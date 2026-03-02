<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Cms;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\CMS\Repositories\PageRepository;
use Webkul\RestApi\Http\Controllers\V2\Shop\ShopController;
use Webkul\RestApi\Http\Resources\V2\Shop\Cms\PageResource;

class PageController extends ShopController
{
    public function __construct(
        protected PageRepository $pageRepository
    ) {}

    /**
     * Public CMS
     */
    public function isAuthorized(): bool
    {
        return false;
    }

    /**
     * List CMS pages
     * ?type=footer|header|page|section|system
     */
    public function index(Request $request): Response
    {
        $query = $this->pageRepository
            ->getModel()
            ->newQuery()
            ->active()
            ->ordered()
            ->with([
                'widgets' => fn ($q) =>
                    $q->wherePivot('is_active', 1)
                      ->orderBy('cms_page_widgets.sort_order'),
            ])
            ->whereHas('channels', fn ($q) =>
                $q->where('id', core()->getCurrentChannel()->id)
            );

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response([
            'data' => PageResource::collection($query->get()),
        ]);
    }

    /**
     * Get CMS page by ID
     */
    public function show(int $id): Response
    {
        $page = $this->pageRepository
            ->getModel()
            ->newQuery()
            ->active()
            ->with([
                'widgets' => fn ($q) =>
                    $q->wherePivot('is_active', 1)
                      ->orderBy('cms_page_widgets.sort_order'),
            ])
            ->whereHas('channels', fn ($q) =>
                $q->where('id', core()->getCurrentChannel()->id)
            )
            ->findOrFail($id);

        return response([
            'data' => new PageResource($page),
        ]);
    }

    /**
     * Get CMS page by slug
     */
    public function showBySlug(string $slug): Response
    {
        $page = $this->pageRepository
            ->getModel()
            ->newQuery()
            ->active()
            ->with([
                'widgets' => fn ($q) =>
                    $q->wherePivot('is_active', 1)
                      ->orderBy('cms_page_widgets.sort_order'),
            ])
            ->whereHas('channels', fn ($q) =>
                $q->where('id', core()->getCurrentChannel()->id)
            )
            ->whereTranslation('url_key', $slug)
            ->firstOrFail();

        return response([
            'data' => new PageResource($page),
        ]);
    }
}
