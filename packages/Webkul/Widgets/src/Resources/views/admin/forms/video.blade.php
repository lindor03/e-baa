<div class="border rounded p-3">

    <label class="block text-sm font-medium">Video URL</label>
    <input
        type="text"
        name="config[video_url]"
        class="w-full border p-2 rounded"
        placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/..."
        value="{{ old('config.video_url', $widget->config['video_url'] ?? '') }}"
    >

    <label class="block text-sm font-medium mt-3">Poster Image (optional)</label>
    <input
        type="file"
        name="images[]"
        accept="image/*"
        class="w-full border p-2 rounded"
    >

    @if (!empty($widget->config['images']))
        <div class="mt-3 grid grid-cols-3 gap-2">
            @foreach ($widget->config['images'] as $img)
                <img src="{{ asset('storage/' . $img) }}" class="w-full rounded">
            @endforeach
        </div>
    @endif

    <div class="mt-3 grid gap-2">
        <label class="inline-flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                name="config[autoplay]"
                value="1"
                {{ old('config.autoplay', $widget->config['autoplay'] ?? false) ? 'checked' : '' }}
            >
            Autoplay
        </label>

        <label class="inline-flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                name="config[muted]"
                value="1"
                {{ old('config.muted', $widget->config['muted'] ?? true) ? 'checked' : '' }}
            >
            Muted
        </label>

        <label class="inline-flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                name="config[loop]"
                value="1"
                {{ old('config.loop', $widget->config['loop'] ?? false) ? 'checked' : '' }}
            >
            Loop
        </label>
    </div>

</div>
