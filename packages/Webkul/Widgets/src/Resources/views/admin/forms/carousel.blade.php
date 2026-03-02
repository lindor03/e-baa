<div class="border rounded p-3">

    <label class="block text-sm font-medium mt-1">Upload Images</label>
    <input
        type="file"
        id="carouselImagesInput"
        name="images[]"
        multiple
        accept="image/*"
        class="w-full border p-2 rounded"
    >

    {{-- New uploads preview + reorder --}}
    <div class="mt-3">
        <div class="text-sm font-medium mb-1">
            New uploads (use arrows to reorder before saving)
        </div>

        <div id="carouselNewList" class="grid grid-cols-3 gap-2"></div>
    </div>

    {{-- Existing images: reorder + remove + link --}}
    <div class="mt-4">
        <div class="text-sm font-medium mb-1">
            Existing images (use arrows to reorder, check to remove)
        </div>

        <div id="carouselExistingList" class="grid grid-cols-3 gap-2">
            @foreach (($widget->config['images'] ?? []) as $img)
                @php
                    $linkVal = old("config.images_links.$img", $widget->config['images_links'][$img] ?? '');
                @endphp

                <div class="carousel-item border rounded p-2 bg-white" data-img-path="{{ $img }}">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <button type="button" class="carousel-move-left text-xs px-2 py-1 border rounded">←</button>
                            <button type="button" class="carousel-move-right text-xs px-2 py-1 border rounded">→</button>
                        </div>

                        <label class="text-xs flex items-center gap-1 select-none">
                            <input
                                type="checkbox"
                                class="carousel-remove"
                                name="config[remove_images][]"
                                value="{{ $img }}"
                            >
                            Remove
                        </label>
                    </div>

                    <img
                        src="{{ asset('storage/' . $img) }}"
                        class="w-full rounded select-none"
                        draggable="false"
                    >

                    <div class="mt-2">
                        <label class="block text-xs font-medium mb-1">Link URL (optional)</label>
                        <input
                            type="url"
                            class="carousel-link w-full border p-1 rounded text-xs"
                            name="config[images_links][{{ $img }}]"
                            placeholder="https://..."
                            value="{{ $linkVal }}"
                        >
                    </div>

                    {{-- order tracking --}}
                    <input type="hidden" name="config[images_order][]" value="{{ $img }}">
                </div>
            @endforeach
        </div>
    </div>

</div>
