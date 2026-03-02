<?php

namespace Webkul\Widgets\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Widgets\Repositories\WidgetRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;


class WidgetController extends Controller
{
    public function __construct(
        protected WidgetRepository $widgetRepository,
        protected ProductRepository $productRepository,
        protected CategoryRepository $categoryRepository,
        protected AttributeRepository $attributeRepository,
    ) {}

    // public function index()
    // {
    //     $widgets = $this->widgetRepository->all();

    //     return view('widgets::admin.index', compact('widgets'));
    // }
    public function index()
    {
        $widgets = $this->widgetRepository
            ->orderBy('sort_order')
            ->get();

        return view('widgets::admin.index', compact('widgets'));
    }



    public function create()
    {
        return view('widgets::admin.create', [
            'widget'     => new \Webkul\Widgets\Models\Widget,
            'types'      => $this->getTypes(),
            'layouts'    => $this->getLayouts(),
            'attributes' => $this->attributeRepository->all(),
            'categories' => $this->categoryRepository->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'        => 'required|string',
            'title'       => 'nullable|string',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer',
            'status'      => 'nullable|boolean',
            'config'      => 'nullable|array',
            'images.*'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['status'] = $request->boolean('status');

        /* Build config safely */
        $data['config'] = $this->buildWidgetConfig($request);

        $this->widgetRepository->create($data);

        session()->flash('success', 'Widget created successfully.');

        return redirect()->route('admin.widgets.index');
    }


    public function edit(int $id)
    {
        $widget = $this->widgetRepository->findOrFail($id);

        return view('widgets::admin.edit', [
            'widget'     => $widget,
            'types'      => $this->getTypes(),
            'layouts'    => $this->getLayouts(),
            'attributes' => $this->attributeRepository->all(),
            'categories' => $this->categoryRepository->all(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $widget = $this->widgetRepository->findOrFail($id);

        $data = $request->validate([
            'type'        => 'required|string',
            'title'       => 'nullable|string',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer',
            'status'      => 'nullable|boolean',
            'config'      => 'nullable|array',
            'images.*'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['status'] = $request->boolean('status');

        /* Merge with existing config */
        $data['config'] = $this->buildWidgetConfig(
            $request,
            $widget->config ?? []
        );

        $this->widgetRepository->update($data, $id);

        session()->flash('success', 'Widget updated successfully.');

        return redirect()->route('admin.widgets.index');
    }


    public function destroy(int $id)
    {
        $widget = $this->widgetRepository->findOrFail($id);

        if (! empty($widget->config['images'])) {
            foreach ($widget->config['images'] as $img) {
                $path = storage_path('app/public/' . $img);
                if ($img && file_exists($path)) {
                    @unlink($path);
                }
            }
        }

        $this->widgetRepository->delete($id);

        session()->flash('success', 'Widget deleted successfully.');

        return redirect()->route('admin.widgets.index');
    }


    /** PROMOTIONS SEARCH */
    public function searchPromotions()
    {
        $q = trim((string) request('q'));

        $query = DB::table('custom_promotions');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'LIKE', "%{$q}%")
                ->orWhere('slug', 'LIKE', "%{$q}%")
                ->orWhere('erp_code', 'LIKE', "%{$q}%");
            });
        }

        return $query
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(30)
            ->get([
                'id',
                'name',
                'slug',
                'erp_code',
                'is_active',
                'from',
                'to',
                'banner_path',
                'logo_path',
            ]);
    }

    /** Get single promotion (useful for edit prefill / details) */
    public function getPromotion(int $id)
    {
        $p = DB::table('custom_promotions')->where('id', $id)->first();

        if (! $p) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($p);
    }


    /**
     * Used by AJAX to render correct partial.
     */
    public function renderForm(string $type)
    {
        if (! view()->exists("widgets::admin.forms.$type")) {
            abort(404, "Unknown widget type: $type");
        }

        // New empty widget for fresh form when switching type
        $widget = new \Webkul\Widgets\Models\Widget;

        return view("widgets::admin.forms.$type", [
            'widget'     => $widget,
            'types'      => $this->getTypes(),
            'attributes' => $this->attributeRepository->all(),
            'categories' => $this->categoryRepository->all(),
        ])->render();
    }

    protected function normalizeNestedProducts(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $products) {
            $normalized[$key] = array_map('intval', (array) $products);
        }

        return $normalized;
    }

    protected function getTypes(): array
    {
        return [
            'featured_products'     => 'Featured Products (manual selection)',
            'products_by_attribute' => 'Products by Attribute',
            'products_by_category'  => 'Products by Category',
            'category_list'         => 'Category List',
            'attribute_options_list'   => 'Attribute Options List',
            'carousel'              => 'Image Carousel',
            'video'                 => 'Video Block',
            'html'                  => 'Custom HTML',
            'promotions'               => 'Promotions (simple)',
            'promotions_products'      => 'Single Promotion with Products',
        ];
    }

    protected function getLayouts(): array
    {
        return [
            '1x1' => '1 × 1 (single)',
            '1x4' => '1 × 4',
            '1x6' => '1 × 6',
            '2x3' => '2 × 3',
            '3x3' => '3 × 3',
            '1x6_tabbed' => '1 × 6 (tabbed)',
            'carousel' => 'Carousel',
        ];
    }


    /** PRODUCT SEARCH (with attribute/category filters) */
    public function searchProducts()
    {
        $q          = request('q');
        $optionId   = request('optionId');   // attribute option id
        $categoryId = request('categoryId'); // category id

        $query = \Webkul\Product\Models\ProductFlat::query();

        if ($q) {
            $query->where('name', 'LIKE', '%' . $q . '%');
        }

        if ($optionId) {
            $query->whereIn('product_id', function ($sub) use ($optionId) {
                $sub->select('product_id')
                    ->from('product_attribute_values')
                    ->where('integer_value', $optionId);
            });
        }

        if ($categoryId) {
            $query->whereIn('product_id', function ($sub) use ($categoryId) {
                $sub->select('product_id')
                    ->from('product_categories')
                    ->where('category_id', $categoryId);
            });
        }

        return $query
            ->select('product_id as id', 'name', 'sku')
            ->limit(30)
            ->get();
    }

    public function getProductsByAttributeOption($optionId)
    {
        return \Webkul\Product\Models\ProductFlat::query()
            ->whereIn('product_id', function ($sub) use ($optionId) {
                $sub->select('product_id')
                    ->from('product_attribute_values')
                    ->where('integer_value', $optionId);
            })
            ->select('product_id as id', 'name', 'sku')
            ->get();
    }

    public function getProductsByCategory($categoryId)
    {
        return \Webkul\Product\Models\ProductFlat::query()
            ->whereIn('product_id', function ($sub) use ($categoryId) {
                $sub->select('product_id')
                    ->from('product_categories')
                    ->where('category_id', $categoryId);
            })
            ->select('product_id as id', 'name', 'sku')
            ->get();
    }

    public function searchCategories()
    {
        return \Webkul\Category\Models\CategoryTranslation::query()
            ->where('name', 'LIKE', '%' . request('q') . '%')
            ->where('locale', app()->getLocale())
            ->select('category_id as id', 'name')
            ->limit(20)
            ->get();
    }

    public function getAttributes()
    {
        return \Webkul\Attribute\Models\Attribute::query()
            ->where('type', 'select')
            ->select('id', 'code', 'admin_name')
            ->get();
    }

    public function getAttributeOptions($attributeId)
    {
        return \Webkul\Attribute\Models\AttributeOption::query()
            ->where('attribute_id', $attributeId)
            ->select('id', 'admin_name')
            ->get();
    }

    protected function normalizeFlatProducts($input): array
    {
        return array_values(
            array_unique(
                array_map('intval', Arr::flatten((array) $input))
            )
        );
    }





    protected function buildWidgetConfig(Request $request, ?array $existingConfig = []): array
    {
        $config = $request->input('config', []);
        $type   = (string) $request->input('type');

        /* ------------------------------------------------------------
        | Normalize selector IDs
        * ------------------------------------------------------------ */

        if (isset($config['attribute_id'])) {
            $config['attribute_id'] = (int) $config['attribute_id'];
        }

        if (isset($config['attribute_option_id'])) {
            $config['attribute_option_id'] = array_values(array_unique(array_map(
                'intval',
                (array) $config['attribute_option_id']
            )));
        }

        // Used by category_list + products_by_category (and any future category selectors)
        if (isset($config['category_id'])) {
            $config['category_id'] = array_values(array_unique(array_map(
                'intval',
                (array) $config['category_id']
            )));
        }

        /* ------------------------------------------------------------
        | Layout (Option A): whitelist + default + preserve existing
        * ------------------------------------------------------------ */

        $allowedLayouts = array_keys($this->getLayouts());
        $layout = (string) ($config['layout'] ?? ($existingConfig['layout'] ?? '1x1'));
        $config['layout'] = in_array($layout, $allowedLayouts, true) ? $layout : '1x1';

        /* ------------------------------------------------------------
        | Products normalization (by widget type)
        * ------------------------------------------------------------ */


        /* ------------------------------------------------------------
        | Promotions widgets normalization
        * ------------------------------------------------------------ */
        if ($type === 'promotions') {
            // Optional: admin can pick promotions, or leave empty to auto-pick active in API
            if (isset($config['promotion_id'])) {
                $config['promotion_id'] = array_values(array_unique(array_map(
                    'intval',
                    (array) $config['promotion_id']
                )));
            } else {
                $config['promotion_id'] = [];
            }

            $config['active_only'] = $request->boolean('config.active_only');
            $config['limit'] = max(1, (int) ($config['limit'] ?? 10));
        }

        if ($type === 'promotions_products') {
            $config['promotion_id'] = isset($config['promotion_id'])
                ? (int) $config['promotion_id']
                : (int) ($existingConfig['promotion_id'] ?? 0);

            $config['limit'] = max(1, (int) ($config['limit'] ?? 24));

            // optional flags
            $config['show_banner'] = $request->boolean('config.show_banner');
            $config['show_logo']   = $request->boolean('config.show_logo');
        }


        if ($type === 'featured_products') {
            $config['products'] = $this->normalizeFlatProducts($config['products'] ?? []);
        }

        if ($type === 'products_by_attribute') {
            $pb = $this->normalizeNestedProducts($config['products'] ?? []);

            // Reorder option blocks by attribute_option_id (if provided)
            $optOrder = array_values(array_unique(array_map('intval', (array) ($config['attribute_option_id'] ?? []))));
            if (!empty($optOrder)) {
                $reordered = [];

                foreach ($optOrder as $oid) {
                    if (array_key_exists($oid, $pb)) {
                        $reordered[$oid] = $pb[$oid];
                    }
                }

                // Append any remaining options that have products but weren't in attribute_option_id
                foreach ($pb as $oid => $pids) {
                    if (!array_key_exists($oid, $reordered)) {
                        $reordered[$oid] = $pids;
                    }
                }

                $pb = $reordered;

                // Keep attribute_option_id aligned (optional but keeps config tidy)
                $config['attribute_option_id'] = array_values(array_unique(array_merge($optOrder, array_keys($pb))));
            }

            $config['products'] = $pb;
        }

        if ($type === 'products_by_category') {
            $pb = $this->normalizeNestedProducts($config['products_by_category'] ?? []);

            // Reorder category blocks by category_id (canonical UI order)
            $catOrder = array_values(array_unique(array_map('intval', (array) ($config['category_id'] ?? []))));
            if (!empty($catOrder)) {
                $reordered = [];

                foreach ($catOrder as $cid) {
                    if (array_key_exists($cid, $pb)) {
                        $reordered[$cid] = $pb[$cid];
                    }
                }

                // Append any remaining categories that have products but weren't in category_id
                foreach ($pb as $cid => $pids) {
                    if (!array_key_exists($cid, $reordered)) {
                        $reordered[$cid] = $pids;
                    }
                }

                $pb = $reordered;

                // Keep category_id aligned with final keys (important for edit order)
                $config['category_id'] = array_values(array_unique(array_merge($catOrder, array_keys($pb))));
            }

            $config['products_by_category'] = $pb;
        }

        /* ------------------------------------------------------------
        | Video widget normalization
        * ------------------------------------------------------------ */

        if ($type === 'video') {
            if (isset($config['video_url'])) {
                $config['video_url'] = trim((string) $config['video_url']);
            }

            $config['autoplay'] = $request->boolean('config.autoplay');
            $config['muted']    = $request->boolean('config.muted');
            $config['loop']     = $request->boolean('config.loop');
        }





        /* ------------------------------------------------------------
        | HTML widget normalization
        * ------------------------------------------------------------ */

        if ($type === 'html') {
            $config['html'] = isset($config['html']) ? (string) $config['html'] : '';
            $config['css']  = isset($config['css'])  ? (string) $config['css']  : '';
            $config['js']   = isset($config['js'])   ? (string) $config['js']   : '';

            $config['enable_preview'] = $request->boolean('config.enable_preview');
        }


        /* ------------------------------------------------------------
        | Images: merge-safe + supports carousel reorder & removal
        * ------------------------------------------------------------ */

        // Start with existing images
        $images = $existingConfig['images'] ?? [];

        // Apply reorder (only for images already saved)
        $order = array_values((array) ($config['images_order'] ?? []));
        if (!empty($order)) {
            $ordered = array_values(array_filter($order, fn ($p) => in_array($p, $images, true)));
            $rest    = array_values(array_diff($images, $ordered));
            $images  = array_merge($ordered, $rest);
        }

        // Apply removals + delete files
        $remove = array_values(array_unique((array) ($config['remove_images'] ?? [])));
        if (!empty($remove)) {
            foreach ($remove as $img) {
                $path = storage_path('app/public/' . $img);
                if ($img && file_exists($path)) {
                    @unlink($path);
                }
            }

            $images = array_values(array_diff($images, $remove));
        }

        // Base images after reorder/removal
        $config['images'] = $images;

        // Append new uploads (keeps user-selected order)
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $config['images'][] = $file->store('widgets', 'public');
            }
        }

        // Always de-duplicate
        $config['images'] = array_values(array_unique($config['images'] ?? []));

        /* Normalize images_links (optional URL per image) */
        $links = (array) ($config['images_links'] ?? ($existingConfig['images_links'] ?? []));

        // Keep only links for images that still exist
        $links = array_intersect_key($links, array_flip($config['images']));

        foreach ($links as $img => $url) {
            $url = trim((string) $url);

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                unset($links[$img]);
                continue;
            }

            $links[$img] = $url;
        }

        $config['images_links'] = $links;

        // Clean helper keys (don’t persist them in config JSON)
        unset($config['remove_images'], $config['images_order']);

        /* Flags */
        $config['is_home']     = $request->boolean('config.is_home');
        $config['is_carousel'] = $request->boolean('config.is_carousel');
        $config['show_title']       = $request->boolean('config.show_title');
        $config['show_description'] = $request->boolean('config.show_description');

        return $config;
    }


    public function reorder(Request $request)
    {
        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($request->order as $index => $id) {
            $this->widgetRepository->update([
                'sort_order' => $index + 1,
            ], $id);
        }

        return response()->json(['success' => true]);
    }



}
