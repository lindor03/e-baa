<?php

namespace Webkul\Product\Repositories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Eloquent\Repository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Marketing\Repositories\SearchSynonymRepository;
use Webkul\Product\Contracts\Product;
use Illuminate\Support\Facades\Cache;

class ProductRepository extends Repository
{
    /**
     * Search engine.
     */
    protected $searchEngine = 'database';


    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected AttributeRepository $attributeRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository,
        protected ElasticSearchRepository $elasticSearchRepository,
        protected SearchSynonymRepository $searchSynonymRepository,
        Container $container
    ) {
        parent::__construct($container);
    }

    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Product::class;
    }

    /**
     * Create product.
     *
     * @return \Webkul\Product\Contracts\Product
     */
    public function create(array $data)
    {
        $typeInstance = app(config('product_types.'.$data['type'].'.class'));

        $product = $typeInstance->create($data);

        return $product;
    }

    /**
     * Update product.
     *
     * @param  int  $id
     * @param  array  $attributes
     * @return \Webkul\Product\Contracts\Product
     */
    public function update(array $data, $id, $attributes = [])
    {
        $product = $this->findOrFail($id);

        $product = $product->getTypeInstance()->update($data, $id, $attributes);

        $product->refresh();

        return $product;
    }

    /**
     * Copy product.
     *
     * @param  int  $id
     * @return \Webkul\Product\Contracts\Product
     */
    public function copy($id)
    {
        $product = $this->with([
            'attribute_family',
            'categories',
            'customer_group_prices',
            'inventories',
            'inventory_sources',
        ])->findOrFail($id);

        if ($product->parent_id) {
            throw new \Exception(trans('product::app.datagrid.variant-already-exist-message'));
        }

        return DB::transaction(function () use ($product) {
            $copiedProduct = $product->getTypeInstance()->copy();

            return $copiedProduct;
        });
    }

    /**
     * Copy product.
     */
    public function setSearchEngine(string $searchEngine): self
    {
        $this->searchEngine = $searchEngine;

        return $this;
    }

    /**
     * Return product by filtering through attribute values.
     *
     * @param  string  $code
     * @param  mixed  $value
     * @return \Webkul\Product\Contracts\Product
     */
    public function findByAttributeCode($code, $value)
    {
        $attribute = $this->attributeRepository->findOneByField('code', $code);

        $attributeValues = $this->productAttributeValueRepository->findWhere([
            'attribute_id'          => $attribute->id,
            $attribute->column_name => $value,
        ]);

        if ($attribute->value_per_channel) {
            if ($attribute->value_per_locale) {
                $filteredAttributeValues = $attributeValues
                    ->where('channel', core()->getRequestedChannelCode())
                    ->where('locale', core()->getRequestedLocaleCode());

                if ($attributeValues->isEmpty()) {
                    $filteredAttributeValues = $attributeValues
                        ->where('channel', core()->getRequestedChannelCode())
                        ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel());
                }
            } else {
                $filteredAttributeValues = $attributeValues
                    ->where('channel', core()->getRequestedChannelCode());
            }
        } else {
            if ($attribute->value_per_locale) {
                $filteredAttributeValues = $attributeValues
                    ->where('locale', core()->getRequestedLocaleCode());

                if ($filteredAttributeValues->isEmpty()) {
                    $filteredAttributeValues = $attributeValues
                        ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel());
                }
            } else {
                $filteredAttributeValues = $attributeValues;
            }
        }

        return $filteredAttributeValues->first()?->product;
    }

    /**
     * Retrieve product from slug without throwing an exception.
     */
    public function findBySlug(string $slug): ?Product
    {
        if ($this->searchEngine == 'elastic') {
            $indices = $this->elasticSearchRepository->search([
                'url_key' => $slug,
            ], [
                'type'  => '',
                'from'  => 0,
                'limit' => 1,
                'sort'  => 'id',
                'order' => 'desc',
            ]);

            return $this->find(current($indices['ids']));
        }

        return $this->findByAttributeCode('url_key', $slug);
    }

    /**
     * Retrieve product from slug.
     */
    public function findBySlugOrFail(string $slug): ?Product
    {
        $product = $this->findBySlug($slug);

        if (! $product) {
            throw (new ModelNotFoundException)->setModel(
                get_class($this->model), $slug
            );
        }

        return $product;
    }

    /**
     * Get all products.
     *
     * @return \Illuminate\Support\Collection
     */


    // 02.10.2025
    public function getAll(array $params = [])
    {
        if ($this->searchEngine == 'elastic') {
            return $this->searchFromElastic($params);
        }

        return $this->searchFromDatabaseKeneta($params);
    }




    /**
     * Search product from database.
     *
     * @return \Illuminate\Support\Collection
     */
    public function searchFromDatabase(array $params = [])
    {
        $params['url_key'] ??= null;

        if (! empty($params['query'])) {
            $params['name'] = $params['query'];
        }

        $query = $this->with([
            'attribute_family',
            'images',
            'videos',
            'attribute_values',
            'price_indices',
            'inventory_indices',
            'reviews',
            'variants',
            'variants.attribute_family',
            'variants.attribute_values',
            'variants.price_indices',
            'variants.inventory_indices',
        ])->scopeQuery(function ($query) use ($params) {
            $prefix = DB::getTablePrefix();

            $qb = $query->distinct()
                ->select('products.*')
                ->leftJoin('products as variants', DB::raw('COALESCE('.$prefix.'variants.parent_id, '.$prefix.'variants.id)'), '=', 'products.id')
                ->leftJoin('product_price_indices', function ($join) {
                    $customerGroup = $this->customerRepository->getCurrentGroup();

                    $join->on('products.id', '=', 'product_price_indices.product_id')
                        ->where('product_price_indices.customer_group_id', $customerGroup->id);
                });

            if (! empty($params['category_id'])) {
                $qb->leftJoin('product_categories', 'product_categories.product_id', '=', 'products.id')
                    ->whereIn('product_categories.category_id', explode(',', $params['category_id']));
            }

            if (! empty($params['channel_id'])) {
                $qb->leftJoin('product_channels', 'products.id', '=', 'product_channels.product_id')
                    ->where('product_channels.channel_id', explode(',', $params['channel_id']));
            }

            if (! empty($params['type'])) {
                $qb->where('products.type', $params['type']);

                if (
                    $params['type'] === 'simple'
                    && ! empty($params['exclude_customizable_products'])
                ) {
                    $qb->leftJoin('product_customizable_options', 'products.id', '=', 'product_customizable_options.product_id')
                        ->whereNull('product_customizable_options.id');
                }
            }

            /**
             * Filter query by price.
             */
            if (! empty($params['price'])) {
                $priceRange = explode(',', $params['price']);

                $qb->whereBetween('product_price_indices.min_price', [
                    core()->convertToBasePrice(current($priceRange)),
                    core()->convertToBasePrice(end($priceRange)),
                ]);
            }

            /**
             * Retrieve all the filterable attributes.
             */
            $filterableAttributes = $this->attributeRepository->getProductDefaultAttributes(array_keys($params));

            /**
             * Filter the required attributes.
             */
            $attributes = $filterableAttributes->whereIn('code', [
                'name',
                'status',
                'visible_individually',
                'url_key',
            ]);

            /**
             * Filter collection by required attributes.
             */
            foreach ($attributes as $attribute) {
                $alias = $attribute->code.'_product_attribute_values';

                $qb->leftJoin('product_attribute_values as '.$alias, 'products.id', '=', $alias.'.product_id')
                    ->where($alias.'.attribute_id', $attribute->id);

                if ($attribute->code == 'name') {
                    $synonyms = $this->searchSynonymRepository->getSynonymsByQuery(urldecode($params['name']));

                    $qb->where(function ($subQuery) use ($alias, $synonyms) {
                        foreach ($synonyms as $synonym) {
                            $subQuery->orWhere($alias.'.text_value', 'like', '%'.$synonym.'%');
                        }
                    });
                } elseif ($attribute->code == 'url_key') {
                    if (empty($params['url_key'])) {
                        $qb->whereNotNull($alias.'.text_value');
                    } else {
                        $qb->where($alias.'.text_value', 'like', '%'.urldecode($params['url_key']).'%');
                    }
                } else {
                    if (is_null($params[$attribute->code])) {
                        continue;
                    }

                    $qb->where($alias.'.'.$attribute->column_name, 1);
                }
            }

            /**
             * Filter the filterable attributes.
             */
            $attributes = $filterableAttributes->whereNotIn('code', [
                'price',
                'name',
                'status',
                'visible_individually',
                'url_key',
            ]);

            /**
             * Filter query by attributes.
             */
            if ($attributes->isNotEmpty()) {
                $qb->where(function ($filterQuery) use ($qb, $params, $attributes) {
                    $aliases = [
                        'products' => 'product_attribute_values',
                        'variants' => 'variant_attribute_values',
                    ];

                    foreach ($aliases as $table => $tableAlias) {
                        $filterQuery->orWhere(function ($subFilterQuery) use ($qb, $params, $attributes, $table, $tableAlias) {
                            foreach ($attributes as $attribute) {
                                $alias = $attribute->code.'_'.$tableAlias;

                                $qb->leftJoin('product_attribute_values as '.$alias, function ($join) use ($table, $alias, $attribute) {
                                    $join->on($table.'.id', '=', $alias.'.product_id');

                                    $join->where($alias.'.attribute_id', $attribute->id);
                                });

                                $subFilterQuery->whereIn($alias.'.'.$attribute->column_name, explode(',', $params[$attribute->code]));
                            }
                        });
                    }
                });

                $qb->groupBy('products.id');
            }

            /**
             * Sort collection.
             */
            $sortOptions = $this->getSortOptions($params);

            if ($sortOptions['order'] != 'rand') {
                $attribute = $this->attributeRepository->findOneByField('code', $sortOptions['sort']);

                if ($attribute) {
                    if ($attribute->code === 'price') {
                        $qb->orderBy('product_price_indices.min_price', $sortOptions['order']);
                    } else {
                        $alias = 'sort_product_attribute_values';

                        $qb->leftJoin('product_attribute_values as '.$alias, function ($join) use ($alias, $attribute) {
                            $join->on('products.id', '=', $alias.'.product_id')
                                ->where($alias.'.attribute_id', $attribute->id);

                            if ($attribute->value_per_channel) {
                                if ($attribute->value_per_locale) {
                                    $join->where($alias.'.channel', core()->getRequestedChannelCode())
                                        ->where($alias.'.locale', core()->getRequestedLocaleCode());
                                } else {
                                    $join->where($alias.'.channel', core()->getRequestedChannelCode());
                                }
                            } else {
                                if ($attribute->value_per_locale) {
                                    $join->where($alias.'.locale', core()->getRequestedLocaleCode());
                                }
                            }
                        })
                            ->orderBy($alias.'.'.$attribute->column_name, $sortOptions['order']);
                    }
                } else {
                    /* `created_at` is not an attribute so it will be in else case */
                    $qb->orderBy('products.created_at', $sortOptions['order']);
                }
            } else {
                return $qb->inRandomOrder();
            }

            return $qb->groupBy('products.id');
        });

        $limit = $this->getPerPageLimit($params);

        return $query->paginate($limit);
    }





    /**
     * Search product from database (Keneta improvements).
     *
     * @return \Illuminate\Support\Collection
     */

