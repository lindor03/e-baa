<div class="border rounded p-3">

    <div class="grid gap-4">

        <div>
            <label class="block text-sm font-medium">HTML</label>
            <textarea
                id="htmlWidgetHtml"
                name="config[html]"
                class="w-full border p-2 rounded font-mono text-sm"
                rows="10"
                placeholder="<div>...</div>"
            >{{ old('config.html', $widget->config['html'] ?? '') }}</textarea>
        </div>

        <div>
            <label class="block text-sm font-medium">CSS</label>
            <textarea
                id="htmlWidgetCss"
                name="config[css]"
                class="w-full border p-2 rounded font-mono text-sm"
                rows="8"
                placeholder=".my-class { ... }"
            >{{ old('config.css', $widget->config['css'] ?? '') }}</textarea>
            <p class="text-xs text-gray-600 mt-1">
                CSS will be injected inside a &lt;style&gt; tag for this widget.
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium">JavaScript</label>
            <textarea
                id="htmlWidgetJs"
                name="config[js]"
                class="w-full border p-2 rounded font-mono text-sm"
                rows="8"
                placeholder="document.querySelector(...);"
            >{{ old('config.js', $widget->config['js'] ?? '') }}</textarea>
            <p class="text-xs text-gray-600 mt-1">
                JS runs inside the widget container (preview uses sandboxed iframe).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <input
                type="checkbox"
                id="htmlWidgetPreviewToggle"
                name="config[enable_preview]"
                value="1"
                {{ old('config.enable_preview', $widget->config['enable_preview'] ?? true) ? 'checked' : '' }}
            >
            <label class="text-sm font-medium">Enable live preview</label>
        </div>

        <div id="htmlWidgetPreviewWrap" class="border rounded p-2 bg-white">
            <div class="text-sm font-medium mb-2">Preview</div>

            <iframe
                id="htmlWidgetPreview"
                class="w-full rounded"
                style="height: 320px;"
                sandbox="allow-scripts allow-forms allow-popups allow-modals"
            ></iframe>

            <p class="text-xs text-gray-600 mt-2">
                Preview is sandboxed. External network requests may be blocked by browser policy.
            </p>
        </div>

    </div>

</div>
