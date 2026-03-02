<div class="border rounded p-3">

    {{-- Attribute --}}
    <label class="block text-sm font-medium">Select Attribute</label>

    {{-- Canonical submitted value --}}
    @php
        $attrId = (string) old('config.attribute_id', $widget->config['attribute_id'] ?? '');
    @endphp

    <input
        type="hidden"
        id="attributeIdInput"
        name="config[attribute_id]"
        value="{{ $attrId }}"
    >

    {{-- UI-only select (no name!) --}}
    <select
        id="attributeSelect"
        class="w-full border p-2 rounded mb-3"
        data-selected="{{ $attrId }}"
    ></select>

    {{-- Attribute Options --}}
    <label class="block text-sm font-medium">Select Attribute Options</label>

    @php
        $selectedOptionIds = old('config.attribute_option_id', $widget->config['attribute_option_id'] ?? []);
        $selectedOptionIds = is_array($selectedOptionIds) ? $selectedOptionIds : [];
    @endphp

    {{-- UI-only select (no name!) --}}
    <select
        id="attributeOptionSelect"
        class="w-full border p-2 rounded mb-2"
        multiple
        data-selected='@json($selectedOptionIds)'
    ></select>

    {{-- Ordered selected options = source of truth for submit --}}
    <div class="mt-2">
        <div class="text-sm font-medium mb-2">Selected Options (orderable)</div>

        <div id="attributeOptionOrder" class="space-y-2">
            @foreach ($selectedOptionIds as $oid)
                @php
                    $optName = \Webkul\Attribute\Models\AttributeOption::where('id', (int) $oid)
                        ->value('admin_name') ?? "Option $oid";
                @endphp

                <div
                    class="border rounded p-2 bg-white flex items-center justify-between gap-2"
                    data-order-option="{{ (int) $oid }}"
                >
                    <div class="flex items-center gap-2">
                        <button type="button" class="opt-move-up text-xs px-2 py-1 border rounded">↑</button>
                        <button type="button" class="opt-move-down text-xs px-2 py-1 border rounded">↓</button>

                        <div class="font-medium text-blue-900">{{ $optName }}</div>
                        <div class="text-xs text-gray-500">(ID: {{ (int) $oid }})</div>
                    </div>

                    <button type="button" class="opt-remove text-xs text-red-700 font-bold">✕</button>

                    <input type="hidden" name="config[attribute_option_id][]" value="{{ (int) $oid }}">
                </div>
            @endforeach
        </div>
    </div>

    {{-- Product blocks --}}
    <div id="attributeOptionsProducts" class="mt-4">
        @foreach (($widget->config['products'] ?? []) as $optionId => $productIds)
            @php
                $opt = \Webkul\Attribute\Models\AttributeOption::find($optionId);
            @endphp

            <div
                class="border p-2 mb-4 rounded bg-gray-50"
                id="attr-option-block-{{ $optionId }}"
                data-attr-option="{{ $optionId }}"
            >
                <div class="font-semibold mb-1 text-blue-900">
                    {{ $opt?->admin_name ?? 'Option ' . $optionId }}
                </div>

                <button
                    type="button"
                    class="addAllProductsForAttribute text-xs p-1 bg-blue-500 text-white rounded mb-2"
                    data-option-id="{{ $optionId }}"
                >
                    Add ALL products
                </button>

                <div class="pl-2" id="attribute-products-{{ $optionId }}">
                    @foreach ($productIds as $pid)
                        @php
                            $p = \Webkul\Product\Models\ProductFlat::where('product_id', $pid)->first();
                        @endphp

                        @if ($p)
                            <div
                                class="product-tag p-1 bg-blue-200 mb-1 rounded flex justify-between items-center"
                                data-product-id="{{ (int) $pid }}"
                                data-option-id="{{ (int) $optionId }}"
                            >
                                <div class="flex items-center gap-2">
                                    <button type="button" class="prod-move-up text-xs px-2 py-1 border rounded">↑</button>
                                    <button type="button" class="prod-move-down text-xs px-2 py-1 border rounded">↓</button>
                                    <span>{{ $p->name }}</span>
                                </div>

                                <div class="flex items-center gap-2">
                                    <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                                    <input type="hidden" name="config[products][{{ $optionId }}][]" value="{{ (int) $pid }}">
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>


                <input
                    type="text"
                    class="border mt-2 p-1 rounded w-full attrProductSearch"
                    placeholder="Search products to add..."
                    data-option-id="{{ $optionId }}"
                >

                <div class="attrProductResults border hidden bg-white rounded mt-1 max-h-40 overflow-auto"></div>
            </div>
        @endforeach
    </div>
</div>