// 02.10.2025
// public function searchFromDatabaseKeneta(array $params = [])
// {
//     // ---------- Normalize & defaults ----------
//     if (! empty($params['q']) && empty($params['name'])) {
//         $params['name'] = $params['q'];
//     }

//     $params['url_key'] = $params['url_key'] ?? null;

//     // E-commerce sensible defaults (overridable)
//     $params['status'] = array_key_exists('status', $params) ? $params['status'] : 1;
//     $params['visible_individually'] = array_key_exists('visible_individually', $params)
//         ? $params['visible_individually'] : 1;

//     // Pagination
//     $paginationAll = isset($params['pagination']) && (int) $params['pagination'] === 0;
//     $page  = max(1, (int)($params['page']  ?? request('page', 1)));
//     $limit = max(1, (int)($params['limit'] ?? 12));

//     // Sorting
//     $sort  = $params['sort']  ?? 'created_at';
//     $order = strtolower($params['order'] ?? 'desc');
//     $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

//     if (! $paginationAll) {
//         Paginator::currentPageResolver(fn () => $page);
//     }

//     // ---------- Optional slug adapter (non-breaking) ----------
//     // category_slug -> category_id (keeps legacy ID path working)
//     if (! empty($params['category_slug']) && empty($params['category_id'])) {
//         try {
//             $catRepo  = app(\Webkul\Category\Repositories\CategoryRepository::class);
//             $category = \method_exists($catRepo, 'findBySlug')
//                 ? $catRepo->findBySlug($params['category_slug'])
//                 : $catRepo->findOneByField('slug', $params['category_slug']);

//             if ($category) {
//                 $params['category_id'] = $category->id;
//             }
//         } catch (\Throwable $e) {
//             // ignore: slug adapter is optional
//         }
//     }

//     // attributes_slug[code][]=nike -> attributes[code][]=<option_id>
//     if (! empty($params['attributes_slug']) && is_array($params['attributes_slug'])) {
//         try {
//             $optionRepo = app(\Webkul\Attribute\Repositories\AttributeOptionRepository::class);

//             foreach ($params['attributes_slug'] as $code => $slugs) {
//                 $slugsArr = is_array($slugs) ? $slugs : explode(',', (string) $slugs);

//                 $attribute = $this->attributeRepository->findOneByField('code', $code);
//                 if (! $attribute) {
//                     continue;
//                 }

//                 // Match by admin_name (swap to 'slug' if your options table has it)
//                 $optionIds = $optionRepo->model
//                     ->newQuery()
//                     ->where('attribute_id', $attribute->id)
//                     ->whereIn('admin_name', array_map('strval', $slugsArr))
//                     ->pluck('id')
//                     ->all();

//                 if ($optionIds) {
//                     foreach ($optionIds as $id) {
//                         $params['attributes'][$code][] = $id;
//                     }
//                 }
//             }
//         } catch (\Throwable $e) {
//             // ignore: slug adapter is optional
//         }
//     }

//     // ---------- Discover attribute codes (Bagisto way) ----------
//     $candidateCodes = array_keys($params);
//     if (! empty($params['attributes']) && is_array($params['attributes'])) {
//         $candidateCodes = array_values(array_unique(array_merge(
//             $candidateCodes,
//             array_keys($params['attributes'])
//         )));
//     }

//     // Ensure required & sort codes are available
//     $requiredCodes = ['name', 'status', 'visible_individually', 'url_key'];
//     $candidateCodes = array_values(array_unique(array_merge($candidateCodes, $requiredCodes, [$sort])));

//     $attrCollection = $this->attributeRepository
//         ->getProductDefaultAttributes($candidateCodes)
//         ->keyBy('code'); // $attrCollection['color']->id, ->column_name, ->value_per_channel, ->value_per_locale

//     $getAttr = fn (string $code) => $attrCollection->get($code);

//     // Build normalized facets: code => [values...] from both syntaxes
//     $facetValues = [];
//     foreach ($attrCollection as $code => $meta) {
//         if (in_array($code, ['price','name','status','visible_individually','url_key'], true)) {
//             continue;
//         }

//         $vals = [];

//         if (isset($params['attributes'][$code])) {
//             $v = $params['attributes'][$code];
//             $vals = is_array($v) ? $v : explode(',', (string) $v);
//         }

//         if (array_key_exists($code, $params)) {
//             $v = $params[$code];
//             $top = is_array($v) ? $v : explode(',', (string) $v);
//             $vals = array_merge($vals, $top);
//         }

//         $vals = array_values(array_unique(array_filter(array_map('strval', $vals), 'strlen')));
//         if ($vals) {
//             $facetValues[$code] = $vals;
//         }
//     }

//     $sortAttr = $getAttr($sort);

//     // ---------- Wide-call fast path (no filters/facets, no attr/price sort) ----------
//     $hasFacets  = ! empty($facetValues);
//     $hasFilters = ! empty($params['name']) || ! empty($params['price'])
//         || ! empty($params['category_id']) || ! empty($params['channel_id'])
//         || ! empty($params['in_stock'])
//         || (isset($params['promotion_id']) && $params['promotion_id'] !== '');
//     $needsJoinForSort = ($sort === 'price') || (bool) $sortAttr;

//     if (! $paginationAll && ! $hasFacets && ! $hasFilters && ! $needsJoinForSort) {
//         // Resolve required attrs once
//         $reqAttrs   = $this->attributeRepository->getProductDefaultAttributes($requiredCodes)->keyBy('code');
//         $statusAttr = $reqAttrs->get('status');
//         $visAttr    = $reqAttrs->get('visible_individually');
//         $urlAttr    = $reqAttrs->get('url_key');

//         // 1) IDs page (tiny query)
//         $idsPage = $this->model
//             ->newQuery()
//             ->from('products')
//             ->select('products.id')
//             ->when($statusAttr, function ($q) use ($statusAttr) {
//                 $q->whereExists(function ($s) use ($statusAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_s')
//                       ->whereColumn('pav_s.product_id', 'products.id')
//                       ->where('pav_s.attribute_id', $statusAttr->id)
//                       ->where("pav_s.{$statusAttr->column_name}", 1);
//                 });
//             })
//             ->when($visAttr, function ($q) use ($visAttr) {
//                 $q->whereExists(function ($s) use ($visAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_v')
//                       ->whereColumn('pav_v.product_id', 'products.id')
//                       ->where('pav_v.attribute_id', $visAttr->id)
//                       ->where("pav_v.{$visAttr->column_name}", 1);
//                 });
//             })
//             ->when($urlAttr, function ($q) use ($urlAttr) {
//                 $q->whereExists(function ($s) use ($urlAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_u')
//                       ->whereColumn('pav_u.product_id', 'products.id')
//                       ->where('pav_u.attribute_id', $urlAttr->id)
//                       ->whereNotNull("pav_u.{$urlAttr->column_name}");
//                 });
//             })
//             ->orderBy(in_array($sort, ['id','sku','created_at','updated_at'], true) ? "products.$sort" : 'products.created_at', $order)
//             ->paginate($limit, ['*'], 'page', $page);

//         $ids = collect($idsPage->items())->pluck('id')->all();

//         if (empty($ids)) {
//             return new LengthAwarePaginator([], 0, $limit, $page, [
//                 'path'  => request()->url(),
//                 'query' => $params,
//             ]);
//         }

//         // 2) Hydrate relations for those IDs (preserve order)
//         $idsCsv = implode(',', $ids);

//         $items = $this->with([
//                 'attribute_family',
//                 'images',
//                 'videos',
//                 'attribute_values',
//                 'price_indices',
//                 'inventory_indices',
//                 'reviews',
//                 'variants',
//                 'variants.attribute_family',
//                 'variants.attribute_values',
//                 'variants.price_indices',
//                 'variants.inventory_indices',
//             ])
//             ->scopeQuery(function ($q) use ($ids, $idsCsv) {
//                 return $q->whereIn('products.id', $ids)
//                          ->orderByRaw("FIELD(products.id, $idsCsv)");
//             })
//             ->get();

//         return new LengthAwarePaginator($items, $idsPage->total(), $limit, $page, [
//             'path'  => request()->url(),
//             'query' => $params,
//         ]);
//     }

//     // ---------- General (filtered/faceted) path ----------
//     $query = $this->with([
//         'attribute_family',
//         'images',
//         'videos',
//         'attribute_values',
//         'price_indices',
//         'inventory_indices',
//         'reviews',
//         'variants',
//         'variants.attribute_family',
//         'variants.attribute_values',
//         'variants.price_indices',
//         'variants.inventory_indices',
//     ])->scopeQuery(function ($query) use ($params, $facetValues, $sort, $order, $getAttr, $sortAttr) {
//         $qb = $query->select('products.*');

//         // Base columns
//         if (! empty($params['id'])) {
//             $qb->where('products.id', (int) $params['id']);
//         }
//         if (! empty($params['sku'])) {
//             $qb->where('products.sku', $params['sku']);
//         }

//         if (isset($params['promotion_id']) && $params['promotion_id'] !== '') {
//             $promoIds = array_map('intval', explode(',', (string) $params['promotion_id']));
//             $qb->whereIn('products.promotion_id', $promoIds);
//         }

