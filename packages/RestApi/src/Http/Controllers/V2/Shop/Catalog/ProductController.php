<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\RestApi\Http\Resources\V2\Shop\Catalog\ProductResource;
use Webkul\RestApi\Http\Resources\V2\Shop\Catalog\ProductReviewResource;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;






class ProductController extends CatalogController
{
        /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(protected ProductRepository $productRepository) {}

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
        return ProductRepository::class;
    }

    /**
     * Resource class name.
     */
    public function resource(): string
    {
        return ProductResource::class;
    }

    /**
     * Returns a listing of the resource.
     */
    // public function allResources(Request $request)
    // {
    //     if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
    //         $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
    //     }

    //     $products = $this->getRepositoryInstance()
    //         ->setSearchEngine($searchEngine ?? 'database')
    //         ->getAll(array_merge(request()->query(), [
    //             'channel_id'           => core()->getCurrentChannel()->id,
    //             'status'               => 1,
    //             'visible_individually' => 1,
    //         ]));

    //     return $this->getResourceCollection($products);
    // }


public function allResources(Request $request)
{
    //-----------------------------------------
    // Detect search engine
    //-----------------------------------------
    $searchEngine = 'database'; // default to avoid undefined variable

    if (core()->getConfigData('catalog.products.search.engine') === 'elastic') {
        $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
    }

    //-----------------------------------------
    // Fetch paginated products (for UI list)
    //-----------------------------------------
    $queryParams = array_merge($request->query(), [
        'channel_id'           => core()->getCurrentChannel()->id,
        'status'               => 1,
        'visible_individually' => 1,
    ]);

    $products = $this->getRepositoryInstance()
        ->setSearchEngine($searchEngine)
        ->getAll($queryParams);

    //-----------------------------------------
    // BRAND EXTRACTION — CACHED
    //-----------------------------------------

    $brands = Cache::remember(
        'brands:' . md5(json_encode($queryParams)),
        now()->addMinutes(30), // adjust TTL as needed
        function () use ($queryParams, $searchEngine) {

            $brandAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
                ->findOneByField('code', 'brand');

            if (!$brandAttr) {
                return collect();
            }

            // Fetch ALL products that match the current filters
            $allFilteredProducts = $this->getRepositoryInstance()
                ->setSearchEngine($searchEngine)
                ->getAll(array_merge($queryParams, ['pagination' => 0]));

            $allIds = collect($allFilteredProducts)->pluck('id')->values();

            if ($allIds->isEmpty()) {
                return collect();
            }

            // Count brands INSIDE this filter
            $brandCounts = \DB::table('product_attribute_values')
                ->select('integer_value as id', \DB::raw('COUNT(*) as product_count'))
                ->where('attribute_id', $brandAttr->id)
                ->whereIn('product_id', $allIds)
                ->whereNotNull('integer_value')
                ->groupBy('integer_value')
                ->pluck('product_count', 'id');

            if ($brandCounts->isEmpty()) {
                return collect();
            }

            // Load brand labels & attach counts
            return \Webkul\Attribute\Models\AttributeOption::whereIn('id', $brandCounts->keys())
                ->get()
                ->map(function ($opt) use ($brandCounts) {
                    return [
                        'id'            => $opt->id,
                        'label'         => $opt->admin_name,
                        'product_count' => (int) $brandCounts[$opt->id],
                    ];
                })
                ->sortBy('label')
                ->values();
        }
    );

    //-----------------------------------------
    // Build response
    //-----------------------------------------
    $response = [
        'data'   => ProductResource::collection($products),
        'brands' => $brands,
    ];

    //-----------------------------------------
    // Add pagination links/meta
    //-----------------------------------------
    if ($products instanceof \Illuminate\Pagination\AbstractPaginator) {
        $arr = $products->toArray();

        if (isset($arr['links'])) {
            $response['links'] = $arr['links'];
        }

        if (isset($arr['meta'])) {
            $response['meta'] = $arr['meta'];
        }
    }

    return response()->json($response);
}




    /**
     * Returns product's additional information.
     */
    public function additionalInformation(Request $request, int $id): Response
    {
        $resource = $this->getRepositoryInstance()->findOrFail($id);

        $additionalInformation = app(\Webkul\Product\Helpers\View::class)
            ->getAdditionalData($resource);

        return response([
            'data' => $additionalInformation,
        ]);
    }

    /**
     * Returns product's additional information.
     */
    public function configurableConfig(Request $request, int $id): Response
    {
        $resource = $this->getRepositoryInstance()->findOrFail($id);

        $configurableConfig = app(\Webkul\Product\Helpers\ConfigurableOption::class)
            ->getConfigurationConfig($resource);

        return response([
            'data' => $configurableConfig,
        ]);
    }

    /**
     * Get the reviews of a product.
     */
    public function reviews(int $id): \Illuminate\Http\Response
    {
        $resource = $this->getRepositoryInstance()->findOrFail($id);

        $reviews = $resource->reviews()
            ->where('status', 'approved')
            ->paginate(request()->input('limit') ?? 10);

        return response([
            'data' => ProductReviewResource::collection($reviews),
        ]);
    }


    /**
     * Related product listings.
     *
     * @param  int  $id
     */
    public function relatedProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $relatedProducts = $product->related_products()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_related_products'))
            ->get();

        return ProductResource::collection($relatedProducts);
    }

    /**
     * Up-sell product listings.
     *
     * @param  int  $id
     */
    public function upSellProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $upSellProducts = $product->up_sells()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_up_sells_products'))
            ->get();

        return ProductResource::collection($upSellProducts);
    }


    /**
     * Breadcrumb trail for a product by its slug.
     * Returns ONLY the deepest trail (no Root by default).
     */
    public function breadcrumbsByProductSlug(Request $request, string $slug): \Illuminate\Http\Response
    {
        // Match search engine selection used elsewhere
        if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
            $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
        }

        $repo = $this->getRepositoryInstance()->setSearchEngine($searchEngine ?? 'database');

        // Uses url_key (or Elastic) under the hood; avoids products.slug
        $product = $repo->findBySlugOrFail($slug);

        $includeRoot = $request->boolean('include_root', false);

        // Build a trail for each assigned category
        $categories = $product->categories()->get();
        $trails = $categories->map(fn($c) => $this->buildCategoryTrail($c))->values()->all();

        // Pick the deepest trail only
        $deepest = $this->pickDeepestTrail($trails);

        // Optionally remove Root (default: omit)
        $deepest = $this->maybeOmitRoot($deepest, $includeRoot);

        return response([
            'data' => [
                'product'     => [
                    'id'   => (int) $product->id,
                    'name' => (string) $product->name,
                    'slug' => (string) ($product->slug ?? $product->url_key),
                ],
                // Single flattened breadcrumb trail
                'breadcrumbs' => $deepest,
            ],
        ]);
    }

    /**
     * Choose the longest breadcrumb trail from a list.
     */
    protected function pickDeepestTrail(array $trails): array
    {
        if (empty($trails)) {
            return [];
        }

        usort($trails, fn ($a, $b) => count($b) <=> count($a));

        // If multiple are tied in depth, the first after sorting wins.
        return $trails[0];
    }

    /**
     * Build a breadcrumb trail array from a Category model up to root.
     */
    protected function buildCategoryTrail($category): array
    {
        if (method_exists($category, 'ancestors')) {
            $nodes = $category->ancestors()->defaultOrder()->get()->push($category);

            return $nodes->map(fn ($node) => [
                'id'   => (int) $node->id,
                'name' => (string) $node->name,
                'slug' => (string) $node->slug,
            ])->values()->all();
        }

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
