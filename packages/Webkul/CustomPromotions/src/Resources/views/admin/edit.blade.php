<x-admin::layouts>
    <x-slot:title>Edit Promotion #{{ $promotion->id }}</x-slot:title>

    <div class="mt-3 flex items-center justify-between gap-2">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            Edit Promotion #{{ $promotion->id }}
        </p>

        <div class="flex gap-2">
            <a href="{{ route('admin.custompromotions.index') }}" class="transparent-button">Back</a>
            <button form="promo-edit-form" type="submit" class="primary-button">Save</button>
        </div>
    </div>

    <x-admin::form id="promo-edit-form"
        :action="route('admin.custompromotions.update', $promotion->id)"
        method="PUT"
        enctype="multipart/form-data">

        <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-3">
            <!-- Left -->
            <div class="xl:col-span-2 flex flex-col gap-3">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">General</p>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">Name</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="text" name="name" rules="required"
                            value="{{ $promotion->name }}" />
                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <div class="grid grid-cols-2 gap-4">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>From</x-admin::form.control-group.label>

                            <input
                                type="datetime-local"
                                name="from"
                                value="{{ optional($promotion->from)->format('Y-m-d\TH:i') }}"
                                class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />

                            <x-admin::form.control-group.error control-name="from" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>To</x-admin::form.control-group.label>

                            <input
                                type="datetime-local"
                                name="to"
                                value="{{ optional($promotion->to)->format('Y-m-d\TH:i') }}"
                                class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />

                            <x-admin::form.control-group.error control-name="to" />
                        </x-admin::form.control-group>
                    </div>


                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>Slug</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="text"
                            name="slug"
                            :value="old('slug', $promotion->slug ?? '')"
                            :placeholder="__('auto-generate if empty')"
                        />
                        <x-admin::form.control-group.error control-name="slug" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>Sort Order</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="number"
                            name="sort_order"
                            :value="old('sort_order', $promotion->sort_order ?? 0)"
                        />
                        <x-admin::form.control-group.error control-name="sort_order" />
                    </x-admin::form.control-group>



<x-admin::form.control-group>
    <x-admin::form.control-group.label>Status</x-admin::form.control-group.label>

    <select name="is_active"
            class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">

        <option value="1" {{ $promotion->is_active ? 'selected' : '' }}>Active</option>
        <option value="0" {{ !$promotion->is_active ? 'selected' : '' }}>Inactive</option>
    </select>

    <x-admin::form.control-group.error control-name="is_active" />
