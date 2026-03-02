<div class="border rounded p-3">

    {{-- Attribute --}}
    <label class="block text-sm font-medium">Select Attribute</label>

    @php
        $attrId = old('config.attribute_id', $widget->config['attribute_id'] ?? '');
    @endphp

    <select
        id="attrListAttributeSelect"
        class="w-full border p-2 rounded mb-2"
        data-selected="{{ $attrId }}"
    ></select>

    <input
        type="hidden"
        id="attrListAttributeIdInput"
        name="config[attribute_id]"
        value="{{ $attrId }}"
    >

    {{-- Search options --}}
    <label class="block text-sm font-medium">Search Attribute Options</label>
    <input
        type="text"
        id="attrListOptionSearch"
        class="w-full border p-2 rounded"
        placeholder="Type to filter options..."
        autocomplete="off"
    >

    {{-- Results --}}
    <div
        id="attrListOptionResults"
        class="border p-2 mt-1 hidden rounded bg-white max-h-[200px] overflow-auto"
    ></div>

    {{-- Selected tags --}}
    <div class="mt-3">
        <div class="text-sm font-medium mb-2">Selected Options (use arrows to reorder)</div>

        <div id="attrListSelected">
            @php
                $selectedOptionIds = old('config.attribute_option_id', $widget->config['attribute_option_id'] ?? []);
                $selectedOptionIds = is_array($selectedOptionIds) ? $selectedOptionIds : [];
            @endphp

            @foreach ($selectedOptionIds as $oid)
                @php
                    $oid = (int) $oid;
                    $optName = \Webkul\Attribute\Models\AttributeOption::where('id', $oid)
                        ->value('admin_name') ?? "Option $oid";
                @endphp

                <div
                    class="product-tag attr-list-item p-1 bg-blue-200 mb-1 rounded flex justify-between items-center gap-2"
                    data-option-id="{{ $oid }}"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" class="attrlist-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                            <button type="button" class="attrlist-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                        </div>

                        <span class="truncate">{{ $optName }}</span>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" class="text-red-700 text-xs font-bold" onclick="removeAttrListOptionTag(this)">✕</button>

                        {{-- IMPORTANT: keep the hidden input INSIDE the tag so reordering persists --}}
                        <input
                            type="hidden"
                            name="config[attribute_option_id][]"
                            value="{{ $oid }}"
                            data-option-id-hidden="{{ $oid }}"
                        >
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</div>
