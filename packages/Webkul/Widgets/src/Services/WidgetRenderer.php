<?php

namespace Webkul\Widgets\Services;

use Webkul\Product\Repositories\ProductRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Widgets\Models\Widget;

class WidgetRenderer
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected CategoryRepository $categoryRepository,
    ) {}

    /**
     * Resolve widget into ready-to-render payload.
     */
    public function resolve(Widget $widget): array
    {
        $config = $widget->config ?? [];

        switch ($widget->type) {
            case 'featured_products':
                $ids = $this->explodeIds($config['product_ids'] ?? []);
                $products = $this->productRepository->whereIn('id', $ids)->get();

                return [
                    'type'     => 'featured_products',
                    'title'    => $widget->title,
                    'products' => $products,
                ];

            case 'products_by_attribute':
                $attributeCode = $config['attribute_code'] ?? null;
                $optionIds     = $this->explodeIds($config['attribute_option_ids'] ?? []);

                $products = $this->productRepository->scopeQuery(function ($query) use ($attributeCode, $optionIds) {
                    if (! $attributeCode || empty($optionIds)) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->join('product_attribute_values as pav', 'products.id', '=', 'pav.product_id')
                        ->join('attributes as a', 'pav.attribute_id', '=', 'a.id')
                        ->where('a.code', $attributeCode)
                        ->whereIn('pav.integer_value', $optionIds)
                        ->select('products.*')
                        ->distinct();
                })->all();

                return [
                    'type'     => 'products_by_attribute',
                    'title'    => $widget->title,
                    'products' => $products,
                ];

            case 'products_by_category':
                $catIds = $this->explodeIds($config['category_ids'] ?? []);
                $products = $this->productRepository->scopeQuery(function ($query) use ($catIds) {
                    if (empty($catIds)) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->join('product_categories as pc', 'products.id', '=', 'pc.product_id')
                        ->whereIn('pc.category_id', $catIds)
                        ->select('products.*')
                        ->distinct();
                })->all();

                return [
                    'type'     => 'products_by_category',
                    'title'    => $widget->title,
                    'products' => $products,
                ];

            case 'category_list':
                $catIds = $this->explodeIds($config['category_ids'] ?? []);
                $categories = $this->categoryRepository->whereIn('id', $catIds)->get();

                return [
                    'type'       => 'category_list',
                    'title'      => $widget->title,
                    'categories' => $categories,
                ];

            case 'carousel':
                $items = $config['items'] ?? [];

                // If textarea was filled with JSON string
                if (is_string($items)) {
                    $decoded = json_decode($items, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $items = $decoded;
                    }
                }

                return [
                    'type'  => 'carousel',
                    'title' => $widget->title,
                    'items' => $items,
                ];

            case 'video':
                return [
                    'type'      => 'video',
                    'title'     => $widget->title,
                    'video_url' => $config['video_url'] ?? null,
                ];

            case 'html':
                return [
                    'type'  => 'html',
                    'title' => $widget->title,
                    'html'  => $config['html'] ?? '',
                ];

            default:
                return [
                    'type'  => $widget->type,
                    'title' => $widget->title,
                    'config'=> $config,
                ];
        }
    }

    protected function explodeIds($value): array
    {
        if (is_array($value)) {
            return array_filter(array_map('intval', $value));
        }

        return array_filter(array_map('intval', explode(',', (string) $value)));
    }
}