</x-admin::form.control-group>


                    <div class="grid grid-cols-2 gap-4">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>Banner</x-admin::form.control-group.label>

                            @if($promotion->banner_url)
                                <div class="mb-2">
                                    <img src="{{ $promotion->banner_url }}" class="max-h-32 rounded border" />
                                </div>
                                <label class="mb-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="remove_banner" value="1" />
                                    Remove current banner
                                </label>
                            @endif

                            <input type="file" name="banner" accept=".jpg,.jpeg,.png,.webp,.avif,.svg"
                                   class="block w-full rounded border px-3 py-2 text-sm" />
                            <x-admin::form.control-group.error control-name="banner" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>Logo</x-admin::form.control-group.label>

                            @if($promotion->logo_url)
                                <div class="mb-2">
                                    <img src="{{ $promotion->logo_url }}" class="h-16 w-auto rounded border" />
                                </div>
                                <label class="mb-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="remove_logo" value="1" />
                                    Remove current logo
                                </label>
                            @endif

                            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.avif,.svg"
                                   class="block w-full rounded border px-3 py-2 text-sm" />
                            <x-admin::form.control-group.error control-name="logo" />
                        </x-admin::form.control-group>
                    </div>
                </div>

                <!-- Products -->
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Products & Special Prices</p>

                    @php
                        // Avoid accessing $p->name directly (dynamic attribute). Read names from product_flat.
                        $ids = $promotion->products->pluck('id');
                        $names = \DB::table('product_flat')
                                    ->whereIn('product_id', $ids)
                                    ->pluck('name', 'product_id');

                        $seed = $promotion->products->map(function ($p) use ($names) {
                            return [
                                'product_id'    => $p->id,
                                'sku'           => $p->sku,
                                'name'          => $names[$p->id] ?? $p->sku ?? ('#' . $p->id),
                                'special_price' => (string) $p->pivot->special_price,
                            ];
                        })->values();
                    @endphp

                    <v-custom-promo-products :initial='@json($seed)'></v-custom-promo-products>
                </div>
            </div>

            <!-- Right -->
            <div class="xl:col-span-1">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">Actions</p>
                    <button type="submit" class="primary-button w-full">Save Promotion</button>
                </div>
            </div>
        </div>
    </x-admin::form>

    @pushOnce('scripts')
        <!-- Same component as in create -->
        <script type="text/x-template" id="v-custom-promo-products-template">
            <div>
                <div class="flex gap-2">
                    <input type="text" class="h-10 w-full rounded-md border px-3 text-sm"
                           placeholder="Search by ID / SKU / Name"
                           v-model="search" @keydown.enter.prevent="doSearch" />
                    <button type="button" class="secondary-button" @click="doSearch" :disabled="busy">Search</button>
                </div>

                <ul v-if="results.length" class="mt-3 border rounded">
                    <li v-for="r in results" :key="r.id"
                        class="flex cursor-pointer items-center justify-between border-t p-2 hover:bg-gray-50"
                        @click="addProduct(r)">
                        <div>
                            <div class="font-medium">@{{ r.name }}</div>
                            <div class="text-xs text-gray-500">#@{{ r.id }} • @{{ r.sku }}</div>
                        </div>
                        <span class="icon-add text-xl"></span>
                    </li>
                </ul>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                <th class="p-2">ID</th>
                                <th class="p-2">SKU</th>
                                <th class="p-2">Name</th>
                                <th class="p-2">Special Price</th>
                                <th class="p-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in items" :key="row.product_id" class="border-b">
                                <td class="p-2">@{{ row.product_id }}</td>
                                <td class="p-2">@{{ row.sku }}</td>
                                <td class="p-2">@{{ row.name }}</td>
                                <td class="p-2">
                                    <input type="text" class="h-9 w-36 rounded border px-2"
                                           v-model="row.special_price"
                                           :name="`items[${idx}][special_price]`" required />
                                    <input type="hidden" :name="`items[${idx}][product_id]`" :value="row.product_id" />
                                </td>
                                <td class="p-2">
                                    <button type="button" class="danger-button" @click="remove(idx)">Remove</button>
                                </td>
                            </tr>

                            <tr v-if="!items.length">
                                <td class="p-4 text-center text-gray-500" colspan="5">No products selected.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <x-admin::form.control-group.error control-name="items" />
                <x-admin::form.control-group.error control-name="items.*.product_id" />
                <x-admin::form.control-group.error control-name="items.*.special_price" />
            </div>
        </script>

        <script type="module">
            app.component('v-custom-promo-products', {
                template: '#v-custom-promo-products-template',
                props: { initial: { type: Array, default: () => [] } },
                data() {
                    return {
                        search: '',
                        results: [],
                        items: JSON.parse(JSON.stringify(this.initial || [])),
                        busy: false,
                    };
                },
                methods: {
                    async doSearch() {
                        if (!this.search || this.busy) return;
                        this.busy = true;
                        try {
                            const { data } = await window.axios.get(
                                "{{ route('admin.custompromotions.search.products') }}",
                                { params: { q: this.search, limit: 10 } }
                            );
                            this.results = data;
                        } catch (_) {
                            this.results = [];
                        } finally {
                            this.busy = false;
                        }
                    },
                    addProduct(r) {
                        if (this.items.find(p => p.product_id === r.id)) return;
                        this.items.push({ product_id: r.id, sku: r.sku, name: r.name, special_price: '' });
                        this.results = [];
                        this.search = '';
                    },
                    remove(i) { this.items.splice(i, 1); },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
