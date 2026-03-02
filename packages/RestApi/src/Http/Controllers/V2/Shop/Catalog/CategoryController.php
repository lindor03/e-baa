<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\RestApi\Http\Resources\V2\Shop\Catalog\CategoryResource;

class CategoryController extends CatalogController
{
    /**
     * Is resource authorized.
     */
    public function isAuthorized(): bool
    {
        return false;
    }

    /**
     * Repository class name.
     */
    public function repository(): string
    {
        return CategoryRepository::class;
    }

    /**
     * Resource class name.
     */
    public function resource(): string
    {
        return CategoryResource::class;
    }

    /**
     * Returns a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function descendantCategories(Request $request)
    {
        $results = $this->getRepositoryInstance()->getVisibleCategoryTree($request->input('parent_id'));

        return $this->getResourceCollection($results);
    }


public function breadcrumbsBySlug(Request $request, string $slug): \Illuminate\Http\Response
{
    // Uses translations-aware lookup; avoids querying categories.slug
    $category = $this->getRepositoryInstance()->findBySlugOrFail($slug);

    $includeRoot = $request->boolean('include_root', false);

    $trail = $this->buildCategoryTrail($category);
    $trail = $this->maybeOmitRoot($trail, $includeRoot);

    return response([
        'data' => $trail,
    ]);
}

    /**
     * Prefer ancestors() if you’re on nested sets (kalnoy/nestedset).
     * Falls back to parent walking if relation isn’t available.
     */
    protected function buildCategoryTrail($category): array
    {
        if (method_exists($category, 'ancestors')) {
            // One query for ancestors, then append self
            $nodes = $category->ancestors()->defaultOrder()->get()->push($category);

            return $nodes->map(fn ($node) => [
                'id'   => (int) $node->id,
                'name' => (string) $node->name,
                'slug' => (string) $node->slug, // resolved from translation accessor
            ])->values()->all();
        }

        // Fallback: parent chain (may do a few queries, but trees are shallow)
        $trail   = [];
        $current = $category;

        while ($current) {
            array_unshift($trail, [
                'id'   => (int) $current->id,
                'name' => (string) $current->name,
                'slug' => (string) $current->slug,
            ]);

            $current = $current->parent ?? null;
        }

        return $trail;
    }

    /**
     * Remove the Root node unless explicitly requested.
     */
    protected function maybeOmitRoot(array $trail, bool $includeRoot): array
    {
        if (! $includeRoot && $trail && isset($trail[0]['slug']) && strcasecmp($trail[0]['slug'], 'root') === 0) {
            array_shift($trail);
        }

        return $trail;
    }
}
