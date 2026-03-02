<?php

namespace Webkul\RestApi\Http\Resources\V2\Shop\Catalog;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Product\Facades\ProductImage;
use Webkul\Product\Helpers\BundleOption;
use Webkul\Checkout\Facades\Cart;
use Webkul\Product\Helpers\Review;
use Webkul\Attribute\Repositories\AttributeRepository;
use Illuminate\Support\Facades\App;

class ProductResource extends JsonResource
{
    protected $reviewHelper;
    protected $attributeRepo;

    public function __construct($resource)
    {
        $this->reviewHelper = app(Review::class);
        $this->attributeRepo = app(AttributeRepository::class);

        parent::__construct($resource);
    }

    public function toArray($request)
    {
        $product = $this->product ?? $this;
        $type    = $product->getTypeInstance();

        // Cache expensive calls once
        $prices       = $type->getProductPrices() ?? [];
        $minimalPrice = $type->getMinimalPrice();
        $totalReviews = $this->reviewHelper->getTotalReviews($product);

        return [
            'id'               => $product->id,
            'promotion_id'     => $product->promotion_id,
            'promotion_name'   => optional($product->promotion)->name,
            'sku'              => $product->sku,
            'type'             => $product->type,
            'name'             => $product->name,
            'url_key'          => $product->url_key,

            // Minimal price
            'price'            => core()->convertPrice($minimalPrice),
            'formatted_price'  => core()->currency($minimalPrice),
            'min_price'        => core()->formatPrice($minimalPrice),

            'prices'           => $prices,

            'short_description'=> $product->short_description,
            'description'      => $product->description,

            'images'           => ProductImageResource::collection($product->images),
            'videos'           => ProductVideoResource::collection($product->videos),
            'base_image'       => ProductImage::getProductBaseImage($product),

            'created_at'       => $product->created_at,
            'updated_at'       => $product->updated_at,

            'attributes'       => $this->getProductAttributes(),

            'reviews' => [
                'total'          => $totalReviews,
                'total_rating'   => $totalReviews ? $this->reviewHelper->getTotalRating($product) : 0,
                'average_rating' => $totalReviews ? $this->reviewHelper->getAverageRating($product) : 0,
                'percentage'     => $totalReviews ? $this->reviewHelper->getPercentageRating($product) : [],
            ],

            'in_stock'              => $product->haveSufficientQuantity(1),
            'is_saved'              => false,
            'is_item_in_cart'       => Cart::getCart()?->items?->contains('product_id', $product->id) ?? false,
            'show_quantity_changer' => $this->when(
                $product->type !== 'grouped',
                $type->showQuantityBox()
            ),

            $this->merge($this->specialPriceInfo($prices)),
            $this->merge($this->allProductExtraInfo($product, $type)),

            $this->mergeWhen($type->isComposite(), [
                'super_attributes' => AttributeResource::collection($product->super_attributes),
            ]),
        ];
    }

    /**
     * Safe special price logic (no undefined keys).
     */
    private function specialPriceInfo(array $prices): array
    {
        $regular = $prices['regular'] ?? null;
        $final   = $prices['final'] ?? null;

        $regularPrice     = $regular['price'] ?? null;
        $regularFormatted = $regular['formatted_price'] ?? null;

        $finalPrice       = $final['price'] ?? $regularPrice;
        $finalFormatted   = $final['formatted_price'] ?? $regularFormatted;

        $hasDiscount = (
            $regularPrice !== null &&
            $finalPrice !== null &&
            $finalPrice < $regularPrice
        );

        return [
            'regular_price'           => $regularPrice,
            'formatted_regular_price' => $regularFormatted,
            'special_price'           => $hasDiscount ? $finalPrice : null,
            'formatted_special_price' => $hasDiscount ? $finalFormatted : null,
            'discount_percentage'     => $hasDiscount
                ? round((($regularPrice - $finalPrice) / $regularPrice) * 100, 2)
                : null,
        ];
    }

    /**
     * Product attributes (clean label output).
     */
    private function getProductAttributes(): array
    {
        $product = $this->product ?? $this;
        $locale  = app()->getLocale();

        $attributes = \Webkul\Attribute\Models\Attribute::query()
            ->where('is_user_defined', 1)
            ->where('is_visible_on_front', 1)
            ->orderBy('position')
            ->get(['id', 'code', 'type', 'value_per_locale']);

        $optionLabels = \Webkul\Attribute\Models\AttributeOption::query()
            ->from('attribute_options as o')
            ->leftJoin('attribute_option_translations as t', function ($join) use ($locale) {
                $join->on('t.attribute_option_id', '=', 'o.id')
                     ->where('t.locale', '=', $locale);
            })
            ->selectRaw('o.id, COALESCE(NULLIF(t.label, ""), o.admin_name) as label')
            ->pluck('label', 'id');

        $data = [];

        foreach ($attributes as $attribute) {
            $code  = $attribute->code;
            $value = $product->{$code};

            if (is_null($value) || $value === '') {
                continue;
            }

            if ($attribute->value_per_locale && is_array($value)) {
                $value = $value[$locale] ?? reset($value);
            }

            if (in_array($attribute->type, ['select', 'multiselect', 'checkbox'])) {
                $optionIds = is_array($value)
                    ? $value
                    : array_filter(explode(',', (string) $value));

                $labels = collect($optionIds)
                    ->map(fn($id) => $optionLabels[$id] ?? null)
                    ->filter()
                    ->values()
                    ->toArray();

                $value = in_array($attribute->type, ['multiselect', 'checkbox'])
                    ? implode(', ', $labels)
                    : ($labels[0] ?? null);
            }

            if (in_array($attribute->type, ['integer', 'decimal'])) {
                $value = (float) $value;
            } elseif ($attribute->type === 'boolean') {
                $value = (bool) $value;
            }

            $data[$code] = $value;
        }

        return $data;
    }

    private function allProductExtraInfo($product, $type)
    {
        return [
            $this->mergeWhen(
                $type instanceof \Webkul\Product\Type\Grouped,
                $this->getGroupedProductInfo($product)
            ),

            $this->mergeWhen(
                $type instanceof \Webkul\Product\Type\Bundle,
                $this->getBundleProductInfo($product)
            ),

            $this->mergeWhen(
                $type instanceof \Webkul\Product\Type\Configurable,
                $this->getConfigurableProductInfo($product)
            ),

            $this->mergeWhen(
                $type instanceof \Webkul\Product\Type\Downloadable,
                $this->getDownloadableProductInfo($product)
            ),
        ];
    }

    private function getGroupedProductInfo($product)
    {
        return [
            'grouped_products' => $product->grouped_products->map(function ($groupedProduct) {
                $associatedProduct = $groupedProduct->associated_product;

                return array_merge($associatedProduct->toArray(), [
                    'qty'                   => $groupedProduct->qty,
                    'isSaleable'            => $associatedProduct->getTypeInstance()->isSaleable(),
                    'formatted_price'       => $associatedProduct->getTypeInstance()->getPriceHtml(),
                    'show_quantity_changer' => $associatedProduct->getTypeInstance()->showQuantityBox(),
                ]);
            }),
        ];
    }

    private function getBundleProductInfo($product)
    {
        return [
            'bundle_options' => app(BundleOption::class)->getBundleConfig($product),
        ];
    }

    private function getConfigurableProductInfo($product)
    {
        return [
            'variants' => $product->variants,
        ];
    }

    private function getDownloadableProductInfo($product)
    {
        return [
            'downloadable_links' => $product->downloadable_links,
            'downloadable_samples' => $product->downloadable_samples,
        ];
    }
}
