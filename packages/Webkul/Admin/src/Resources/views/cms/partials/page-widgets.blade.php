@php
    use Webkul\Widgets\Models\Widget;

    $allWidgets = Widget::where('status', 1)->orderBy('sort_order')->get(['id', 'title', 'type']);


    $attached = isset($page)
        ? $page->widgets->sortBy('pivot.sort_order')->values()
        : collect();
@endphp

<x-admin::accordion>
    <x-slot:header>
        <p class="p-2.5 text-base font-semibold text-gray-800 dark:text-white">
            Page Widgets
        </p>
    </x-slot>

    <x-slot:content>

        <!-- ADD WIDGET -->
        <div class="flex gap-2 mb-4">
            <select id="widget-selector" class="form-input flex-1">
                <option value="">Select widget…</option>

                @foreach ($allWidgets as $widget)
                    <option value="{{ $widget->id }}">
                        {{ $widget->title ?: ucfirst(str_replace('_', ' ', $widget->type)) }}
                        (#{{ $widget->id }})
                    </option>
                @endforeach
            </select>

            <button
                type="button"
                class="secondary-button"
                onclick="addWidgetToPage()"
            >
                Add
            </button>
        </div>

        <!-- SELECTED WIDGETS -->
        <div id="page-widgets" class="space-y-2">
            @foreach ($attached as $index => $widget)
                <div
                    class="flex items-center gap-3 border rounded p-2"
                    data-widget-id="{{ $widget->id }}"
                >
                    <input
                        type="hidden"
                        name="widgets[{{ $widget->id }}][enabled]"
                        value="1"
                    />

                    <input
                        type="number"
                        class="form-input w-20"
                        name="widgets[{{ $widget->id }}][sort_order]"
                        value="{{ $widget->pivot->sort_order ?? ($index + 1) }}"
                    />

                    <span class="flex-1 font-medium">
                        {{ $widget->title ?: ucfirst(str_replace('_', ' ', $widget->type)) }}
                    </span>

                    <button
                        type="button"
                        class="text-red-600 text-sm"
                        onclick="removeWidget(this)"
                    >
                        Remove
                    </button>
                </div>
            @endforeach
        </div>

    </x-slot>
</x-admin::accordion>

<script>
function addWidgetToPage() {
    const select = document.getElementById('widget-selector');
    const id = select.value;
    if (!id) return;

    // prevent duplicates
    if (document.querySelector(`[data-widget-id="${id}"]`)) {
        select.value = '';
        return;
    }

    const label = select.options[select.selectedIndex].text;
    const container = document.getElementById('page-widgets');
    const order = container.children.length + 1;

    const div = document.createElement('div');
    div.className = 'flex items-center gap-3 border rounded p-2';
    div.dataset.widgetId = id;

    div.innerHTML = `
        <input type="hidden" name="widgets[${id}][enabled]" value="1" />

        <input
            type="number"
            class="form-input w-20"
            name="widgets[${id}][sort_order]"
            value="${order}"
        />

        <span class="flex-1 font-medium">${label}</span>

        <button
            type="button"
            class="text-red-600 text-sm"
            onclick="removeWidget(this)"
        >
            Remove
        </button>
    `;

    container.appendChild(div);
    select.value = '';
}

function removeWidget(btn) {
    btn.closest('[data-widget-id]').remove();
}
</script>
