@php
    $config      = $widget->config ?? [];
    $promotionId = (int) ($config['promotion_id'] ?? 0);
@endphp

<div class="mt-4">
    <div class="flex gap-4">
        <div class="w-1/2">
            <label class="block font-semibold mb-1">Promotion</label>

            <select
                name="config[promotion_id]"
                class="control js-promotion-singeselect"
                data-search-url="{{ route('admin.widgets.search-promotions') }}"
                data-get-url="{{ route('admin.widgets.get-promotion', ['id' => '__ID__']) }}"
            >
                @if($promotionId)
                    <option value="{{ $promotionId }}" selected>#{{ $promotionId }}</option>
                @endif
            </select>

            <div class="text-sm text-gray-600 mt-2 js-promo-meta"></div>
        </div>

        <div class="w-1/2">
            <label class="block font-semibold mb-1">Products limit</label>
            <input type="number" name="config[limit]" class="control"
                   value="{{ (int)($config['limit'] ?? 24) }}" min="1" max="100">

            <div class="mt-3 flex gap-4">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="config[show_banner]" value="1"
                           {{ !empty($config['show_banner']) ? 'checked' : '' }}>
                    <span>Show banner</span>
                </label>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="config[show_logo]" value="1"
                           {{ !empty($config['show_logo']) ? 'checked' : '' }}>
                    <span>Show logo</span>
                </label>
            </div>
        </div>
    </div>
</div>
