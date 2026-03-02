<link rel="stylesheet" href="{{ asset('themes/default/assets/css/admin.css') }}">

@php
    $colors = Webkul\CustomSettings\Models\CustomColor::all()->pluck('value', 'key');
@endphp

<style>
    :root {
        --custom-primary: {{ $colors['primary'] ?? '#4e73df' }};
        --custom-secondary: {{ $colors['secondary'] ?? '#858796' }};
    }

    .btn-custom {
        background-color: var(--custom-primary);
        color: #fff;
    }

    .bg-custom {
        background-color: var(--custom-secondary);
    }
</style>
