<?php

namespace Webkul\CustomPromotions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\CustomPromotions\Http\Requests\CustomPromotionRequest;
use Webkul\CustomPromotions\Jobs\ApplyPromotionsJob;
use Webkul\CustomPromotions\Models\Promotion;
use Webkul\CustomPromotions\Repositories\PromotionRepository;
use Illuminate\Support\Facades\Storage;

class CustomPromotionsController extends Controller
{
    public function __construct(protected PromotionRepository $promotions) {}

    public function index(): View
    {
        return view('custompromotions::admin.index', [
            'items' => Promotion::query()->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('custompromotions::admin.create');
    }

    public function store(CustomPromotionRequest $request)
    {
        Event::dispatch('custompromotions.create.before');

        $promotion = $this->promotions->create($request->validated());
        $this->handleUploads($promotion, $request);

        Event::dispatch('custompromotions.create.after', $promotion);

        session()->flash('success', 'Promotion created.');
        return redirect()->route('admin.custompromotions.index');
    }

    public function edit(Promotion $promotion): View
    {
        $promotion->load(['products:id,sku']);
        return view('custompromotions::admin.edit', compact('promotion'));
    }

    public function update(CustomPromotionRequest $request, Promotion $promotion)
    {
        Event::dispatch('custompromotions.update.before', $promotion->id);

        $promotion = $this->promotions->update($promotion, $request->validated());
        $this->handleUploads($promotion, $request);

        Event::dispatch('custompromotions.update.after', $promotion);

        session()->flash('success', 'Promotion updated.');
        return redirect()->route('admin.custompromotions.index');
    }

    public function destroy(Promotion $promotion)
    {
        try {
            Event::dispatch('custompromotions.delete.before', $promotion->id);

            $promotion->delete();

            Event::dispatch('custompromotions.delete.after', $promotion->id);

            session()->flash('success', 'Promotion deleted.');
            return redirect()->route('admin.custompromotions.index');

        } catch (\Throwable $e) {
            session()->flash('error', 'Delete failed.');
            return redirect()->route('admin.custompromotions.index');
        }
    }


    public function apply(): JsonResponse
    {
        dispatch_sync(new ApplyPromotionsJob());
        return response()->json(['message' => 'Promotions applied.']);
    }

    /**
     * Robust product search: by id, sku, name (from product_flat), limited.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $q     = trim((string) $request->get('q', ''));
        $limit = min((int) $request->get('limit', 10), 50);

        $builder = DB::table('products as p')
            ->leftJoin('product_flat as pf', 'pf.product_id', '=', 'p.id')
            ->select('p.id', 'p.sku', DB::raw('MAX(pf.name) as name'))
            ->groupBy('p.id', 'p.sku')
            ->orderByDesc('p.id');

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->orWhere('p.id', $q)
                  ->orWhere('p.sku', 'like', "%{$q}%")
                  ->orWhere('pf.name', 'like', "%{$q}%");
            });
        }

        $rows = $builder->limit($limit)->get();

        return response()->json($rows->map(function ($r) {
            return [
                'id'   => (int) $r->id,
                'sku'  => $r->sku,
                'name' => $r->name ?: $r->sku,
            ];
        }));
    }

    /**
     * Fetch products by category ids (bulk add support).
     * GET ?category_ids[]=1&category_ids[]=2&limit=100
     */
    public function searchProductsByCategories(Request $request): JsonResponse
    {
        $categoryIds = array_filter(array_map('intval', (array) $request->get('category_ids', [])));
        $limit       = min((int) $request->get('limit', 100), 500);

        if (empty($categoryIds)) {
            return response()->json([]);
        }

        // Bagisto pivot is product_categories (product_id, category_id)
        $productIds = DB::table('product_categories')
            ->whereIn('category_id', $categoryIds)
            ->pluck('product_id')
            ->unique()
            ->take($limit)
            ->values();

        if ($productIds->isEmpty()) {
            return response()->json([]);
        }

        $rows = DB::table('products as p')
            ->leftJoin('product_flat as pf', 'pf.product_id', '=', 'p.id')
            ->whereIn('p.id', $productIds)
            ->select('p.id', 'p.sku', DB::raw('MAX(pf.name) as name'))
            ->groupBy('p.id', 'p.sku')
            ->orderBy('p.id')
            ->get();

        return response()->json($rows->map(function ($r) {
            return [
                'id'   => (int) $r->id,
                'sku'  => $r->sku,
                'name' => $r->name ?: $r->sku,
            ];
        }));
    }

    protected function handleUploads(Promotion $promotion, Request $request): void
    {
        // Remove flags
        if ($request->boolean('remove_banner') && $promotion->banner_path) {
            Storage::disk('public')->delete($promotion->banner_path);
            $promotion->banner_path = null;
        }

        if ($request->boolean('remove_logo') && $promotion->logo_path) {
            Storage::disk('public')->delete($promotion->logo_path);
            $promotion->logo_path = null;
        }

        // New files
        if ($request->file('banner')) {
            if ($promotion->banner_path) {
                Storage::disk('public')->delete($promotion->banner_path);
            }
            $promotion->banner_path = $request->file('banner')->store('custompromotions/banners', 'public');
        }

        if ($request->file('logo')) {
            if ($promotion->logo_path) {
                Storage::disk('public')->delete($promotion->logo_path);
            }
            $promotion->logo_path = $request->file('logo')->store('custompromotions/logos', 'public');
        }

        // persist if any change
        if ($promotion->isDirty(['banner_path','logo_path'])) {
            $promotion->save();
        }
    }

}
