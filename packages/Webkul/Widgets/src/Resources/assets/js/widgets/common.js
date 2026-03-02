console.log("widgets/common.js loaded");

/**
 * Remove tag + hidden input (product/category/etc)
 */
function removeTag(btn) {
    const wrap = btn.closest(".product-tag");
    if (wrap) {
        wrap.remove();
    }
}

/**
 * Load fields for the given widget type using AJAX partial
 */
function loadWidgetFields(type) {
    const container = document.getElementById("widget-fields-container");
    if (!container) return;

    fetch(`/admin/widgets/render/${type}`)
        .then(res => res.text())
        .then(html => {
            container.innerHTML = html;

            // 🔒 Wait for DOM to settle
            requestAnimationFrame(() => {
                if (typeof initAttributes === "function") initAttributes();
                if (typeof initCategories === "function") initCategories();
                if (typeof initFeaturedProducts === "function") initFeaturedProducts();
                if (typeof initCarousel === "function") initCarousel();
                if (typeof initHtmlWidgetEditor === "function") initHtmlWidgetEditor();
                if (typeof initCategoryList === "function") initCategoryList();
                if (typeof initAttributeOptionsList === "function") initAttributeOptionsList();
                if (typeof initPromotions === "function") initPromotions();

            });

        })

        .catch(err => console.error("Widget partial load error:", err));
}


/**
 * Listen for widget-type change
 */
// document.addEventListener("change", e => {
//     if (e.target.id === "widget-type") {
//         const type = e.target.value;
//         console.log("Widget type changed ->", type);
//         loadWidgetFields(type);
//     }
// });

document.addEventListener("change", e => {
    if (e.target.id === "widget-type" && !e.target.disabled) {
        loadWidgetFields(e.target.value);
    }
});


function bootWidgetModules() {
    if (typeof initAttributes === "function") initAttributes();
    if (typeof initCategories === "function") initCategories();
    if (typeof initFeaturedProducts === "function") initFeaturedProducts();
    if (typeof initCarousel === "function") initCarousel();
    if (typeof initHtmlWidgetEditor === "function") initHtmlWidgetEditor();
    if (typeof initCategoryList === "function") initCategoryList();
    if (typeof initAttributeOptionsList === "function") initAttributeOptionsList();
    if (typeof initPromotions === "function") initPromotions();

}

// ✅ Run once on initial page load (edit + create)
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootWidgetModules);
} else {
    bootWidgetModules();
}



// NOTE: we DO NOT auto-call loadWidgetFields on DOMContentLoaded,
// because edit/create already render correct partial on first load.
