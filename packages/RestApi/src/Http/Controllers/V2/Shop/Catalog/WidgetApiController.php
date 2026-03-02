<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Webkul\Widgets\Models\Widget;

// optional helpers (Bagisto)
use Webkul\Product\Facades\ProductImage;

class WidgetApiController extends Controller
{
    /**
     * GET /widgets
     */
    public function all(Request $request)
    {
        $includeInactive = $request->boolean('include_inactive', false);
        $includePayload  = $this->wantsPayload($request);

        $query = Widget::query();

        if (! $includeInactive) {
            $query->where('status', 1);
        }

        $widgets = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->respondWidgets($request, $widgets, $includePayload);
    }

    /**
     * GET /widgets/{id}
     */
    public function get(Request $request, int $id)
    {
        $includePayload = $this->wantsPayload($request);

        $widget = Widget::query()->findOrFail($id);

        return $this->respondWidgets($request, collect([$widget]), $includePayload, true);
    }

    /**
     * GET /widgets/home
     */
    public function homeWidgets(Request $request)
    {
        $includeInactive = $request->boolean('include_inactive', false);
        $includePayload  = $this->wantsPayload($request);

        $query = Widget::query()
            ->where('config->is_home', true);

        if (! $includeInactive) {
            $query->where('status', 1);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $widgets = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->respondWidgets($request, $widgets, $includePayload);
    }

    /**
     * Unified response builder
     */
    protected function respondWidgets(Request $request, $widgets, bool $includePayload, bool $single = false)
    {
        $data = $widgets->map(fn ($w) => $this->transform($w))->values();

        $hashSeed = [
            'route' => $request->path(),
            'type'  => (string) $request->query('type', ''),
            'include_inactive' => $request->boolean('include_inactive'),
            'include_payload'  => $includePayload,
            'widget_hashes'    => $widgets->pluck('content_hash')->all(),
        ];

        $included = null;
        $maxTouchedAt = null;

        if ($includePayload) {
            $cacheKey = 'widgets:payload:' . hash('sha256', json_encode($hashSeed));

            [$included, $maxTouchedAt] = Cache::remember(
                $cacheKey,
                now()->addMinutes(10),
                fn () => $this->hydrateIncluded($widgets)
            );

            if ($maxTouchedAt) {
                $hashSeed['touched_at'] = $maxTouchedAt;
            }
        }

        $etag = '"' . hash('sha256', json_encode($hashSeed)) . '"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        $payload = [
            'data' => $single ? ($data[0] ?? null) : $data,
        ];

        if ($includePayload) {
            $payload['included'] = $included;
        }

        return response()
            ->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=60');
    }

    protected function wantsPayload(Request $request): bool
    {
        return $request->boolean('include_payload')
            || str_contains(strtolower((string) $request->query('include', '')), 'payload');
    }

