<div class="border rounded p-3">

    <label class="block text-sm font-medium">Search Categories</label>
    <input
        type="text"
        id="categoryListSearch"
        class="w-full border p-2 rounded"
        placeholder="Search categories..."
        autocomplete="off"
    >

    <div
        id="categoryListResults"
        class="border p-2 mt-1 hidden rounded bg-white max-h-[200px] overflow-auto"
    ></div>

    <div class="mt-3">
        <div class="text-sm font-medium mb-2">Selected Categories (use arrows to reorder)</div>

        <div id="categoryListSelected">
            @php
                $selectedIds = old('config.category_id', $widget->config['category_id'] ?? []);
                $selectedIds = is_array($selectedIds) ? array_values(array_unique(array_map('intval', $selectedIds))) : [];
            @endphp

            @foreach ($selectedIds as $catId)
                @php
                    $catName = \Webkul\Category\Models\CategoryTranslation::where('category_id', (int) $catId)
                        ->where('locale', app()->getLocale())
                        ->value('name') ?? "Category $catId";
                @endphp

                <div
                    class="product-tag category-list-item p-1 bg-green-200 mb-1 rounded flex justify-between items-center gap-2"
                    data-category="{{ (int) $catId }}"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" class="catlist-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                            <button type="button" class="catlist-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                        </div>

                        <span class="truncate">{{ $catName }}</span>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" class="text-red-700 text-xs font-bold" onclick="removeCategoryListTag(this)">✕</button>

                        {{-- IMPORTANT: hidden input lives inside the tag so order persists --}}
                        <input
                            type="hidden"
                            name="config[category_id][]"
                            value="{{ (int) $catId }}"
                            data-category-id-hidden="{{ (int) $catId }}"
                        >
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</div>
