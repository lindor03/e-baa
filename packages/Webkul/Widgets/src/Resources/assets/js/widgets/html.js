console.log("widgets/html.js loaded");

function initHtmlWidgetEditor() {
    const htmlEl = document.getElementById("htmlWidgetHtml");
    const cssEl = document.getElementById("htmlWidgetCss");
    const jsEl = document.getElementById("htmlWidgetJs");
    const iframe = document.getElementById("htmlWidgetPreview");
    const toggle = document.getElementById("htmlWidgetPreviewToggle");
    const wrap = document.getElementById("htmlWidgetPreviewWrap");

    // If this partial isn't on screen, do nothing
    if (!htmlEl && !cssEl && !jsEl && !iframe) return;

    // Prevent double binding after AJAX partial reload
    if (wrap && wrap.dataset.bound === "1") return;
    if (wrap) wrap.dataset.bound = "1";

    const render = () => {
        if (!iframe) return;

        const enabled = toggle ? toggle.checked : true;
        if (wrap) wrap.style.display = enabled ? "" : "none";
        if (!enabled) return;

        const html = htmlEl ? htmlEl.value : "";
        const css = cssEl ? cssEl.value : "";
        const js = jsEl ? jsEl.value : "";

        const doc = `
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>${css}</style>
</head>
<body>
${html}
<script>
try {
${js}
} catch (e) {
  document.body.insertAdjacentHTML('beforeend',
    '<pre style="white-space:pre-wrap;padding:8px;border:1px solid #fca5a5;background:#fee2e2;color:#7f1d1d;">' +
    'JS Error: ' + (e && e.message ? e.message : e) +
    '</pre>'
  );
}
<\/script>
</body>
</html>`;

        // srcdoc is safest for preview
        iframe.srcdoc = doc;
    };

    // Debounce typing for performance
    let t = null;
    const schedule = () => {
        clearTimeout(t);
        t = setTimeout(render, 250);
    };

    ["input", "change"].forEach(evt => {
        if (htmlEl) htmlEl.addEventListener(evt, schedule);
        if (cssEl) cssEl.addEventListener(evt, schedule);
        if (jsEl) jsEl.addEventListener(evt, schedule);
    });

    if (toggle) toggle.addEventListener("change", render);

    // initial render
    render();
}

// auto-init on first load
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initHtmlWidgetEditor);
} else {
    initHtmlWidgetEditor();
}