//         // Category → EXISTS
//         if (! empty($params['category_id'])) {
//             $catIds = array_map('intval', explode(',', (string) $params['category_id']));
//             $qb->whereExists(function ($s) use ($catIds) {
//                 $s->selectRaw('1')->from('product_categories')
//                   ->whereColumn('product_categories.product_id', 'products.id')
//                   ->whereIn('product_categories.category_id', $catIds);
//             });
//         }

//         // Channel → EXISTS
//         if (! empty($params['channel_id'])) {
//             $chan = array_map('intval', explode(',', (string) $params['channel_id']));
//             $qb->whereExists(function ($s) use ($chan) {
//                 $s->selectRaw('1')->from('product_channels')
//                   ->whereColumn('product_channels.product_id', 'products.id')
//                   ->whereIn('product_channels.channel_id', $chan);
//             });
//         }

//         // In-stock (product OR any child variant) — adjust qty col if needed
//         if (! empty($params['in_stock'])) {
//             $qb->where(function ($outer) {
//                 $outer->whereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('product_inventory_indices as pii')
//                       ->whereColumn('pii.product_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 })
//                 ->orWhereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_inventory_indices as pii', 'pii.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 });
//             });
//         }

//         // Required attributes
//         if (($a = $getAttr('status')) && isset($params['status'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')->from('product_attribute_values as pav_status')
//                   ->whereColumn('pav_status.product_id', 'products.id')
//                   ->where('pav_status.attribute_id', $a->id)
//                   ->where("pav_status.{$a->column_name}", $params['status']);
//             });
//         }

//         if (($a = $getAttr('visible_individually')) && isset($params['visible_individually'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')->from('product_attribute_values as pav_vis')
//                   ->whereColumn('pav_vis.product_id', 'products.id')
//                   ->where('pav_vis.attribute_id', $a->id)
//                   ->where("pav_vis.{$a->column_name}", $params['visible_individually']);
//             });
//         }

//         // name (synonymized LIKE) only when provided
//         if (! empty($params['name']) && ($a = $getAttr('name'))) {
//             $synonyms = $this->searchSynonymRepository
//                 ->getSynonymsByQuery(urldecode($params['name'])) ?? [];
//             $synonyms = array_slice(array_values(array_unique($synonyms)), 0, 10);

//             if ($synonyms) {
//                 $qb->where(function ($outer) use ($a, $synonyms) {
//                     $outer->whereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('product_attribute_values as pav_name')
//                           ->whereColumn('pav_name.product_id', 'products.id')
//                           ->where('pav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("pav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     })
//                     ->orWhereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('products as v')
//                           ->join('product_attribute_values as vav_name', 'vav_name.product_id', '=', 'v.id')
//                           ->whereColumn('v.parent_id', 'products.id') // index-friendly
//                           ->where('vav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("vav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     });
//                 });
//             }
//         }

//         // url_key guard (preserve original)
//         if (($a = $getAttr('url_key'))) {
//             if (empty($params['url_key'])) {
//                 $qb->whereExists(function ($s) use ($a) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->whereNotNull("pav_url.{$a->column_name}");
//                 });
//             } else {
//                 $needle = '%'.urldecode($params['url_key']).'%';
//                 $qb->whereExists(function ($s) use ($a, $needle) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->where("pav_url.{$a->column_name}", 'like', $needle);
//                 });
//             }
//         }

//         // Generic facet attributes (product OR child variant)
//         foreach ($facetValues as $code => $values) {
//             $a = $getAttr($code);
//             if (! $a) {
//                 continue;
//             }

//             $qb->where(function ($outer) use ($a, $values) {
//                 $outer->whereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_f')
//                       ->whereColumn('pav_f.product_id', 'products.id')
//                       ->where('pav_f.attribute_id', $a->id)
//                       ->whereIn("pav_f.{$a->column_name}", $values);
//                 })
//                 ->orWhereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_attribute_values as vav_f', 'vav_f.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id') // index-friendly
//                       ->where('vav_f.attribute_id', $a->id)
//                       ->whereIn("vav_f.{$a->column_name}", $values);
//                 });
//             });
//         }

//         // Price range (EXISTS)
//         if (! empty($params['price'])) {
//             $range = explode(',', (string) $params['price']);
//             $min = core()->convertToBasePrice((float) current($range));
//             $max = core()->convertToBasePrice((float) end($range));

//             $qb->whereExists(function ($s) use ($min, $max) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $s->selectRaw('1')
//                   ->from('product_price_indices as ppi_f')
//                   ->whereColumn('ppi_f.product_id', 'products.id')
//                   ->where('ppi_f.customer_group_id', $customerGroup->id)
//                   ->whereBetween('ppi_f.min_price', [$min, $max]);
//             });
//         }

//         // Sorting
//         if ($sort === 'price') {
//             $qb->leftJoin('product_price_indices as ppi_s', function ($join) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $join->on('products.id', '=', 'ppi_s.product_id')
//                      ->where('ppi_s.customer_group_id', $customerGroup->id);
//             })->orderBy('ppi_s.min_price', $order);
//         } else {
//             if ($sortAttr) {
//                 $qb->leftJoin('product_attribute_values as sort_pav', function ($join) use ($sortAttr) {
//                     $join->on('products.id', '=', 'sort_pav.product_id')
//                          ->where('sort_pav.attribute_id', $sortAttr->id);

//                     if (! empty($sortAttr->value_per_channel)) {
//                         $join->where('sort_pav.channel', core()->getRequestedChannelCode());
//                     }
//                     if (! empty($sortAttr->value_per_locale)) {
//                         $join->where('sort_pav.locale', core()->getRequestedLocaleCode());
//                     }
//                 })->orderBy("sort_pav.{$sortAttr->column_name}", $order);
//             } else {
//                 $allowed = ['id', 'sku', 'promotion_id', 'created_at', 'updated_at'];
//                 $col = in_array($sort, $allowed, true) ? $sort : 'created_at';
//                 $qb->orderBy("products.$col", $order);
//             }
//         }

//         return $qb;
//     });

//     // ---------- Return shape per API ----------
//     if ($paginationAll) {
//         return $query->get();
//     }

//     // If you later want to skip COUNT on heavy facet pages, switch to simplePaginate($limit) when !empty($facetValues).
//     return $query->paginate($limit);
// }

// public function searchFromDatabaseKeneta(array $params = [])
// {
//     // ---------- Normalize & defaults ----------
//     if (! empty($params['q']) && empty($params['name'])) {
//         $params['name'] = $params['q'];
//     }

//     $params['url_key'] = $params['url_key'] ?? null;

//     // E-commerce sensible defaults (overridable)
//     $params['status'] = array_key_exists('status', $params) ? $params['status'] : 1;
//     $params['visible_individually'] = array_key_exists('visible_individually', $params)
//         ? $params['visible_individually'] : 1;

//     // Pagination
//     $paginationAll = isset($params['pagination']) && (int) $params['pagination'] === 0;
//     $page  = max(1, (int)($params['page']  ?? request('page', 1)));
//     $limit = max(1, (int)($params['limit'] ?? 12));

//     // Sorting
//     $sort  = $params['sort']  ?? 'created_at';
//     $order = strtolower($params['order'] ?? 'desc');
//     $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

//     if (! $paginationAll) {
//         Paginator::currentPageResolver(fn () => $page);
//     }

//     // ---------- Optional slug adapter (non-breaking) ----------
//     // category_slug -> category_id (keeps legacy ID path working)
//     if (! empty($params['category_slug']) && empty($params['category_id'])) {
//         try {
//             $catRepo  = app(\Webkul\Category\Repositories\CategoryRepository::class);
//             $category = \method_exists($catRepo, 'findBySlug')
//                 ? $catRepo->findBySlug($params['category_slug'])
//                 : $catRepo->findOneByField('slug', $params['category_slug']);

//             if ($category) {
//                 $params['category_id'] = $category->id;
//             }
//         } catch (\Throwable $e) {
//             // ignore: slug adapter is optional
//         }
//     }

//     // attributes_slug[code][]=nike -> attributes[code][]=<option_id>
//     if (! empty($params['attributes_slug']) && is_array($params['attributes_slug'])) {
//         try {
//             $optionRepo = app(\Webkul\Attribute\Repositories\AttributeOptionRepository::class);

//             foreach ($params['attributes_slug'] as $code => $slugs) {
//                 $slugsArr = is_array($slugs) ? $slugs : explode(',', (string) $slugs);

//                 $attribute = $this->attributeRepository->findOneByField('code', $code);
//                 if (! $attribute) {
//                     continue;
//                 }

//                 // Match by admin_name (swap to 'slug' if your options table has it)
//                 $optionIds = $optionRepo->model
//                     ->newQuery()
//                     ->where('attribute_id', $attribute->id)
//                     ->whereIn('admin_name', array_map('strval', $slugsArr))
//                     ->pluck('id')
//                     ->all();

//                 if ($optionIds) {
//                     foreach ($optionIds as $id) {
//                         $params['attributes'][$code][] = $id;
//                     }
//                 }
//             }
//         } catch (\Throwable $e) {
//             // ignore: slug adapter is optional
//         }
//     }

//     // ---------- Discover attribute codes (Bagisto way) ----------
//     $candidateCodes = array_keys($params);
//     if (! empty($params['attributes']) && is_array($params['attributes'])) {
//         $candidateCodes = array_values(array_unique(array_merge(
//             $candidateCodes,
//             array_keys($params['attributes'])
//         )));
//     }

//     // Ensure required & sort codes are available
//     $requiredCodes = ['name', 'status', 'visible_individually', 'url_key'];
//     $candidateCodes = array_values(array_unique(array_merge($candidateCodes, $requiredCodes, [$sort])));

//     $attrCollection = $this->attributeRepository
//         ->getProductDefaultAttributes($candidateCodes)
//         ->keyBy('code'); // $attrCollection['color']->id, ->column_name, ->value_per_channel, ->value_per_locale

//     $getAttr = fn (string $code) => $attrCollection->get($code);

//     // Build normalized facets: code => [values...] from both syntaxes
//     $facetValues = [];
//     foreach ($attrCollection as $code => $meta) {
//         if (in_array($code, ['price','name','status','visible_individually','url_key'], true)) {
//             continue;
//         }

//         $vals = [];

//         if (isset($params['attributes'][$code])) {
//             $v = $params['attributes'][$code];
//             $vals = is_array($v) ? $v : explode(',', (string) $v);
//         }

