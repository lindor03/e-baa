@php
    $pageType = old('type', $page->type ?? 'page');
@endphp

<!-- Page Type -->
<x-admin::form.control-group>
    <x-admin::form.control-group.label>
        Page Type
    </x-admin::form.control-group.label>

    <select
        name="type"
        class="form-input"
    >
        <option value="page"    {{ $pageType === 'page' ? 'selected' : '' }}>Page</option>
        <option value="header"  {{ $pageType === 'header' ? 'selected' : '' }}>Header</option>
        <option value="footer"  {{ $pageType === 'footer' ? 'selected' : '' }}>Footer</option>
        <option value="section" {{ $pageType === 'section' ? 'selected' : '' }}>Section</option>
        <option value="system"  {{ $pageType === 'system' ? 'selected' : '' }}>System</option>
    </select>
</x-admin::form.control-group>

<!-- Position -->
<x-admin::form.control-group>
    <x-admin::form.control-group.label>
        Position
    </x-admin::form.control-group.label>

    <x-admin::form.control-group.control
        type="number"
        name="position"
        :value="old('position', $page->position ?? 0)"
    />
</x-admin::form.control-group>

<!-- Active -->
<x-admin::form.control-group>
    <x-admin::form.control-group.label>
        Active
    </x-admin::form.control-group.label>

    <x-admin::form.control-group.control
        type="checkbox"
        name="is_active"
        value="1"
        :checked="old('is_active', $page->is_active ?? true)"
    />
</x-admin::form.control-group>
