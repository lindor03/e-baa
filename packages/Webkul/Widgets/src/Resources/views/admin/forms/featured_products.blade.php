<div class="border rounded p-3">

    <label class="block text-sm font-medium">Search Products</label>
    <input
        type="text"
        id="productSearch"
        class="w-full border p-2 rounded"
        placeholder="Search products..."
        autocomplete="off"
    >

    <div
        id="productResults"
        class="border p-2 mt-1 hidden rounded bg-white max-h-[200px] overflow-auto"
    ></div>

    <div id="productSelected" class="mt-3">
        @php
            // Backward compatible: supports both [1,2,3] and [[1],[2],[3]]
            $productIds = collect($widget->config['products'] ?? [])
                ->flatten()
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->values();
        @endphp

        @foreach ($productIds as $pid)
            @php
                $p = \Webkul\Product\Models\ProductFlat::where('product_id', $pid)->first();
            @endphp

            @if ($p)
                <div
                    class="product-tag featured-product-item p-1 bg-blue-200 mb-1 rounded flex justify-between items-center gap-2"
                    data-product-id="{{ $pid }}"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" class="fp-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                            <button type="button" class="fp-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                        </div>

                        <span class="truncate">{{ $p->name }}</span>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                        <input type="hidden" name="config[products][]" value="{{ $pid }}">
                    </div>
                </div>
            @endif
        @endforeach
    </div>

</div>