    /**
     * Hydrate referenced entities + compute max freshness timestamp
     */
    protected function hydrateIncluded($widgets): array
    {
        $locale = app()->getLocale();

        $productIds = $categoryIds = $attributeIds = $optionIds = [];

        foreach ($widgets as $w) {
            $cfg  = is_array($w->config) ? $w->config : [];
            $type = (string) $w->type;

            if ($type === 'featured_products') {
                $productIds = array_merge($productIds, (array) ($cfg['products'] ?? []));
            }

            if ($type === 'products_by_category') {
                $categoryIds = array_merge($categoryIds, (array) ($cfg['category_id'] ?? []));
                foreach ((array) ($cfg['products_by_category'] ?? []) as $cid => $ids) {
                    $categoryIds[] = $cid;
                    $productIds   = array_merge($productIds, (array) $ids);
                }
            }

            if ($type === 'category_list') {
                $categoryIds = array_merge($categoryIds, (array) ($cfg['category_id'] ?? []));
            }

            if ($type === 'products_by_attribute') {
                $attributeIds[] = $cfg['attribute_id'] ?? null;
                $optionIds = array_merge($optionIds, (array) ($cfg['attribute_option_id'] ?? []));
                foreach ((array) ($cfg['products'] ?? []) as $oid => $ids) {
                    $optionIds[] = $oid;
                    $productIds = array_merge($productIds, (array) $ids);
                }
            }

            if ($type === 'attribute_options_list') {
                $attributeIds[] = $cfg['attribute_id'] ?? null;
                $optionIds = array_merge($optionIds, (array) ($cfg['attribute_option_id'] ?? []));
            }
        }

        $productIds   = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $categoryIds  = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $attributeIds = array_values(array_unique(array_filter(array_map('intval', $attributeIds))));
        $optionIds    = array_values(array_unique(array_filter(array_map('intval', $optionIds))));

        /* ---------------- Products ---------------- */
        $productsOut = [];
        $productsTouchedAt = null;

        if ($productIds) {
            $products = \Webkul\Product\Models\Product::query()
                ->with(['images', 'price_indices'])
                ->whereIn('id', $productIds)
                ->get();

            $productsTouchedAt = $products->max('updated_at')?->toISOString();

            foreach ($products as $p) {
                $min = $p->getTypeInstance()?->getMinimalPrice();

                $productTypeInstance = $p->getTypeInstance();
                $productPrices = $productTypeInstance->getProductPrices();
                $hasDiscount = $productTypeInstance->haveDiscount();
                $finalPrice = data_get($productPrices, 'final.price');

                $productsOut[] = [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'type' => $p->type,
                    'name' => $p->name,
                    'url_key' => $p->url_key,
                    'price' => $min ? core()->convertPrice($min) : null,
                    'formatted_price' => $min ? core()->currency($min) : null,
                    'regular_price' => $productPrices['regular']['price'],
                    'formatted_regular_price' => $productPrices['regular']['formatted_price'],
                    'special_price' => ($hasDiscount && $finalPrice !== null) ? core()->convertPrice($finalPrice): null,
                    'formatted_special_price' => ($hasDiscount && $finalPrice !== null) ? core()->currency($finalPrice): null,
                    'base_image' => ProductImage::getProductBaseImage($p),
                    'in_stock'   => $p->haveSufficientQuantity(1),

                    'updated_at' => $p->updated_at?->toISOString(),
                ];
            }
        }

        /* ---------------- Categories ---------------- */
        $categoriesOut = [];
        $categoriesTouchedAt = null;

        if ($categoryIds) {
            $rows = \Webkul\Category\Models\CategoryTranslation::query()
                ->from('category_translations as ct')
                ->join('categories as c', 'c.id', '=', 'ct.category_id')
                ->whereIn('ct.category_id', $categoryIds)
                ->where('ct.locale', $locale)
                ->get([
                    'ct.category_id',
                    'ct.name',
                    'ct.slug',
                    'c.updated_at',
                ]);

            $categoriesTouchedAt = $rows->max('updated_at');

            $map = $rows->keyBy('category_id');

            foreach ($categoryIds as $cid) {
                $t = $map->get($cid);

                $categoriesOut[] = [
                    'id' => (int) $cid,
                    'name' => $t->name ?? "Category {$cid}",
                    'slug' => $t->slug ?? '',
                    'updated_at' => $t->updated_at ?? null,
                ];
            }
        }

        /* ---------------- Attributes ---------------- */
        $attributesOut = [];
        $attributesTouchedAt = null;

        if ($attributeIds) {
            $attrs = \Webkul\Attribute\Models\Attribute::whereIn('id', $attributeIds)->get();
            $attributesTouchedAt = $attrs->max('updated_at')?->toISOString();

            foreach ($attrs as $a) {
                $attributesOut[] = [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name ?? $a->admin_name,
                    'type' => $a->type,
                    'updated_at' => $a->updated_at?->toISOString(),
                ];
            }
        }

        /* ---------------- Attribute Options ---------------- */
        /* ---------------- Attribute Options ---------------- */
        $optionsOut = [];
        $optionsTouchedAt = null;

        if ($optionIds) {
            $opts = \Webkul\Attribute\Models\AttributeOption::query()
                ->from('attribute_options as o')
                ->join('attributes as a', 'a.id', '=', 'o.attribute_id')
                ->leftJoin('attribute_option_translations as t', function ($join) use ($locale) {
                    $join->on('t.attribute_option_id', '=', 'o.id')
                        ->where('t.locale', $locale);
                })
                ->whereIn('o.id', $optionIds)
                ->get([
                    'o.id',
                    'o.attribute_id',
                    'a.updated_at as attribute_updated_at',
                    \DB::raw('COALESCE(NULLIF(t.label, ""), o.admin_name) as label'),
                ]);

            $optionsTouchedAt = $opts->max('attribute_updated_at');

            foreach ($opts as $o) {
                $optionsOut[] = [
                    'id' => (int) $o->id,
                    'attribute_id' => (int) $o->attribute_id,
                    'label' => (string) $o->label,
                    'updated_at' => $o->attribute_updated_at,
                ];
            }
        }


        $maxTouchedAt = collect([
            $productsTouchedAt,
            $categoriesTouchedAt,
            $attributesTouchedAt,
            $optionsTouchedAt,
        ])->filter()->max();

        return [[
            'products' => $productsOut,
            'categories' => $categoriesOut,
            'attributes' => $attributesOut,
            'attribute_options' => $optionsOut,
        ], $maxTouchedAt];
    }

    /**
     * Normalize widget
     */
    protected function transform(Widget $widget): array
    {
        $config = is_array($widget->config) ? $widget->config : [];

        $images = array_map(function ($path) use ($config) {
            return [
                'path' => $path,
                'url'  => Storage::disk('public')->url($path),
                'link' => $config['images_links'][$path] ?? null,
            ];
        }, (array) ($config['images'] ?? []));

        unset($config['images_links']);

        return [
            'id' => $widget->id,
            'type' => $widget->type,
            'title' => $widget->title,
            'description' => $widget->description,
            'sort_order' => $widget->sort_order,
            'status' => (bool) $widget->status,
            'is_home' => (bool) ($config['is_home'] ?? false),
            'layout' => $config['layout'] ?? '1x1',
            'content_hash' => $widget->content_hash,
            'cache_key' => "widget:{$widget->id}:{$widget->content_hash}",
            'config' => array_merge($config, ['images' => $images]),
        ];
    }
}