//         if (array_key_exists($code, $params)) {
//             $v = $params[$code];
//             $top = is_array($v) ? $v : explode(',', (string) $v);
//             $vals = array_merge($vals, $top);
//         }

//         $vals = array_values(array_unique(array_filter(array_map('strval', $vals), 'strlen')));
//         if ($vals) {
//             $facetValues[$code] = $vals;
//         }
//     }

//     $sortAttr = $getAttr($sort);

//     // ---------- Wide-call fast path (no filters/facets, no attr/price sort) ----------
//     $hasFacets  = ! empty($facetValues);
//     $hasFilters = ! empty($params['name']) || ! empty($params['price'])
//         || ! empty($params['category_id']) || ! empty($params['channel_id'])
//         || ! empty($params['in_stock'])
//         || (isset($params['promotion_id']) && $params['promotion_id'] !== '');
//     $needsJoinForSort = ($sort === 'price') || (bool) $sortAttr;

//     if (! $paginationAll && ! $hasFacets && ! $hasFilters && ! $needsJoinForSort) {
//         // Resolve required attrs once
//         $reqAttrs   = $this->attributeRepository->getProductDefaultAttributes($requiredCodes)->keyBy('code');
//         $statusAttr = $reqAttrs->get('status');
//         $visAttr    = $reqAttrs->get('visible_individually');
//         $urlAttr    = $reqAttrs->get('url_key');

//         // 1) IDs page (tiny query)
//         $idsPage = $this->model
//             ->newQuery()
//             ->from('products')
//             ->select('products.id')
//             ->when($statusAttr, function ($q) use ($statusAttr) {
//                 $q->whereExists(function ($s) use ($statusAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_s')
//                       ->whereColumn('pav_s.product_id', 'products.id')
//                       ->where('pav_s.attribute_id', $statusAttr->id)
//                       ->where("pav_s.{$statusAttr->column_name}", 1);
//                 });
//             })
//             ->when($visAttr, function ($q) use ($visAttr) {
//                 $q->whereExists(function ($s) use ($visAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_v')
//                       ->whereColumn('pav_v.product_id', 'products.id')
//                       ->where('pav_v.attribute_id', $visAttr->id)
//                       ->where("pav_v.{$visAttr->column_name}", 1);
//                 });
//             })
//             ->when($urlAttr, function ($q) use ($urlAttr) {
//                 $q->whereExists(function ($s) use ($urlAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_u')
//                       ->whereColumn('pav_u.product_id', 'products.id')
//                       ->where('pav_u.attribute_id', $urlAttr->id)
//                       ->whereNotNull("pav_u.{$urlAttr->column_name}");
//                 });
//             })
//             ->orderBy(in_array($sort, ['id','sku','created_at','updated_at'], true) ? "products.$sort" : 'products.created_at', $order)
//             ->paginate($limit, ['*'], 'page', $page);

//         $ids = collect($idsPage->items())->pluck('id')->all();

//         if (empty($ids)) {
//             return new LengthAwarePaginator([], 0, $limit, $page, [
//                 'path'  => request()->url(),
//                 'query' => $params,
//             ]);
//         }

//         // 2) Hydrate relations for those IDs (preserve order)
//         $idsCsv = implode(',', $ids);

//         $items = $this->with([
//                 'attribute_family',
//                 'images',
//                 'videos',
//                 'attribute_values',
//                 'price_indices',
//                 'inventory_indices',
//                 'reviews',
//                 'variants',
//                 'variants.attribute_family',
//                 'variants.attribute_values',
//                 'variants.price_indices',
//                 'variants.inventory_indices',
//             ])
//             ->scopeQuery(function ($q) use ($ids, $idsCsv) {
//                 return $q->whereIn('products.id', $ids)
//                          ->orderByRaw("FIELD(products.id, $idsCsv)");
//             })
//             ->get();

//         return new LengthAwarePaginator($items, $idsPage->total(), $limit, $page, [
//             'path'  => request()->url(),
//             'query' => $params,
//         ]);
//     }

//     // ---------- General (filtered/faceted) path ----------
//     $query = $this->with([
//         'attribute_family',
//         'images',
//         'videos',
//         'attribute_values',
//         'price_indices',
//         'inventory_indices',
//         'reviews',
//         'variants',
//         'variants.attribute_family',
//         'variants.attribute_values',
//         'variants.price_indices',
//         'variants.inventory_indices',
//     ])->scopeQuery(function ($query) use ($params, $facetValues, $sort, $order, $getAttr, $sortAttr) {
//         $qb = $query->select('products.*');

//         // Base columns
//         if (! empty($params['id'])) {
//             $qb->where('products.id', (int) $params['id']);
//         }
//         if (! empty($params['sku'])) {
//             $qb->where('products.sku', $params['sku']);
//         }

//         if (isset($params['promotion_id']) && $params['promotion_id'] !== '') {
//             $promoIds = array_map('intval', explode(',', (string) $params['promotion_id']));
//             $qb->whereIn('products.promotion_id', $promoIds);
//         }

//         // Category → EXISTS
//         if (! empty($params['category_id'])) {
//             $catIds = array_map('intval', explode(',', (string) $params['category_id']));
//             $qb->whereExists(function ($s) use ($catIds) {
//                 $s->selectRaw('1')->from('product_categories')
//                   ->whereColumn('product_categories.product_id', 'products.id')
//                   ->whereIn('product_categories.category_id', $catIds);
//             });
//         }

//         // Channel → EXISTS
//         if (! empty($params['channel_id'])) {
//             $chan = array_map('intval', explode(',', (string) $params['channel_id']));
//             $qb->whereExists(function ($s) use ($chan) {
//                 $s->selectRaw('1')->from('product_channels')
//                   ->whereColumn('product_channels.product_id', 'products.id')
//                   ->whereIn('product_channels.channel_id', $chan);
//             });
//         }

//         // In-stock (product OR any child variant) — adjust qty col if needed
//         if (! empty($params['in_stock'])) {
//             $qb->where(function ($outer) {
//                 $outer->whereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('product_inventory_indices as pii')
//                       ->whereColumn('pii.product_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 })
//                 ->orWhereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_inventory_indices as pii', 'pii.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 });
//             });
//         }

//         // Required attributes
//         if (($a = $getAttr('status')) && isset($params['status'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')->from('product_attribute_values as pav_status')
//                   ->whereColumn('pav_status.product_id', 'products.id')
//                   ->where('pav_status.attribute_id', $a->id)
//                   ->where("pav_status.{$a->column_name}", $params['status']);
//             });
//         }

//         if (($a = $getAttr('visible_individually')) && isset($params['visible_individually'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')->from('product_attribute_values as pav_vis')
//                   ->whereColumn('pav_vis.product_id', 'products.id')
//                   ->where('pav_vis.attribute_id', $a->id)
//                   ->where("pav_vis.{$a->column_name}", $params['visible_individually']);
//             });
//         }

//         // name (synonymized LIKE) only when provided
//         if (! empty($params['name']) && ($a = $getAttr('name'))) {
//             $synonyms = $this->searchSynonymRepository
//                 ->getSynonymsByQuery(urldecode($params['name'])) ?? [];
//             $synonyms = array_slice(array_values(array_unique($synonyms)), 0, 10);

//             if ($synonyms) {
//                 $qb->where(function ($outer) use ($a, $synonyms) {
//                     $outer->whereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('product_attribute_values as pav_name')
//                           ->whereColumn('pav_name.product_id', 'products.id')
//                           ->where('pav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("pav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     })
//                     ->orWhereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('products as v')
//                           ->join('product_attribute_values as vav_name', 'vav_name.product_id', '=', 'v.id')
//                           ->whereColumn('v.parent_id', 'products.id') // index-friendly
//                           ->where('vav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("vav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     });
//                 });
//             }
//         }

//         // url_key guard (preserve original)
//         if (($a = $getAttr('url_key'))) {
//             if (empty($params['url_key'])) {
//                 $qb->whereExists(function ($s) use ($a) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->whereNotNull("pav_url.{$a->column_name}");
//                 });
//             } else {
//                 $needle = '%'.urldecode($params['url_key']).'%';
//                 $qb->whereExists(function ($s) use ($a, $needle) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->where("pav_url.{$a->column_name}", 'like', $needle);
//                 });
//             }
//         }

//         // Generic facet attributes (product OR child variant)
//         foreach ($facetValues as $code => $values) {
//             $a = $getAttr($code);
//             if (! $a) {
//                 continue;
//             }

//             $qb->where(function ($outer) use ($a, $values) {
//                 $outer->whereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_f')
//                       ->whereColumn('pav_f.product_id', 'products.id')
//                       ->where('pav_f.attribute_id', $a->id)
//                       ->whereIn("pav_f.{$a->column_name}", $values);
//                 })
//                 ->orWhereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_attribute_values as vav_f', 'vav_f.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id') // index-friendly
//                       ->where('vav_f.attribute_id', $a->id)
//                       ->whereIn("vav_f.{$a->column_name}", $values);
//                 });
//             });
//         }

//         // Price range (EXISTS)
//         if (! empty($params['price'])) {
//             $range = explode(',', (string) $params['price']);
//             $min = core()->convertToBasePrice((float) current($range));
//             $max = core()->convertToBasePrice((float) end($range));

//             $qb->whereExists(function ($s) use ($min, $max) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $s->selectRaw('1')
//                   ->from('product_price_indices as ppi_f')
//                   ->whereColumn('ppi_f.product_id', 'products.id')
//                   ->where('ppi_f.customer_group_id', $customerGroup->id)
//                   ->whereBetween('ppi_f.min_price', [$min, $max]);
//             });
//         }

//         // Sorting
//         if ($sort === 'price') {
//             $qb->leftJoin('product_price_indices as ppi_s', function ($join) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $join->on('products.id', '=', 'ppi_s.product_id')
//                      ->where('ppi_s.customer_group_id', $customerGroup->id);
//             })->orderBy('ppi_s.min_price', $order);
//         } else {
//             if ($sortAttr) {
//                 $qb->leftJoin('product_attribute_values as sort_pav', function ($join) use ($sortAttr) {
//                     $join->on('products.id', '=', 'sort_pav.product_id')
//                          ->where('sort_pav.attribute_id', $sortAttr->id);

