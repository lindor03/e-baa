<?php

namespace Webkul\CustomPromotions\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\CustomPromotions\Models\Promotion;
use Illuminate\Support\Arr;

class PromotionController extends Controller
{
    /**
     * GET /api/custom-promotions
     * Query params:
     *  - status=active|all (default: active)
     *  - with_products=0|1 (default: 0)
     *  - product_limit=int (default: 10)
     *  - channel=string (default: 'default' for product names)
     *  - locale=string|null
     *  - per_page=1..50 (default: 15)
     */


    public function index(Request $request): JsonResponse
    {
        $status        = $request->query('status', 'active');
        $withProducts  = $request->boolean('with_products', false);
        $productLimit  = (int) $request->query('product_limit', 10);
        $productLimit  = max(1, min($productLimit, 50));

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 50));

        $channel = $request->query('channel', 'default');
        $locale  = $request->query('locale');

        // sort param: sort_order (asc), -sort_order (desc), name, -name, newest, oldest
        $sort = $request->query('sort', 'sort_order');

        $query = Promotion::query()
            ->select(['id','name','slug','sort_order','from','to','is_active','banner_path','logo_path']);

        if ($status === 'active') {
            $query->activeNow();
        }

        if ($withProducts) {
            $query->with(['products:id,sku']);
        }

        // apply sorting
        switch ($sort) {
            case 'sort_order':
                $query->orderBy('sort_order')->orderByDesc('id');
                break;
            case '-sort_order':
                $query->orderByDesc('sort_order')->orderByDesc('id');
                break;
            case 'name':
                $query->orderBy('name')->orderByDesc('id');
                break;
            case '-name':
                $query->orderByDesc('name')->orderByDesc('id');
                break;
            case 'oldest':
                $query->orderBy('id'); // creation order
                break;
            case 'newest':
            default:
                $query->orderByDesc('id');
                break;
        }

        $paginated = $query->simplePaginate($perPage);

        $data = $paginated->getCollection()->map(function (Promotion $p) use ($withProducts, $productLimit, $channel, $locale) {
            $item = [
                'id'           => $p->id,
                'slug'         => $p->slug,
                'name'         => $p->name,
                'sort_order'   => $p->sort_order,
                'from'         => optional($p->from)?->toDateString(),
                'to'           => optional($p->to)?->toDateString(),
                'is_active'    => (bool) $p->is_active,
                'banner_url'   => $p->banner_url,
                'logo_url'     => $p->logo_url,
                'product_count'=> $withProducts
                    ? $p->products->count()
                    : (int) DB::table('custom_promotion_products')->where('promotion_id', $p->id)->count(),
            ];

            if ($withProducts) {
                $ids   = $p->products->pluck('id')->take($productLimit);
                $names = DB::table('product_flat')
                    ->whereIn('product_id', $ids)
                    ->when($channel, fn($q) => $q->where('channel', $channel))
                    ->when(!is_null($locale), fn($q) => $q->where('locale', $locale))
                    ->pluck('name', 'product_id');

                $item['products'] = $p->products
                    ->filter(fn($pr) => $ids->contains($pr->id))
                    ->map(function ($pr) use ($names) {
                        return [
                            'id'            => $pr->id,
                            'sku'           => $pr->sku,
                            'name'          => $names[$pr->id] ?? $pr->sku ?? ('#' . $pr->id),
                            'special_price' => (string) $pr->pivot->special_price,
                        ];
                    })->values();
            }

            return $item;
        });

        return response()->json([
            'data'  => $data,
            'links' => ['next' => $paginated->nextPageUrl(), 'prev' => $paginated->previousPageUrl()],
            'meta'  => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'has_more'     => $paginated->hasMorePages(),
                'sort'         => $sort,
            ],
        ]);
    }



    public function simpleList(Request $request): JsonResponse
    {
        $withProducts = $request->boolean('with_products', true);
        $productLimit = (int) $request->query('product_limit', 10);
        $productLimit = max(1, min($productLimit, 50));

        $channel = $request->query('channel', 'default');
        $locale  = $request->query('locale');

        $query = Promotion::query()
            ->select(['id','name','sort_order','is_active'])
            ->activeNow();                   // ✅ ONLY ACTIVE PROMOTIONS

        if ($withProducts) {
            $query->with(['products:id,sku']);
        }

        $promotions = $query->orderBy('sort_order')->get();

        $data = $promotions->map(function (Promotion $p) use ($withProducts, $productLimit, $channel, $locale) {
            $item = [
                'name'       => $p->name,
                'is_active'  => (bool) $p->is_active,
                'sort_order' => $p->sort_order,
            ];

            if ($withProducts) {
                $ids = $p->products->pluck('id')->take($productLimit);

                $names = DB::table('product_flat')
                    ->whereIn('product_id', $ids)
                    ->when($channel, fn($q) => $q->where('channel', $channel))
                    ->when(!is_null($locale), fn($q) => $q->where('locale', $locale))
                    ->pluck('name', 'product_id');

                $item['products'] = $p->products
                    ->filter(fn($pr) => $ids->contains($pr->id))
                    ->map(fn($pr) => [
                        'id'   => $pr->id,
                        'sku'  => $pr->sku,
                        'name' => $names[$pr->id] ?? $pr->sku ?? ('#'.$pr->id),
                    ])->values();
            }

            return $item;
        });

        return response()->json([
            'data' => $data
        ]);
    }


}
