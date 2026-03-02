<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Enums\AttributeTypeEnum;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;

class FacetController extends CatalogController
{
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected CategoryRepository $categoryRepository
    ) {}

    public function isAuthorized(): bool
    {
        return false;
    }

    /**
     * GET /api/v2/filters
     *
     * Query params:
     *  - category: category slug (optional)
     *  - attributes[code][]=optionId (repeatable)
     *
     * Example:
     *  /filters?category=b-makineria&attributes[color][]=12&attributes[size][]=34&attributes[size][]=35
     */
    public function available(Request $request)
    {
        $categorySlug   = $request->query('category');                 // optional
        $selectedByCode = (array) $request->query('attributes', []);   // e.g. ['color' => [12], 'size' => [34,35]]

        $channel = core()->getRequestedChannelCode();
        $locale  = core()->getRequestedLocaleCode();

        // Resolve category (and descendants) if present
        $category = null;
        $categoryIds = [];

        if ($categorySlug) {
            $category = $this->categoryRepository->findBySlugOrFail($categorySlug);
            $categoryIds = $this->getDescendantAndSelfIds($category);
        }

        // Base query over product_flat (fast filterable surface)
        $base = DB::table('product_flat as pf')
            ->select('pf.product_id')
            ->where('pf.status', 1)
            ->where('pf.visible_individually', 1)
            ->where('pf.channel', $channel)
            ->where('pf.locale', $locale);

        if ($categoryIds) {
            $base->join('product_categories as pc', 'pc.product_id', '=', 'pf.product_id')
                 ->whereIn('pc.category_id', $categoryIds);
        }

        // Load all filterable attributes once
        $filterable = $this->attributeRepository->getFilterableAttributes();
        $attributesByCode = $filterable->keyBy('code');

        // Available categories (children or roots), after applying current attribute selections
        $categoriesFacet = $this->buildAvailableCategoriesFacet(
            baseQuery: clone $base,
            selectedByCode: $selectedByCode,
            attributesByCode: $attributesByCode,
            currentCategory: $category
        );

        // Available attributes/options after current selections
        $attributesFacet = $this->buildAvailableAttributesFacet(
            baseQuery: clone $base,
            selectedByCode: $selectedByCode,
            attributesByCode: $attributesByCode
        );

        return response([
            'data' => [
                'categories' => $categoriesFacet, // [{id,name,slug}]
                'attributes' => $attributesFacet, // [{code,name,type,options:[{id,label}]}]
            ],
        ]);
    }

    /**
     * Return IDs for descendants and self across nestedset versions.
     */
    protected function getDescendantAndSelfIds($category): array
    {
        $modelClass = get_class($category);

        // Static scope requires an ID argument in kalnoy/nestedset
        if (method_exists($modelClass, 'descendantsAndSelf')) {
            return $modelClass::descendantsAndSelf($category->id)
                ->pluck('id')
                ->unique()
                ->values()
                ->all();
        }

        // Fallback to relation
        if (method_exists($category, 'descendants')) {
            return $category->descendants()
                ->pluck('id')
                ->push($category->id)
                ->unique()
                ->values()
                ->all();
        }

        return [(int) $category->id];
    }

    /**
     * Build available categories facet: immediate children of current category (or roots),
     * filtered by current attribute selections.
     */
    protected function buildAvailableCategoriesFacet($baseQuery, array $selectedByCode, $attributesByCode, $currentCategory = null): array
    {
        // Apply selected attributes to the base
        $this->applyAttributeFilters($baseQuery, $selectedByCode, $attributesByCode);

        // Candidate set = children if category chosen; else roots
        $candidates = $currentCategory
            ? $currentCategory->children // immediate children only
            : $this->categoryRepository->getRootCategories();

        $candidateIds = $candidates->pluck('id')->all();

        if (empty($candidateIds)) {
            return [];
        }

        // Which candidate categories still have products?
        $matchingCategoryIds = DB::table('product_categories as pc')
            ->joinSub($baseQuery, 'b', 'b.product_id', '=', 'pc.product_id')
            ->whereIn('pc.category_id', $candidateIds)
            ->groupBy('pc.category_id')
            ->pluck('pc.category_id')
            ->all();

        if (empty($matchingCategoryIds)) {
            return [];
        }

        return $candidates
            ->whereIn('id', $matchingCategoryIds)
            ->map(fn ($cat) => [
                'id'   => (int) $cat->id,
                'name' => (string) $cat->name,
                'slug' => (string) $cat->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * Build available attributes facet: per attribute, list only options that still have matches.
     * Excludes the attribute itself while computing its availability ("non-sticky" facet).
     */
    protected function buildAvailableAttributesFacet($baseQuery, array $selectedByCode, $attributesByCode): array
    {
        $out = [];

        foreach ($attributesByCode as $code => $attribute) {
            $q = clone $baseQuery;

            // Exclude this attribute from current selections when computing its availability
            $this->applyAttributeFilters($q, $selectedByCode, $attributesByCode, $excludeAttributeId = (int) $attribute->id);

            $availableOptionIds = $this->queryAvailableOptionIds($q, $attribute);

            if (empty($availableOptionIds)) {
                continue;
            }

            $options = $attribute->options()
                ->whereIn('id', $availableOptionIds)
                ->get(['id', 'admin_name'])
                ->map(fn ($opt) => [
                    'id'    => (int) $opt->id,
                    'label' => (string) $opt->admin_name,
                ])
                ->values()
                ->all();

            if (empty($options) && $attribute->type === AttributeTypeEnum::BOOLEAN->value) {
                $options = [
                    ['id' => 1, 'label' => 'Yes'],
                    ['id' => 0, 'label' => 'No'],
                ];
            }

            if (empty($options)) {
                continue;
            }

            $out[] = [
                'code'    => (string) $attribute->code,
                'name'    => (string) $attribute->admin_name,
                'type'    => (string) $attribute->type,
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * Apply selected attribute filters to the base query.
     * AND across attributes; OR within the same attribute (multiple selected options).
     */
    protected function applyAttributeFilters($query, array $selectedByCode, $attributesByCode, ?int $excludeAttributeId = null): void
    {
        foreach ($selectedByCode as $code => $optionIds) {
            $optionIds = array_values(array_filter((array) $optionIds, fn ($v) => $v !== '' && $v !== null));

            if (empty($optionIds)) {
                continue;
            }

            $attribute = $attributesByCode[$code] ?? null;

            if (! $attribute || ($excludeAttributeId && (int) $attribute->id === $excludeAttributeId)) {
                continue;
            }

            $type      = $attribute->type;
            $attrId    = (int) $attribute->id;
            $col       = $attribute->column_name; // e.g. integer_value, text_value, boolean_value

            $query->where(function ($q) use ($attrId, $type, $optionIds, $col) {
                foreach ($optionIds as $optId) {
                    $q->orWhereExists(function ($sq) use ($attrId, $type, $optId, $col) {
                        $sq->selectRaw('1')
                           ->from('product_attribute_values as pav')
                           ->whereColumn('pav.product_id', 'pf.product_id')
                           ->where('pav.attribute_id', $attrId)
                           ->where(function ($qq) use ($type, $optId, $col) {
                               if (in_array($type, [AttributeTypeEnum::SELECT->value, AttributeTypeEnum::BOOLEAN->value])) {
                                   $qq->where("pav.$col", (int) $optId);
                               } elseif (in_array($type, [AttributeTypeEnum::MULTISELECT->value, AttributeTypeEnum::CHECKBOX->value])) {
                                   $qq->whereRaw('FIND_IN_SET(?, pav.text_value) > 0', [(int) $optId]);
                               } else {
                                   $qq->where('pav.text_value', (string) $optId);
                               }
                           });
                    });
                }
            });
        }
    }

    /**
     * Determine available option IDs for an attribute given the already-filtered base query.
     */
    protected function queryAvailableOptionIds($filteredBaseQuery, $attribute): array
    {
        $type = $attribute->type;
        $col  = $attribute->column_name; // correct Bagisto column (integer_value, text_value, boolean_value)

        if (in_array($type, [AttributeTypeEnum::SELECT->value, AttributeTypeEnum::BOOLEAN->value])) {
            return DB::table('product_attribute_values as pav')
                ->joinSub($filteredBaseQuery, 'b', 'b.product_id', '=', 'pav.product_id')
                ->where('pav.attribute_id', (int) $attribute->id)
                ->whereNotNull("pav.$col")
                ->groupBy("pav.$col")
                ->pluck("pav.$col")
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }

        if (in_array($type, [AttributeTypeEnum::MULTISELECT->value, AttributeTypeEnum::CHECKBOX->value])) {
            // Multiselect/checkbox options are stored as CSV IDs in text_value
            return DB::table('product_attribute_values as pav')
                ->joinSub($filteredBaseQuery, 'b', 'b.product_id', '=', 'pav.product_id')
                ->join('attribute_options as ao', function ($join) use ($attribute) {
                    $join->on(DB::raw('FIND_IN_SET(ao.id, pav.text_value)'), '>', DB::raw('0'))
                         ->where('pav.attribute_id', (int) $attribute->id);
                })
                ->groupBy('ao.id')
                ->pluck('ao.id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }

        // Fallback for custom/text types
        return DB::table('product_attribute_values as pav')
            ->joinSub($filteredBaseQuery, 'b', 'b.product_id', '=', 'pav.product_id')
            ->where('pav.attribute_id', (int) $attribute->id)
            ->whereNotNull('pav.text_value')
            ->groupBy('pav.text_value')
            ->pluck('pav.text_value')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values()
            ->all();
    }
}