//                     if (! empty($sortAttr->value_per_channel)) {
//                         $join->where('sort_pav.channel', core()->getRequestedChannelCode());
//                     }
//                     if (! empty($sortAttr->value_per_locale)) {
//                         $join->where('sort_pav.locale', core()->getRequestedLocaleCode());
//                     }
//                 })->orderBy("sort_pav.{$sortAttr->column_name}", $order);
//             } else {
//                 $allowed = ['id', 'sku', 'promotion_id', 'created_at', 'updated_at'];
//                 $col = in_array($sort, $allowed, true) ? $sort : 'created_at';
//                 $qb->orderBy("products.$col", $order);
//             }
//         }

//         return $qb;
//     });

//     // ---------- Return shape per API ----------
//     if ($paginationAll) {
//         return $query->get();
//     }

//     // If you later want to skip COUNT on heavy facet pages, switch to simplePaginate($limit) when !empty($facetValues).
//     return $query->paginate($limit);
// }

//20.01.2026
// public function searchFromDatabaseKeneta(array $params = [])
// {

//     $cacheKey = 'search_ids:' . md5(json_encode($params));

//     $paginationAll = isset($params['pagination']) && (int) $params['pagination'] === 0;
//     $page  = max(1, (int)($params['page']  ?? request('page', 1)));
//     $limit = max(1, (int)($params['limit'] ?? 12));

//     $cached = Cache::tags(['products_search'])->get($cacheKey);

//     if ($cached) {
//         if ($paginationAll) {
//             return $this->model
//                 ->with([
//                     'attribute_family',
//                     'images',
//                     'videos',
//                     'attribute_values',
//                     'price_indices',
//                     'inventory_indices',
//                     'reviews',
//                     'variants',
//                     'variants.attribute_family',
//                     'variants.attribute_values',
//                     'variants.price_indices',
//                     'variants.inventory_indices',
//                 ])
//                 ->whereIn('products.id', $cached['ids'])
//                 ->get();
//         }

//         $ids = $cached['ids'];
//         $idsCsv = implode(',', $ids);

//         $items = $this->model
//             ->with([
//                 'attribute_family',
//                 'images',
//                 'videos',
//                 'attribute_values',
//                 'price_indices',
//                 'inventory_indices',
//                 'reviews',
//                 'variants',
//                 'variants.attribute_family',
//                 'variants.attribute_values',
//                 'variants.price_indices',
//                 'variants.inventory_indices',
//             ])
//             ->whereIn('products.id', $ids)
//             ->orderByRaw("FIELD(products.id, $idsCsv)")
//             ->get();

//         return new LengthAwarePaginator(
//             $items,
//             $cached['total'],
//             $limit,
//             $page,
//             ['path' => request()->url(), 'query' => $params]
//         );
//     }

//     if (! empty($params['q']) && empty($params['name'])) {
//         $params['name'] = $params['q'];
//     }

//     $params['url_key'] = $params['url_key'] ?? null;
//     $params['status'] = array_key_exists('status', $params) ? $params['status'] : 1;
//     $params['visible_individually'] = array_key_exists('visible_individually', $params) ? $params['visible_individually'] : 1;

//     $sort  = $params['sort']  ?? 'created_at';
//     $order = strtolower($params['order'] ?? 'desc');
//     $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

//     if (! $paginationAll) {
//         Paginator::currentPageResolver(fn () => $page);
//     }

//     if (! empty($params['category_slug']) && empty($params['category_id'])) {
//         try {
//             $catRepo  = app(\Webkul\Category\Repositories\CategoryRepository::class);
//             $category = method_exists($catRepo, 'findBySlug')
//                 ? $catRepo->findBySlug($params['category_slug'])
//                 : $catRepo->findOneByField('slug', $params['category_slug']);
//             if ($category) {
//                 $params['category_id'] = $category->id;
//             }
//         } catch (\Throwable $e) {}
//     }

//     if (! empty($params['attributes_slug']) && is_array($params['attributes_slug'])) {
//         try {
//             $optionRepo = app(\Webkul\Attribute\Repositories\AttributeOptionRepository::class);

//             foreach ($params['attributes_slug'] as $code => $slugs) {
//                 $slugsArr = is_array($slugs) ? $slugs : explode(',', (string) $slugs);
//                 $attribute = $this->attributeRepository->findOneByField('code', $code);
//                 if (! $attribute) continue;

//                 $optionIds = $optionRepo->model
//                     ->newQuery()
//                     ->where('attribute_id', $attribute->id)
//                     ->whereIn('admin_name', array_map('strval', $slugsArr))
//                     ->pluck('id')
//                     ->all();

//                 if ($optionIds) {
//                     foreach ($optionIds as $id) {
//                         $params['attributes'][$code][] = $id;
//                     }
//                 }
//             }
//         } catch (\Throwable $e) {}
//     }

//     $candidateCodes = array_keys($params);
//     if (! empty($params['attributes']) && is_array($params['attributes'])) {
//         $candidateCodes = array_values(array_unique(array_merge(
//             $candidateCodes, array_keys($params['attributes'])
//         )));
//     }

//     $requiredCodes = ['name', 'status', 'visible_individually', 'url_key'];
//     $candidateCodes = array_values(array_unique(array_merge($candidateCodes, $requiredCodes, [$sort])));

//     $attrCollection = $this->attributeRepository
//         ->getProductDefaultAttributes($candidateCodes)
//         ->keyBy('code');

//     $getAttr = fn (string $code) => $attrCollection->get($code);

//     $facetValues = [];
//     foreach ($attrCollection as $code => $meta) {
//         if (in_array($code, ['price','name','status','visible_individually','url_key'], true)) continue;

//         $vals = [];

//         if (isset($params['attributes'][$code])) {
//             $v = $params['attributes'][$code];
//             $vals = is_array($v) ? $v : explode(',', (string) $v);
//         }

//         if (array_key_exists($code, $params)) {
//             $v = $params[$code];
//             $vals = array_merge(
//                 $vals,
//                 is_array($v) ? $v : explode(',', (string) $v)
//             );
//         }

//         $vals = array_values(array_unique(array_filter(array_map('strval', $vals), 'strlen')));
//         if ($vals) $facetValues[$code] = $vals;
//     }

//     $sortAttr = $getAttr($sort);

//     $hasFacets = ! empty($facetValues);
//     $hasFilters = ! empty($params['name']) ||
//                   ! empty($params['price']) ||
//                   ! empty($params['category_id']) ||
//                   ! empty($params['channel_id']) ||
//                   ! empty($params['in_stock']) ||
//                   (isset($params['promotion_id']) && $params['promotion_id'] !== '');

//     $needsJoinForSort = ($sort === 'price') || (bool) $sortAttr;

//     if (! $paginationAll && ! $hasFacets && ! $hasFilters && ! $needsJoinForSort) {

//         $reqAttrs   = $this->attributeRepository->getProductDefaultAttributes($requiredCodes)->keyBy('code');
//         $statusAttr = $reqAttrs->get('status');
//         $visAttr    = $reqAttrs->get('visible_individually');
//         $urlAttr    = $reqAttrs->get('url_key');

//         $idsPage = $this->model
//             ->newQuery()
//             ->from('products')
//             ->select('products.id')
//             ->when($statusAttr, function ($q) use ($statusAttr) {
//                 $q->whereExists(function ($s) use ($statusAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_s')
//                       ->whereColumn('pav_s.product_id', 'products.id')
//                       ->where('pav_s.attribute_id', $statusAttr->id)
//                       ->where("pav_s.{$statusAttr->column_name}", 1);
//                 });
//             })
//             ->when($visAttr, function ($q) use ($visAttr) {
//                 $q->whereExists(function ($s) use ($visAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_v')
//                       ->whereColumn('pav_v.product_id', 'products.id')
//                       ->where('pav_v.attribute_id', $visAttr->id)
//                       ->where("pav_v.{$visAttr->column_name}", 1);
//                 });
//             })
//             ->when($urlAttr, function ($q) use ($urlAttr) {
//                 $q->whereExists(function ($s) use ($urlAttr) {
//                     $s->selectRaw('1')->from('product_attribute_values as pav_u')
//                       ->whereColumn('pav_u.product_id', 'products.id')
//                       ->where('pav_u.attribute_id', $urlAttr->id)
//                       ->whereNotNull("pav_u.{$urlAttr->column_name}");
//                 });
//             })
//             ->orderBy(in_array($sort, ['id','sku','created_at','updated_at'], true)
//                 ? "products.$sort"
//                 : 'products.created_at',
//                 $order)
//             ->paginate($limit, ['*'], 'page', $page);

//         $ids = collect($idsPage->items())->pluck('id')->all();

//         $store = [
//             'ids'   => $ids,
//             'total' => $idsPage->total(),
//             'page'  => $page,
//             'limit' => $limit
//         ];

//         Cache::tags(['products_search'])->put($cacheKey, $store, 900);

//         $idsCsv = implode(',', $ids);

//         $items = $this->model
//             ->with([
//                 'attribute_family',
//                 'images',
//                 'videos',
//                 'attribute_values',
//                 'price_indices',
//                 'inventory_indices',
//                 'reviews',
//                 'variants',
//                 'variants.attribute_family',
//                 'variants.attribute_values',
//                 'variants.price_indices',
//                 'variants.inventory_indices',
//             ])
//             ->whereIn('products.id', $ids)
//             ->orderByRaw("FIELD(products.id, $idsCsv)")
//             ->get();

//         return new LengthAwarePaginator(
//             $items,
//             $idsPage->total(),
//             $limit,
//             $page,
//             ['path' => request()->url(), 'query' => $params]
//         );
//     }

//     $query = $this->with([
//         'attribute_family',
//         'images',
//         'videos',
//         'attribute_values',
//         'price_indices',
//         'inventory_indices',
//         'reviews',
//         'variants',
//         'variants.attribute_family',
//         'variants.attribute_values',
//         'variants.price_indices',
//         'variants.inventory_indices',
//     ])->scopeQuery(function ($query) use ($params, $facetValues, $sort, $order, $getAttr, $sortAttr) {

//         $qb = $query->select('products.*');

//         if (! empty($params['id'])) {
//             $qb->where('products.id', (int) $params['id']);
//         }
//         if (! empty($params['sku'])) {
//             $qb->where('products.sku', $params['sku']);
//         }

