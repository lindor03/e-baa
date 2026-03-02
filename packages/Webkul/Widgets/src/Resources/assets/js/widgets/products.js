console.log("widgets/products.js loaded");

let featuredReqSeq = 0;

/**
 * Init hook (works after AJAX partial replace too)
 */
function initFeaturedProducts() {
    const results = document.getElementById("productResults");
    if (results) {
        results.innerHTML = "";
        results.classList.add("hidden");
    }
}

function hideFeaturedResults() {
    const box = document.getElementById("productResults");
    if (!box) return;
    box.innerHTML = "";
    box.classList.add("hidden");
}

function isAlreadySelected(productId) {
    const target = document.getElementById("productSelected");
    if (!target) return false;
    return !!target.querySelector(`input[name="config[products][]"][value="${productId}"]`);
}

function addFeaturedProduct(p) {
    const target = document.getElementById("productSelected");
    if (!target) return;

    if (isAlreadySelected(p.id)) return; // prevent duplicates

    target.insertAdjacentHTML(
        "beforeend",
        `
        <div class="product-tag featured-product-item p-1 bg-blue-200 mb-1 rounded flex justify-between items-center gap-2"
             data-product-id="${p.id}">
            <div class="flex items-center gap-2 min-w-0">
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="fp-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                    <button type="button" class="fp-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                </div>
                <span class="truncate">${p.name}</span>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                <input type="hidden" name="config[products][]" value="${p.id}">
            </div>
        </div>
        `
    );
}

/**
 * Safe init even if DOMContentLoaded already fired
 */
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initFeaturedProducts);
} else {
    initFeaturedProducts();
}

/**
 * Search input handler
 */
document.addEventListener("input", e => {
    if (e.target.id !== "productSearch") return;

    const q = e.target.value.trim();
    const box = document.getElementById("productResults");
    if (!box) return;

    if (q.length < 2) {
        hideFeaturedResults();
        return;
    }

    const seq = ++featuredReqSeq;

    fetch(`/admin/widgets/search-products?q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(products => {
            if (seq !== featuredReqSeq) return;

            const currentBox = document.getElementById("productResults");
            if (!currentBox) return;

            currentBox.innerHTML = "";
            currentBox.classList.remove("hidden");

            (products || []).forEach(p => {
                const div = document.createElement("div");
                div.textContent = `${p.name} (SKU: ${p.sku})`;
                div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";

                if (isAlreadySelected(p.id)) {
                    div.classList.add("opacity-60");
                }

                div.onclick = () => {
                    addFeaturedProduct(p);

                    const input = document.getElementById("productSearch");
                    if (input) input.value = "";

                    hideFeaturedResults();
                };

                currentBox.appendChild(div);
            });
        })
        .catch(err => console.error("productSearch error:", err));
});

/**
 * Reorder selected items (delegated, binds once)
 */
if (!window.__featuredProductsOrderBound) {
    window.__featuredProductsOrderBound = true;

    document.addEventListener("click", (e) => {
        const upBtn = e.target.closest(".fp-move-up");
        const dnBtn = e.target.closest(".fp-move-down");
        if (!upBtn && !dnBtn) return;

        const item = e.target.closest(".featured-product-item");
        const list = document.getElementById("productSelected");
        if (!item || !list) return;

        if (upBtn) {
            const prev = item.previousElementSibling;
            if (prev) list.insertBefore(item, prev);
        }

        if (dnBtn) {
            const next = item.nextElementSibling;
            if (next) list.insertBefore(next, item); // swap with next
        }
        // No extra hidden inputs needed: DOM order == submitted order
    });
}

/**
 * Hide results when clicking outside
 */
document.addEventListener("click", e => {
    const input = document.getElementById("productSearch");
    const box   = document.getElementById("productResults");

    if (!input || !box) return;
    if (e.target === input || box.contains(e.target)) return;

    hideFeaturedResults();
});
