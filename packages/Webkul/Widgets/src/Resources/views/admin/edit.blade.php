<x-admin::layouts>
    <x-slot:pageTitle>
        Edit Widget
    </x-slot:pageTitle>

    <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-bold">
            Edit Widget
        </h1>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded box-shadow p-4">
        <form
            method="POST"
            action="{{ route('admin.widgets.update', $widget->id) }}"
            enctype="multipart/form-data"
        >
            @csrf
            @method('PUT')

            @include('widgets::admin.form', ['widget' => $widget])

            <div class="mt-4 flex justify-end gap-2">
                <a
                    href="{{ route('admin.widgets.index') }}"
                    class="secondary-button"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="primary-button"
                >
                    Update
                </button>
            </div>
        </form>
    </div>
</x-admin::layouts>