//         if (isset($params['promotion_id']) && $params['promotion_id'] !== '') {
//             $promoIds = array_map('intval', explode(',', (string) $params['promotion_id']));
//             $qb->whereIn('products.promotion_id', $promoIds);
//         }

//         if (! empty($params['category_id'])) {
//             $catIds = array_map('intval', explode(',', (string) $params['category_id']));
//             $qb->whereExists(function ($s) use ($catIds) {
//                 $s->selectRaw('1')
//                   ->from('product_categories')
//                   ->whereColumn('product_categories.product_id', 'products.id')
//                   ->whereIn('product_categories.category_id', $catIds);
//             });
//         }

//         if (! empty($params['channel_id'])) {
//             $chan = array_map('intval', explode(',', (string) $params['channel_id']));
//             $qb->whereExists(function ($s) use ($chan) {
//                 $s->selectRaw('1')
//                   ->from('product_channels')
//                   ->whereColumn('product_channels.product_id', 'products.id')
//                   ->whereIn('product_channels.channel_id', $chan);
//             });
//         }

//         if (! empty($params['in_stock'])) {
//             $qb->where(function ($outer) {
//                 $outer->whereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('product_inventory_indices as pii')
//                       ->whereColumn('pii.product_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 })
//                 ->orWhereExists(function ($s) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_inventory_indices as pii', 'pii.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id')
//                       ->where('pii.qty', '>', 0);
//                 });
//             });
//         }

//         if (($a = $getAttr('status')) && isset($params['status'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')
//                   ->from('product_attribute_values as pav_status')
//                   ->whereColumn('pav_status.product_id', 'products.id')
//                   ->where('pav_status.attribute_id', $a->id)
//                   ->where("pav_status.{$a->column_name}", $params['status']);
//             });
//         }

//         if (($a = $getAttr('visible_individually')) && isset($params['visible_individually'])) {
//             $qb->whereExists(function ($s) use ($a, $params) {
//                 $s->selectRaw('1')
//                   ->from('product_attribute_values as pav_vis')
//                   ->whereColumn('pav_vis.product_id', 'products.id')
//                   ->where('pav_vis.attribute_id', $a->id)
//                   ->where("pav_vis.{$a->column_name}", $params['visible_individually']);
//             });
//         }

//         if (! empty($params['name']) && ($a = $getAttr('name'))) {
//             $synonyms = $this->searchSynonymRepository
//                 ->getSynonymsByQuery(urldecode($params['name'])) ?? [];
//             $synonyms = array_slice(array_values(array_unique($synonyms)), 0, 10);

//             if ($synonyms) {
//                 $qb->where(function ($outer) use ($a, $synonyms) {
//                     $outer->whereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('product_attribute_values as pav_name')
//                           ->whereColumn('pav_name.product_id', 'products.id')
//                           ->where('pav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("pav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     })
//                     ->orWhereExists(function ($s) use ($a, $synonyms) {
//                         $s->selectRaw('1')
//                           ->from('products as v')
//                           ->join('product_attribute_values as vav_name', 'vav_name.product_id', '=', 'v.id')
//                           ->whereColumn('v.parent_id', 'products.id')
//                           ->where('vav_name.attribute_id', $a->id)
//                           ->where(function ($w) use ($a, $synonyms) {
//                               foreach ($synonyms as $t) {
//                                   $w->orWhere("vav_name.{$a->column_name}", 'like', "%{$t}%");
//                               }
//                           });
//                     });
//                 });
//             }
//         }

//         if (($a = $getAttr('url_key'))) {
//             if (empty($params['url_key'])) {
//                 $qb->whereExists(function ($s) use ($a) {
//                     $s->selectRaw('1')
//                       ->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->whereNotNull("pav_url.{$a->column_name}");
//                 });
//             } else {
//                 $needle = '%'.urldecode($params['url_key']).'%';
//                 $qb->whereExists(function ($s) use ($a, $needle) {
//                     $s->selectRaw('1')
//                       ->from('product_attribute_values as pav_url')
//                       ->whereColumn('pav_url.product_id', 'products.id')
//                       ->where('pav_url.attribute_id', $a->id)
//                       ->where("pav_url.{$a->column_name}", 'like', $needle);
//                 });
//             }
//         }

//         foreach ($facetValues as $code => $values) {
//             $a = $getAttr($code);
//             if (! $a) continue;

//             $qb->where(function ($outer) use ($a, $values) {
//                 $outer->whereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')
//                       ->from('product_attribute_values as pav_f')
//                       ->whereColumn('pav_f.product_id', 'products.id')
//                       ->where('pav_f.attribute_id', $a->id)
//                       ->whereIn("pav_f.{$a->column_name}", $values);
//                 })
//                 ->orWhereExists(function ($s) use ($a, $values) {
//                     $s->selectRaw('1')
//                       ->from('products as v')
//                       ->join('product_attribute_values as vav_f', 'vav_f.product_id', '=', 'v.id')
//                       ->whereColumn('v.parent_id', 'products.id')
//                       ->where('vav_f.attribute_id', $a->id)
//                       ->whereIn("vav_f.{$a->column_name}", $values);
//                 });
//             });
//         }

//         if (! empty($params['price'])) {
//             $range = explode(',', (string) $params['price']);
//             $min = core()->convertToBasePrice((float) current($range));
//             $max = core()->convertToBasePrice((float) end($range));

//             $qb->whereExists(function ($s) use ($min, $max) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $s->selectRaw('1')
//                   ->from('product_price_indices as ppi_f')
//                   ->whereColumn('ppi_f.product_id', 'products.id')
//                   ->where('ppi_f.customer_group_id', $customerGroup->id)
//                   ->whereBetween('ppi_f.min_price', [$min, $max]);
//             });
//         }

//         if ($sort === 'price') {
//             $qb->leftJoin('product_price_indices as ppi_s', function ($join) {
//                 $customerGroup = $this->customerRepository->getCurrentGroup();
//                 $join->on('products.id', '=', 'ppi_s.product_id')
//                      ->where('ppi_s.customer_group_id', $customerGroup->id);
//             })->orderBy('ppi_s.min_price', $order);
//         } else {
//             if ($sortAttr) {
//                 $qb->leftJoin('product_attribute_values as sort_pav', function ($join) use ($sortAttr) {
//                     $join->on('products.id', '=', 'sort_pav.product_id')
//                          ->where('sort_pav.attribute_id', $sortAttr->id);

//                     if (! empty($sortAttr->value_per_channel)) {
//                         $join->where('sort_pav.channel', core()->getRequestedChannelCode());
//                     }
//                     if (! empty($sortAttr->value_per_locale)) {
//                         $join->where('sort_pav.locale', core()->getRequestedLocaleCode());
//                     }
//                 })->orderBy("sort_pav.{$sortAttr->column_name}", $order);
//             } else {
//                 $allowed = ['id', 'sku', 'promotion_id', 'created_at', 'updated_at'];
//                 $col = in_array($sort, $allowed, true) ? $sort : 'created_at';
//                 $qb->orderBy("products.$col", $order);
//             }
//         }

//         return $qb;
//     });

//     $executed = $paginationAll ? $query->get() : $query->paginate($limit);

//     if ($paginationAll) {
//         Cache::tags(['products_search'])->put($cacheKey, [
//             'ids' => $executed->pluck('id')->all(),
//             'total' => count($executed),
//             'page' => 1,
//             'limit' => count($executed)
//         ], 900);

//         return $executed;
//     }

//     $ids = $executed->pluck('id')->all();
//     Cache::tags(['products_search'])->put($cacheKey, [
//         'ids'   => $ids,
//         'total' => $executed->total(),
//         'page'  => $page,
//         'limit' => $limit
//     ], 900);

//     return $executed;
// }

