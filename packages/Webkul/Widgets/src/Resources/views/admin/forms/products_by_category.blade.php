{{-- packages/Webkul/Widgets/src/Resources/views/admin/forms/products_by_category.blade.php --}}

<div class="border rounded p-3">

    <label class="block text-sm font-medium">Search Categories</label>
    <input
        type="text"
        id="categorySearch"
        class="w-full border p-2 rounded"
        placeholder="Search categories..."
        autocomplete="off"
    >

    <div
        id="categoryResults"
        class="border p-2 mt-1 hidden rounded bg-white max-h-[200px] overflow-auto"
    ></div>

    {{-- Canonical selected categories (ORDER MATTERS) --}}
    <div id="categoryIdsHidden">
        @php
            $catsFromProducts = array_keys($widget->config['products_by_category'] ?? []);

            $catOrder = $widget->config['category_id'] ?? [];
            $catOrder = is_array($catOrder) ? array_values(array_unique(array_map('intval', $catOrder))) : [];

            $catsFromProducts = array_values(array_unique(array_map('intval', $catsFromProducts)));

            // category_id order first, then append any missing categories that have products
            $missing = array_values(array_diff($catsFromProducts, $catOrder));

            $selectedCatIds = array_values(array_merge($catOrder, $missing));
        @endphp

        @foreach ($selectedCatIds as $cid)
            <input
                type="hidden"
                name="config[category_id][]"
                value="{{ $cid }}"
                data-category-id-hidden="{{ $cid }}"
            >
        @endforeach
    </div>

    <div id="categorySelected" class="mt-3">
        @foreach ($selectedCatIds as $catId)
            @php
                $catName = \Webkul\Category\Models\CategoryTranslation::where('category_id', $catId)
                    ->where('locale', app()->getLocale())
                    ->value('name') ?? "Category $catId";

                $productIds = $widget->config['products_by_category'][$catId] ?? [];
            @endphp

            <div
                class="border p-2 mb-4 rounded bg-green-50"
                data-category="{{ $catId }}"
                id="category-block-{{ $catId }}"
            >
                {{-- Header + reorder + remove --}}
                <div class="flex items-center justify-between mb-2">
                    <div class="font-semibold text-green-900">
                        {{ $catName }} (ID: {{ $catId }})
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" class="cat-move-up text-xs px-2 py-1 border rounded">↑</button>
                        <button type="button" class="cat-move-down text-xs px-2 py-1 border rounded">↓</button>

                        {{-- ✅ NEW: remove category --}}
                        <button
                            type="button"
                            class="cat-remove text-xs px-2 py-1 border rounded text-red-700 font-bold"
                            title="Remove category"
                        >✕</button>
                    </div>
                </div>

                <button
                    type="button"
                    class="addAllProductsForCategory text-xs p-1 bg-green-500 text-white rounded mb-2"
                    data-category-id="{{ $catId }}"
                >
                    Add ALL products
                </button>

                {{-- Products list (ORDER MATTERS) --}}
                <div class="pl-2" id="category-products-{{ $catId }}">
                    @foreach ($productIds as $pid)
                        @php
                            $p = \Webkul\Product\Models\ProductFlat::where('product_id', $pid)->first();
                        @endphp

                        @if ($p)
                            <div
                                class="product-tag p-1 bg-green-200 mb-1 rounded flex justify-between items-center"
                                data-product-id="{{ (int) $pid }}"
                                data-category-id="{{ (int) $catId }}"
                            >
                                <div class="flex items-center gap-2">
                                    <button type="button" class="cat-prod-move-up text-xs px-2 py-1 border rounded">↑</button>
                                    <button type="button" class="cat-prod-move-down text-xs px-2 py-1 border rounded">↓</button>
                                    <span>{{ $p->name }}</span>
                                </div>

                                <div class="flex items-center gap-2">
                                    <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                                    <input
                                        type="hidden"
                                        name="config[products_by_category][{{ $catId }}][]"
                                        value="{{ (int) $pid }}"
                                    >
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>

                <input
                    type="text"
                    class="border mt-2 p-1 rounded w-full categoryProductSearch"
                    placeholder="Search products to add..."
                    data-category-id="{{ $catId }}"
                    autocomplete="off"
                >

                <div class="categoryProductResults border hidden bg-white rounded mt-1 max-h-40 overflow-auto"></div>
            </div>
        @endforeach
    </div>
</div>
