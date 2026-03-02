@php
    $config   = $widget->config ?? [];
    $selected = (array) ($config['promotion_id'] ?? []);
@endphp

<div class="mt-4">
    <div class="flex gap-4">
        <div class="w-1/2">
            <label class="block font-semibold mb-1">Limit</label>
            <input type="number" name="config[limit]" class="control"
                   value="{{ (int)($config['limit'] ?? 10) }}" min="1" max="50">
        </div>

        <div class="w-1/2 flex items-center gap-2" style="margin-top: 28px;">
            <input type="checkbox" name="config[active_only]" value="1"
                   {{ !empty($config['active_only']) ? 'checked' : '' }}>
            <span>Active only (when no IDs selected)</span>
        </div>
    </div>

    <hr class="my-4">

    <label class="block font-semibold mb-1">Select Promotions (optional)</label>
    <p class="text-gray-600 text-sm mb-2">
        Leave empty to let API auto-return active promotions.
    </p>

    <select
        name="config[promotion_id][]"
        class="control js-promotion-multiselect"
        multiple
        data-search-url="{{ route('admin.widgets.search-promotions') }}"
    >
        @foreach($selected as $pid)
            <option value="{{ (int)$pid }}" selected>#{{ (int)$pid }}</option>
        @endforeach
    </select>
</div>