public function searchFromDatabaseKeneta(array $params = [])
{
    // Build a stable cache key based on the parameters.
    $cacheKey = 'search_ids:' . md5(json_encode($params));

    $paginationAll = isset($params['pagination']) && (int) $params['pagination'] === 0;
    $page  = max(1, (int) ($params['page']  ?? request('page', 1)));
    $limit = max(1, (int) ($params['limit'] ?? 12));

    // List of relations to eager load.  Adjusting this array in one place
    // simplifies later tuning or limiting to specific columns.
    $relations = [
        'attribute_family',
        'images',
        'videos',
        'attribute_values',
        'price_indices',
        'inventory_indices',
        'reviews',
        'variants',
        'variants.attribute_family',
        'variants.attribute_values',
        'variants.price_indices',
        'variants.inventory_indices',
    ];

    // Attempt to return from cache.  Cast IDs to integers to ensure proper binding.
    $cached = Cache::tags(['products_search'])->get($cacheKey);
    if ($cached) {
        $ids = array_map('intval', $cached['ids'] ?? []);

        if ($paginationAll) {
            return $this->model
                ->with($relations)
                ->whereIn('products.id', $ids)
                ->get();
        }

        if ($ids) {
            // Use comma‑separated list for FIELD() ordering; all values are ints.
            $idsCsv = implode(',', $ids);
            $items  = $this->model
                ->with($relations)
                ->whereIn('products.id', $ids)
                // Parameter binding here avoids SQL injection on FIELD().
                ->orderByRaw("FIELD(products.id, $idsCsv)")
                ->get();

            return new LengthAwarePaginator(
                $items,
                $cached['total'],
                $limit,
                $page,
                ['path' => request()->url(), 'query' => $params]
            );
        }
    }

    // Normalize search input – if 'q' is provided, use it as 'name'.
    if (! empty($params['q']) && empty($params['name'])) {
        $params['name'] = $params['q'];
    }

    // Default filters: treat empty keys as null and cast booleans to ints.
    $params['url_key']            = $params['url_key']            ?? null;
    $params['status']             = array_key_exists('status', $params) ? (int) $params['status'] : 1;
    $params['visible_individually'] = array_key_exists('visible_individually', $params) ? (int) $params['visible_individually'] : 1;

    $sort  = $params['sort']  ?? 'created_at';
    $order = strtolower($params['order'] ?? 'desc');
    $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

    // Set the current page for the paginator when not retrieving all items.
    if (! $paginationAll) {
        Paginator::currentPageResolver(fn () => $page);
    }

    // Resolve category slug to ID via caching to avoid repeated lookups.
    if (! empty($params['category_slug']) && empty($params['category_id'])) {
        try {
            $categoryId = Cache::remember(
                'category_slug:' . $params['category_slug'],
                300,
                function () use ($params) {
                    $catRepo = app(\Webkul\Category\Repositories\CategoryRepository::class);
                    $category = method_exists($catRepo, 'findBySlug')
                        ? $catRepo->findBySlug($params['category_slug'])
                        : $catRepo->findOneByField('slug', $params['category_slug']);
                    return $category ? (int) $category->id : null;
                }
            );
            if ($categoryId) {
                $params['category_id'] = $categoryId;
            }
        } catch (\Throwable $e) {
            // Log and swallow to preserve existing behavior:contentReference[oaicite:2]{index=2}.
            \Log::error('Error resolving category slug: ' . $e->getMessage());
        }
    }

    // Convert attribute slugs to option IDs.  Caching individual mappings can further
    // reduce database traffic when the same filters are frequently applied.
    if (! empty($params['attributes_slug']) && is_array($params['attributes_slug'])) {
        try {
            $optionRepo = app(\Webkul\Attribute\Repositories\AttributeOptionRepository::class);
            foreach ($params['attributes_slug'] as $code => $slugs) {
                $slugsArr = is_array($slugs) ? $slugs : explode(',', (string) $slugs);
                $attribute = $this->attributeRepository->findOneByField('code', $code);
                if (! $attribute) {
                    continue;
                }
                // Key the cache by attribute code and requested values.
                $cacheKeyOpts = 'attr_opts:' . $code . ':' . md5(json_encode($slugsArr));
                $optionIds = Cache::remember(
                    $cacheKeyOpts,
                    300,
                    function () use ($optionRepo, $attribute, $slugsArr) {
                        return $optionRepo->model
                            ->newQuery()
                            ->where('attribute_id', $attribute->id)
                            ->whereIn('admin_name', array_map('strval', $slugsArr))
                            ->pluck('id')
                            ->all();
                    }
                );
                if ($optionIds) {
                    foreach ($optionIds as $id) {
                        $params['attributes'][$code][] = (int) $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::error('Error resolving attribute slugs: ' . $e->getMessage());
        }
    }

    // Determine the set of attribute codes that will be used.
    $candidateCodes = array_keys($params);
    if (! empty($params['attributes']) && is_array($params['attributes'])) {
        $candidateCodes = array_values(array_unique(array_merge(
            $candidateCodes,
            array_keys($params['attributes'])
        )));
    }

    // Always require these codes for filters; cast to ints above.
    $requiredCodes  = ['name', 'status', 'visible_individually', 'url_key'];
    $candidateCodes = array_values(array_unique(array_merge($candidateCodes, $requiredCodes, [$sort])));

    // Preload attribute metadata for all possible codes.
    $attrCollection = $this->attributeRepository
        ->getProductDefaultAttributes($candidateCodes)
        ->keyBy('code');

    // Helper to return metadata for a code.
    $getAttr = fn (string $code) => $attrCollection->get($code);

    // Build facet values for filters.
    $facetValues = [];
    foreach ($attrCollection as $code => $meta) {
        if (in_array($code, ['price', 'name', 'status', 'visible_individually', 'url_key'], true)) {
            continue;
        }
        $vals = [];

        if (isset($params['attributes'][$code])) {
            $v = $params['attributes'][$code];
            $vals = is_array($v) ? $v : explode(',', (string) $v);
        }
        if (array_key_exists($code, $params)) {
            $v = $params[$code];
            $vals = array_merge(
                $vals,
                is_array($v) ? $v : explode(',', (string) $v)
            );
        }
        $vals = array_values(array_unique(array_filter(array_map('strval', $vals), 'strlen')));
        if ($vals) {
            $facetValues[$code] = $vals;
        }
    }

    $sortAttr = $getAttr($sort);

    // Determine whether the query needs joins.  Avoiding unnecessary joins can speed up queries.
    $hasFacets   = ! empty($facetValues);
    $hasFilters  = ! empty($params['name'])
                || ! empty($params['price'])
                || ! empty($params['category_id'])
                || ! empty($params['channel_id'])
                || ! empty($params['in_stock'])
                || (isset($params['promotion_id']) && $params['promotion_id'] !== '');
    $needsJoinForSort = ($sort === 'price') || (bool) $sortAttr;

    // Short‑circuit when no facets, filters, or sort joins are present.  Use a minimal
    // select list to reduce memory consumption:contentReference[oaicite:3]{index=3}.
    if (! $paginationAll && ! $hasFacets && ! $hasFilters && ! $needsJoinForSort) {
        $reqAttrs   = $this->attributeRepository->getProductDefaultAttributes($requiredCodes)->keyBy('code');
        $statusAttr = $reqAttrs->get('status');
        $visAttr    = $reqAttrs->get('visible_individually');
        $urlAttr    = $reqAttrs->get('url_key');

        $idsPage = $this->model
            ->newQuery()
            ->from('products')
            ->select('products.id')
            ->when($statusAttr, function ($q) use ($statusAttr) {
                $q->whereExists(function ($s) use ($statusAttr) {
                    $s->selectRaw('1')->from('product_attribute_values as pav_s')
                      ->whereColumn('pav_s.product_id', 'products.id')
                      ->where('pav_s.attribute_id', $statusAttr->id)
                      ->where("pav_s.{$statusAttr->column_name}", 1);
                });
            })
            ->when($visAttr, function ($q) use ($visAttr) {
                $q->whereExists(function ($s) use ($visAttr) {
                    $s->selectRaw('1')->from('product_attribute_values as pav_v')
                      ->whereColumn('pav_v.product_id', 'products.id')
                      ->where('pav_v.attribute_id', $visAttr->id)
                      ->where("pav_v.{$visAttr->column_name}", 1);
                });
            })
            ->when($urlAttr, function ($q) use ($urlAttr) {
                $q->whereExists(function ($s) use ($urlAttr) {
                    $s->selectRaw('1')->from('product_attribute_values as pav_u')
                      ->whereColumn('pav_u.product_id', 'products.id')
                      ->where('pav_u.attribute_id', $urlAttr->id)
                      ->whereNotNull("pav_u.{$urlAttr->column_name}");
                });
            })
            ->orderBy(
                in_array($sort, ['id','sku','created_at','updated_at'], true)
                    ? "products.$sort"
                    : 'products.created_at',
                $order
            )
            ->paginate($limit, ['products.id'], 'page', $page);

        $ids = collect($idsPage->items())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $store = [
            'ids'   => $ids,
            'total' => $idsPage->total(),
            'page'  => $page,
            'limit' => $limit,
        ];
        // Cache IDs and pagination metadata for subsequent calls.
        Cache::tags(['products_search'])->put($cacheKey, $store, 900);

        if ($ids) {
            $idsCsv = implode(',', $ids);
            $items  = $this->model
                ->with($relations)
                ->whereIn('products.id', $ids)
                ->orderByRaw("FIELD(products.id, $idsCsv)")
                ->get();

            return new LengthAwarePaginator(
                $items,
                $idsPage->total(),
                $limit,
                $page,
                ['path' => request()->url(), 'query' => $params]
            );
        }

        return new LengthAwarePaginator([], 0, $limit, $page, ['path' => request()->url(), 'query' => $params]);
    }

    // Build the dynamic query using the repository's scopeQuery API.
    $query = $this->with($relations)->scopeQuery(function ($query) use (
        $params,
        $facetValues,
        $sort,
        $order,
        $getAttr,
        $sortAttr
    ) {
        $qb = $query->select('products.*');

        // Filter by numeric primary key or SKU.
        if (! empty($params['id'])) {
            $qb->where('products.id', (int) $params['id']);
        }
        if (! empty($params['sku'])) {
            $qb->where('products.sku', $params['sku']);
        }

        // Promotion filter: cast values to ints.
        if (isset($params['promotion_id']) && $params['promotion_id'] !== '') {
            $promoIds = array_map('intval', explode(',', (string) $params['promotion_id']));
            $qb->whereIn('products.promotion_id', $promoIds);
        }

        // Category filter.  Adding an index on product_categories.product_id and category_id improves lookup speed:contentReference[oaicite:4]{index=4}.
        if (! empty($params['category_id'])) {
            $catIds = array_map('intval', explode(',', (string) $params['category_id']));
            $qb->whereExists(function ($s) use ($catIds) {
                $s->selectRaw('1')
                  ->from('product_categories')
                  ->whereColumn('product_categories.product_id', 'products.id')
                  ->whereIn('product_categories.category_id', $catIds);
            });
        }

        // Channel filter.  Index product_channels.product_id and channel_id to speed up this exists() query:contentReference[oaicite:5]{index=5}.
        if (! empty($params['channel_id'])) {
            $chan = array_map('intval', explode(',', (string) $params['channel_id']));
            $qb->whereExists(function ($s) use ($chan) {
                $s->selectRaw('1')
                  ->from('product_channels')
                  ->whereColumn('product_channels.product_id', 'products.id')
                  ->whereIn('product_channels.channel_id', $chan);
            });
        }

        // In‑stock filter: two exists checks for simple products and variants.
        if (! empty($params['in_stock'])) {
            $qb->where(function ($outer) {
                $outer->whereExists(function ($s) {
                    $s->selectRaw('1')
                      ->from('product_inventory_indices as pii')
                      ->whereColumn('pii.product_id', 'products.id')
                      ->where('pii.qty', '>', 0);
                })
                ->orWhereExists(function ($s) {
                    $s->selectRaw('1')
                      ->from('products as v')
                      ->join('product_inventory_indices as pii', 'pii.product_id', '=', 'v.id')
                      ->whereColumn('v.parent_id', 'products.id')
                      ->where('pii.qty', '>', 0);
                });
            });
        }

        // Status filter
        if (($a = $getAttr('status')) && isset($params['status'])) {
            $qb->whereExists(function ($s) use ($a, $params) {
                $s->selectRaw('1')
                  ->from('product_attribute_values as pav_status')
                  ->whereColumn('pav_status.product_id', 'products.id')
                  ->where('pav_status.attribute_id', $a->id)
                  ->where("pav_status.{$a->column_name}", $params['status']);
            });
        }

        // Visible individually filter
        if (($a = $getAttr('visible_individually')) && isset($params['visible_individually'])) {
            $qb->whereExists(function ($s) use ($a, $params) {
                $s->selectRaw('1')
                  ->from('product_attribute_values as pav_vis')
                  ->whereColumn('pav_vis.product_id', 'products.id')
                  ->where('pav_vis.attribute_id', $a->id)
                  ->where("pav_vis.{$a->column_name}", $params['visible_individually']);
            });
        }

        // Name filter with synonyms.  Synonyms are cached to avoid repeated lookups.
        if (! empty($params['name']) && ($a = $getAttr('name'))) {
            $synonyms = Cache::remember(
                'search_syn:' . md5(urldecode($params['name'])),
                300,
                function () use ($params) {
                    return $this->searchSynonymRepository
                        ->getSynonymsByQuery(urldecode($params['name'])) ?? [];
                }
            );
            $synonyms = array_slice(array_values(array_unique($synonyms)), 0, 10);
            if ($synonyms) {
                $qb->where(function ($outer) use ($a, $synonyms) {
                    $outer->whereExists(function ($s) use ($a, $synonyms) {
                        $s->selectRaw('1')
                          ->from('product_attribute_values as pav_name')
                          ->whereColumn('pav_name.product_id', 'products.id')
                          ->where('pav_name.attribute_id', $a->id)
                          ->where(function ($w) use ($a, $synonyms) {
                              foreach ($synonyms as $t) {
                                  $w->orWhere("pav_name.{$a->column_name}", 'like', "%{$t}%");
                              }
                          });
                    })
                    ->orWhereExists(function ($s) use ($a, $synonyms) {
                        $s->selectRaw('1')
                          ->from('products as v')
                          ->join('product_attribute_values as vav_name', 'vav_name.product_id', '=', 'v.id')
                          ->whereColumn('v.parent_id', 'products.id')
                          ->where('vav_name.attribute_id', $a->id)
                          ->where(function ($w) use ($a, $synonyms) {
                              foreach ($synonyms as $t) {
                                  $w->orWhere("vav_name.{$a->column_name}", 'like', "%{$t}%");
                              }
                          });
                    });
                });
            }
        }

        // URL key filter – ensures only products with a populated url_key by default.
        if (($a = $getAttr('url_key'))) {
            if (empty($params['url_key'])) {
                $qb->whereExists(function ($s) use ($a) {
                    $s->selectRaw('1')
                      ->from('product_attribute_values as pav_url')
                      ->whereColumn('pav_url.product_id', 'products.id')
                      ->where('pav_url.attribute_id', $a->id)
                      ->whereNotNull("pav_url.{$a->column_name}");
                });
            } else {
                $needle = '%' . urldecode($params['url_key']) . '%';
                $qb->whereExists(function ($s) use ($a, $needle) {
                    $s->selectRaw('1')
                      ->from('product_attribute_values as pav_url')
                      ->whereColumn('pav_url.product_id', 'products.id')
                      ->where('pav_url.attribute_id', $a->id)
                      ->where("pav_url.{$a->column_name}", 'like', $needle);
                });
            }
        }

        // Apply facet filters on dynamic attributes.
        foreach ($facetValues as $code => $values) {
            $a = $getAttr($code);
            if (! $a) {
                continue;
            }
            $qb->where(function ($outer) use ($a, $values) {
                $outer->whereExists(function ($s) use ($a, $values) {
                    $s->selectRaw('1')
                      ->from('product_attribute_values as pav_f')
                      ->whereColumn('pav_f.product_id', 'products.id')
                      ->where('pav_f.attribute_id', $a->id)
                      ->whereIn("pav_f.{$a->column_name}", $values);
                })
                ->orWhereExists(function ($s) use ($a, $values) {
                    $s->selectRaw('1')
                      ->from('products as v')
                      ->join('product_attribute_values as vav_f', 'vav_f.product_id', '=', 'v.id')
                      ->whereColumn('v.parent_id', 'products.id')
                      ->where('vav_f.attribute_id', $a->id)
                      ->whereIn("vav_f.{$a->column_name}", $values);
                });
            });
        }

        // Price range filter; convert to base price and index by customer group.
        if (! empty($params['price'])) {
            $range = explode(',', (string) $params['price']);
            $min = core()->convertToBasePrice((float) current($range));
            $max = core()->convertToBasePrice((float) end($range));
            $qb->whereExists(function ($s) use ($min, $max) {
                $customerGroup = $this->customerRepository->getCurrentGroup();
                $s->selectRaw('1')
                  ->from('product_price_indices as ppi_f')
                  ->whereColumn('ppi_f.product_id', 'products.id')
                  ->where('ppi_f.customer_group_id', $customerGroup->id)
                  ->whereBetween('ppi_f.min_price', [$min, $max]);
            });
        }

        // Sorting.  Sorting by price requires a join to the price index table.
        if ($sort === 'price') {
            $qb->leftJoin('product_price_indices as ppi_s', function ($join) {
                $customerGroup = $this->customerRepository->getCurrentGroup();
                $join->on('products.id', '=', 'ppi_s.product_id')
                     ->where('ppi_s.customer_group_id', $customerGroup->id);
            })->orderBy('ppi_s.min_price', $order);
        } else {
            if ($sortAttr) {
                // When sorting by a custom attribute, join the product_attribute_values table.
                $qb->leftJoin('product_attribute_values as sort_pav', function ($join) use ($sortAttr) {
                    $join->on('products.id', '=', 'sort_pav.product_id')
                         ->where('sort_pav.attribute_id', $sortAttr->id);
                    if (! empty($sortAttr->value_per_channel)) {
                        $join->where('sort_pav.channel', core()->getRequestedChannelCode());
                    }
                    if (! empty($sortAttr->value_per_locale)) {
                        $join->where('sort_pav.locale', core()->getRequestedLocaleCode());
                    }
                })->orderBy("sort_pav.{$sortAttr->column_name}", $order);
            } else {
                // Safe list for direct product columns; other columns default to created_at.
                $allowed = ['id', 'sku', 'promotion_id', 'created_at', 'updated_at'];
                $col = in_array($sort, $allowed, true) ? $sort : 'created_at';
                $qb->orderBy("products.$col", $order);
            }
        }

        return $qb;
    });

    // Execute the query.
    $executed = $paginationAll ? $query->get() : $query->paginate($limit);

    if ($paginationAll) {
        // Cache all IDs for future identical calls.
        Cache::tags(['products_search'])->put($cacheKey, [
            'ids'   => $executed->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'total' => count($executed),
            'page'  => 1,
            'limit' => count($executed),
        ], 900);

        return $executed;
    }

    // Cache paginated IDs.
    $ids = $executed->pluck('id')->map(fn ($v) => (int) $v)->all();
    Cache::tags(['products_search'])->put($cacheKey, [
        'ids'   => $ids,
        'total' => $executed->total(),
        'page'  => $page,
        'limit' => $limit,
    ], 900);

    return $executed;
}

















    /**
     * Search product from elastic search.
     *
     * @return \Illuminate\Support\Collection
     */
    public function searchFromElastic(array $params = [])
    {
        $currentPage = Paginator::resolveCurrentPage('page');

        $limit = $this->getPerPageLimit($params);

        $sortOptions = $this->getSortOptions($params);

        $indices = $this->elasticSearchRepository->search($params, [
            'from'  => ($currentPage * $limit) - $limit,
            'limit' => $limit,
            'sort'  => $sortOptions['sort'],
            'order' => $sortOptions['order'],
        ]);

        $query = $this->with([
            'attribute_family',
            'images',
            'videos',
            'attribute_values',
            'price_indices',
            'inventory_indices',
            'reviews',
            'variants',
            'variants.attribute_family',
            'variants.attribute_values',
            'variants.price_indices',
            'variants.inventory_indices',
        ])->scopeQuery(function ($query) use ($params, $indices) {
            $qb = $query->distinct()
                ->whereIn('products.id', $indices['ids']);

            if (
                ! empty($params['type'])
                && $params['type'] === 'simple'
                && ! empty($params['exclude_customizable_products'])
            ) {
                $qb->leftJoin('product_customizable_options', 'products.id', '=', 'product_customizable_options.product_id')
                    ->whereNull('product_customizable_options.id');
            }

            $qb->orderBy(DB::raw('FIELD(id, '.implode(',', $indices['ids']).')'));

            return $qb;
        });

        $items = $indices['total'] ? $query->get() : [];

        $results = new LengthAwarePaginator($items, $indices['total'], $limit, $currentPage, [
            'path'  => request()->url(),
            'query' => $params,
        ]);

        return $results;
    }

    /**
     * Fetch per page limit from toolbar helper. Adapter for this repository.
     */
    public function getPerPageLimit(array $params): int
    {
        return product_toolbar()->getLimit($params);
    }

    /**
     * Fetch sort option from toolbar helper. Adapter for this repository.
     */
    public function getSortOptions(array $params): array
    {
        return product_toolbar()->getOrder($params);
    }

    /**
     * Returns product's super attribute with options.
     *
     * @param  \Webkul\Product\Contracts\Product  $product
     * @return \Illuminate\Support\Collection
     */
    public function getSuperAttributes($product)
    {
        $superAttributes = [];

        foreach ($product->super_attributes as $key => $attribute) {
            $superAttributes[$key] = $attribute->toArray();

            foreach ($attribute->options as $option) {
                $superAttributes[$key]['options'][] = [
                    'id'           => $option->id,
                    'admin_name'   => $option->admin_name,
                    'sort_order'   => $option->sort_order,
                    'swatch_value' => $option->swatch_value,
                ];
            }
        }

        return $superAttributes;
    }

    /**
     * Return category product maximum price.
     *
     * @param  int  $categoryId
     * @return float
     */
    public function getMaxPrice($params = [])
    {
        if ($this->searchEngine == 'elastic') {
            return $this->elasticSearchRepository->getMaxPrice($params);
        }

        $customerGroup = $this->customerRepository->getCurrentGroup();

        $query = $this->model
            ->leftJoin('product_price_indices', 'products.id', 'product_price_indices.product_id')
            ->leftJoin('product_categories', 'products.id', 'product_categories.product_id')
            ->where('product_price_indices.customer_group_id', $customerGroup->id);

        if (! empty($params['category_id'])) {
            $query->where('product_categories.category_id', $params['category_id']);
        }

        return $query->max('min_price') ?? 0;
    }
}
