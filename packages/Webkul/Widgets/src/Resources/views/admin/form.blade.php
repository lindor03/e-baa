<div class="grid gap-4">

    {{-- Title --}}
    <div>
        <label class="block text-sm font-medium">Title</label>
        <input
            type="text"
            name="title"
            class="w-full border p-2 rounded"
            value="{{ old('title', $widget->title ?? '') }}"
            required
        >
    </div>

    {{-- Description --}}
    <div>
        <label class="block text-sm font-medium">Description</label>
        <textarea
            name="description"
            class="w-full border p-2 rounded"
        >{{ old('description', $widget->description ?? '') }}</textarea>
    </div>

    <label class="block text-sm font-medium mt-3">Show Title?</label>
    <select name="config[show_title]" class="w-full border p-2 rounded">
        <option value="0" @selected(! (old('config.show_title', $widget->config['show_title'] ?? true)))>No</option>
        <option value="1" @selected(old('config.show_title', $widget->config['show_title'] ?? true))>Yes</option>
    </select>

    <label class="block text-sm font-medium mt-3">Show Description?</label>
    <select name="config[show_description]" class="w-full border p-2 rounded">
        <option value="0" @selected(! (old('config.show_description', $widget->config['show_description'] ?? true)))>No</option>
        <option value="1" @selected(old('config.show_description', $widget->config['show_description'] ?? true))>Yes</option>
    </select>




    <label class="block text-sm font-medium mt-3">Show on Home?</label>
    <select name="config[is_home]" class="w-full border p-2 rounded">
        <option value="0">No</option>
        <option value="1" @selected(($widget->config['is_home'] ?? false))>Yes</option>
    </select>

    <label class="block text-sm font-medium mt-3">Is Carousel?</label>
    <select name="config[is_carousel]" class="w-full border p-2 rounded">
        <option value="0">No</option>
        <option value="1" @selected(($widget->config['is_carousel'] ?? false))>Yes</option>
    </select>



    {{-- Sort Order --}}
    <div>
        <label class="block text-sm font-medium">Sort Order</label>
        <input
            type="number"
            name="sort_order"
            class="w-full border p-2 rounded"
            value="{{ old('sort_order', $widget->sort_order ?? 0) }}"
        >
    </div>

    {{-- Status --}}
    <div class="flex items-center gap-2">
        <input
            type="checkbox"
            name="status"
            value="1"
            {{ old('status', $widget->status ?? 1) ? 'checked' : '' }}
        >
        <label class="text-sm font-medium">Active</label>
    </div>

    <div>
        <label class="block text-sm font-medium">Layout</label>
        <select name="config[layout]" class="w-full border p-2 rounded">
            @foreach(($layouts ?? []) as $k => $label)
                <option value="{{ $k }}"
                    {{ old('config.layout', $widget->config['layout'] ?? '1x1') == $k ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>


    {{-- Widget Type --}}
    {{-- Widget Type --}}
    <div>
        <label class="block text-sm font-medium">Widget Type</label>

        @php
            // edit = widget has an id
            $isEdit = !empty($widget?->id);
        @endphp

        {{-- Hidden ensures value is submitted even when select is disabled --}}
        @if ($isEdit)
            <input type="hidden" name="type" value="{{ $widget->type }}">
        @endif

        <select
            id="widget-type"
            name="type"
            class="w-full border p-2 rounded"
            {{ $isEdit ? 'disabled' : '' }}
        >
            @foreach ($types as $key => $label)
                <option value="{{ $key }}" {{ ($widget->type ?? '') == $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>

        @if ($isEdit)
            <p class="text-xs text-gray-500 mt-1">
                Widget type can’t be changed after creation.
            </p>
        @endif
    </div>


    {{-- Dynamic widget-specific fields --}}
    <div id="widget-fields-container">
        @include("widgets::admin.forms." . ($widget->type ?? 'featured_products'))
    </div>

</div>

@pushOnce('scripts')
<script src="{{ asset('vendor/widgets/js/widgets/common.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/products.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/attributes.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/categories.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/carousel.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/html.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/category_list.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/attribute_options_list.js') }}"></script>
<script src="{{ asset('vendor/widgets/js/widgets/promotions.js') }}"></script>

@endpushOnce

