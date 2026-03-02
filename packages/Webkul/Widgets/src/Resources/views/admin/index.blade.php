<x-admin::layouts>
    <x-slot:title>
        Widgets
    </x-slot>

    <v-widgets></v-widgets>

    @pushOnce('scripts')
        <!-- ===================== -->
        <!-- Vue Template -->
        <!-- ===================== -->
        <script type="text/x-template" id="v-widgets-template">
            <div>
                <!-- Header -->
                <div class="flex justify-between items-center mb-4">
                    <p class="text-xl font-bold text-gray-800 dark:text-white">
                        Widgets
                    </p>

                    <a
                        href="{{ route('admin.widgets.create') }}"
                        class="primary-button"
                    >
                        Create Widget
                    </a>
                </div>

                <!-- Table -->
                <div class="box-shadow rounded bg-white dark:bg-gray-900 overflow-hidden">
                    <table class="w-full table-auto">
                        <thead>
                            <tr class="border-b dark:border-gray-800 text-xs uppercase text-gray-600 dark:text-gray-300">
                                <th class="w-12"></th>
                                <th class="px-4 py-2 text-left">ID</th>
                                <th class="px-4 py-2 text-left">Title</th>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>

                        <draggable
                            tag="tbody"
                            :list="widgets"
                            item-key="id"
                            handle=".icon-drag"
                            ghost-class="widget-drag-ghost"
                            :animation="200"
                            @end="updateOrder"
                        >
                            <template #item="{ element, index }">
                                <tr
                                    class="border-b dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                >
                                    <!-- Drag Handle -->
                                    <td class="px-4 py-2 text-center">
                                        <i class="icon-drag cursor-grab text-xl"></i>
                                    </td>

                                    <!-- ID -->
                                    <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">
                                        @{{ element.id }}
                                    </td>

                                    <!-- Title -->
                                    <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">
                                        @{{ element.title }}
                                    </td>

                                    <!-- Type -->
                                    <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">
                                        @{{ element.type }}
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-2 text-sm">
                                        <span
                                            v-if="element.status"
                                            class="badge badge-md badge-success"
                                        >
                                            Active
                                        </span>

                                        <span
                                            v-else
                                            class="badge badge-md badge-danger"
                                        >
                                            Inactive
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-4 py-2 text-right text-sm">
                                        <a
                                            :href="`{{ url('admin/widgets') }}/${element.id}/edit`"
                                            class="icon-edit cursor-pointer mr-2"
                                        ></a>

                                        <span
                                            class="icon-delete cursor-pointer text-red-600"
                                            @click="confirmDelete(element.id)"
                                        ></span>
                                    </td>
                                </tr>
                            </template>
                        </draggable>
                    </table>

                    <!-- Empty State -->
                    <div
                        v-if="!widgets.length"
                        class="px-4 py-6 text-center text-gray-500 dark:text-gray-400 text-sm"
                    >
                        No widgets found.
                    </div>
                </div>
            </div>
        </script>

        <!-- ===================== -->
        <!-- Vue Component -->
        <!-- ===================== -->
        <script type="module">
            app.component('v-widgets', {
                template: '#v-widgets-template',

                data() {
                    return {
                        widgets: @json($widgets),
                    }
                },

                methods: {
                    updateOrder() {
                        const order = this.widgets.map(widget => widget.id);

                        this.$axios.post(
                            '{{ route("admin.widgets.reorder") }}',
                            { order }
                        ).then(() => {
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: 'Widget order updated successfully.'
                            });
                        });
                    },

                    confirmDelete(id) {
                        this.$emitter.emit('open-confirm-modal', {
                            agree: () => {
                                this.$axios.delete(
                                    `{{ url('admin/widgets') }}/${id}`
                                ).then(() => {
                                    this.widgets = this.widgets.filter(
                                        widget => widget.id !== id
                                    );

                                    this.$emitter.emit('add-flash', {
                                        type: 'success',
                                        message: 'Widget deleted successfully.'
                                    });
                                });
                            }
                        });
                    },
                },
            });
        </script>

        <!-- ===================== -->
        <!-- Ghost Styling -->
        <!-- ===================== -->
        <style>
            .widget-drag-ghost {
                opacity: 0.6;
                background-color: rgb(243 244 246); /* gray-100 */
            }

            .dark .widget-drag-ghost {
                background-color: rgb(31 41 55); /* gray-800 */
            }
        </style>
    @endPushOnce
</x-admin::layouts>
