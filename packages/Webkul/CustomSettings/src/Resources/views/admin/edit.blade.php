<x-admin::layouts>
    <x-slot:title>
        Edit Color
    </x-slot>

    <div class="page-content">
        <form method="POST" action="{{ route('admin.customsettings.update', $color->id) }}">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label>Key</label>
                <input type="text" value="{{ $color->key }}" disabled class="form-control" />
            </div>

            <div class="form-group mt-3">
                <label>Value</label>
                <input type="color" name="value" value="{{ $color->value }}" class="form-control form-control-color" />
            </div>

            <button type="submit" class="btn btn-primary mt-3">Update Color</button>
        </form>
    </div>
</x-admin::layouts>
