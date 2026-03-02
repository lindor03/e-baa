<x-admin::layouts>
    <x-slot:title>
        Custom Theme Colors
    </x-slot>

    <div class="page-content">
        <form method="POST" action="{{ route('admin.customsettings.store') }}">
            @csrf

            <div class="table-responsive box-shadow grid w-full overflow-hidden rounded bg-white dark:bg-gray-900">
                <!-- Header -->
                <div class="row grid min-h-[47px] items-center gap-2.5 border-b bg-gray-50 px-4 py-2.5 font-semibold text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    style="grid-template-columns: repeat(4, minmax(0px, 1fr));">
                    <p>ID</p>
                    <p>Key</p>
                    <p>Color</p>
                    <p class="place-self-end">Actions</p>
                </div>

                <!-- Existing Colors -->
                @foreach ($colors as $color)
                    <div class="row grid items-center gap-2.5 border-b px-4 py-4 text-gray-600 transition-all hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-950"
                        style="grid-template-columns: repeat(4, minmax(0px, 1fr));">
                        <p>{{ $color->id }}</p>

                        <input type="text" name="keys[]" value="{{ $color->key }}"
                               class="form-control bg-transparent border-none shadow-none focus:ring-0" />

                        <input type="color" name="values[]" value="{{ $color->value }}"
                               class="form-control form-control-color w-[70px] h-[40px] border rounded" />

                        <div class="flex justify-end gap-2">
                            <button type="button"
                                    onclick="deleteColor({{ $color->id }})"
                                    class="icon-delete cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800">
                            </button>
                        </div>
                    </div>
                @endforeach

                <!-- Add New Row -->
                <div class="row grid items-center gap-2.5 border-b px-4 py-4 text-gray-600 transition-all hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-950"
                    style="grid-template-columns: repeat(4, minmax(0px, 1fr));">
                    <p>New</p>
                    <input type="text" name="keys[]" placeholder="e.g. header_bg" class="form-control" />
                    <input type="color" name="values[]" class="form-control form-control-color w-[70px] h-[40px] border rounded" />
                    <div></div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="primary-button">Save Colors</button>
            </div>
        </form>
    </div>

    <!-- Delete Color Script -->
    <script>
        function deleteColor(id) {
            if (!confirm('Delete this color?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/admin/custom-settings/${id}`;
            form.innerHTML = `
                @csrf
                @method('DELETE')
            `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</x-admin::layouts>
