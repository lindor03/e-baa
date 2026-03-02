// packages/Webkul/Widgets/src/Resources/assets/js/widgets/categories.js

console.log("widgets/categories.js loaded");

/**
 * Ensure hidden input exists for selected category_id
 */
function ensureCategoryHiddenId(catId) {
    const hiddenWrap = document.getElementById("categoryIdsHidden");
    if (!hiddenWrap) return;

    if (hiddenWrap.querySelector(`input[data-category-id-hidden="${catId}"]`)) return;

    const inp = document.createElement("input");
    inp.type = "hidden";
    inp.name = "config[category_id][]";
    inp.value = String(catId);
    inp.dataset.categoryIdHidden = String(catId);

    hiddenWrap.appendChild(inp);
}

/**
 * Rebuild hidden category_id inputs based on current DOM order.
 */
function refreshCategoryHiddenOrder() {
    const hiddenWrap = document.getElementById("categoryIdsHidden");
    const container  = document.getElementById("categorySelected");
    if (!hiddenWrap || !container) return;

    const ids = Array.from(container.querySelectorAll("[data-category]"))
        .map(b => String(b.dataset.category || ""))
        .filter(Boolean);

    hiddenWrap.innerHTML = "";
    ids.forEach(id => {
        const inp = document.createElement("input");
        inp.type = "hidden";
        inp.name = "config[category_id][]";
        inp.value = id;
        inp.dataset.categoryIdHidden = id;
        hiddenWrap.appendChild(inp);
    });
}

/**
 * Ensure category block exists
 */
function ensureCategoryBlock(catId, catName) {
    const container = document.getElementById("categorySelected");
    if (!container) return;

    ensureCategoryHiddenId(catId);

    if (document.getElementById("category-block-" + catId)) return;

    const block = document.createElement("div");
    block.id = "category-block-" + catId;
    block.dataset.category = String(catId);
    block.className = "border p-2 mb-4 rounded bg-green-50";

    block.innerHTML = `
        <div class="flex items-center justify-between mb-2">
            <div class="font-semibold text-green-900">
                ${catName} (ID: ${catId})
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="cat-move-up text-xs px-2 py-1 border rounded">↑</button>
                <button type="button" class="cat-move-down text-xs px-2 py-1 border rounded">↓</button>
                <button type="button" class="cat-remove text-xs px-2 py-1 border rounded text-red-700 font-bold" title="Remove category">✕</button>
            </div>
        </div>

        <button
            type="button"
            class="addAllProductsForCategory text-xs p-1 bg-green-500 text-white rounded mb-2"
            data-category-id="${catId}"
        >
            Add ALL products
        </button>

        <div class="pl-2" id="category-products-${catId}"></div>

        <input
            type="text"
            class="border mt-2 p-1 rounded w-full categoryProductSearch"
            placeholder="Search products to add..."
            data-category-id="${catId}"
            autocomplete="off"
        >

        <div class="categoryProductResults border hidden bg-white rounded mt-1 max-h-40 overflow-auto"></div>
    `;

    container.appendChild(block);
    refreshCategoryHiddenOrder();
}

/**
 * Add product tag under given category (dedupe-safe)
 */
function addProductForCategory(categoryId, id, name) {
    const container = document.getElementById("category-products-" + categoryId);
    if (!container) return;

    if (container.querySelector(`input[value="${id}"]`)) return;

    container.insertAdjacentHTML(
        "beforeend",
        `
        <div class="product-tag p-1 bg-green-200 mb-1 rounded flex justify-between items-center"
             data-product-id="${id}"
             data-category-id="${categoryId}">
            <div class="flex items-center gap-2">
                <button type="button" class="cat-prod-move-up text-xs px-2 py-1 border rounded">↑</button>
                <button type="button" class="cat-prod-move-down text-xs px-2 py-1 border rounded">↓</button>
                <span>${name}</span>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                <input type="hidden" name="config[products_by_category][${categoryId}][]" value="${id}">
            </div>
        </div>
        `
    );
}

/**
 * Init hook
 */
function initCategories() {
    refreshCategoryHiddenOrder();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCategories);
} else {
    initCategories();
}

/**
 * Category & Product live search
 */
document.addEventListener("input", e => {
    if (e.target.id === "categorySearch") {
        const q = e.target.value.trim();
        const box = document.getElementById("categoryResults");
        if (!box) return;

        if (q.length < 2) {
            box.innerHTML = "";
            box.classList.add("hidden");
            return;
        }

        fetch(`/admin/widgets/search-categories?q=${encodeURIComponent(q)}`)
            .then(res => res.json())
            .then(cats => {
                box.innerHTML = "";
                box.classList.remove("hidden");

                (cats || []).forEach(c => {
                    const div = document.createElement("div");
                    div.textContent = c.name;
                    div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                    div.onclick = () => {
                        ensureCategoryBlock(c.id, c.name);
                        box.innerHTML = "";
                        box.classList.add("hidden");
                        e.target.value = "";
                    };
                    box.appendChild(div);
                });
            })
            .catch(err => console.error("categorySearch error:", err));

        return;
    }

    if (e.target.classList.contains("categoryProductSearch")) {
        const q          = e.target.value.trim();
        const categoryId = e.target.dataset.categoryId;

        const categoryBlock = e.target.closest("[data-category]");
        if (!categoryBlock) return;

        const box = categoryBlock.querySelector(".categoryProductResults");
        if (!box) return;

        if (q.length < 2 || !categoryId) {
            box.innerHTML = "";
            box.classList.add("hidden");
            return;
        }

        fetch(`/admin/widgets/search-products?q=${encodeURIComponent(q)}&categoryId=${categoryId}`)
            .then(res => res.json())
            .then(products => {
                if (!document.body.contains(categoryBlock)) return;

                box.innerHTML = "";
                box.classList.remove("hidden");

                (products || []).forEach(p => {
                    const div = document.createElement("div");
                    div.textContent = `${p.name} (SKU: ${p.sku})`;
                    div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                    div.onclick = () => {
                        addProductForCategory(categoryId, p.id, p.name);
                        box.innerHTML = "";
                        box.classList.add("hidden");
                        e.target.value = "";
                    };
                    box.appendChild(div);
                });
            })
            .catch(err => console.error("categoryProductSearch error:", err));
    }
});

/**
 * Click handlers: add all, reorder category, reorder product, remove category
 */
document.addEventListener("click", e => {
    // Add all products
    if (e.target.classList.contains("addAllProductsForCategory")) {
        const categoryId = e.target.dataset.categoryId;

        fetch(`/admin/widgets/get-products-by-category/${categoryId}`)
            .then(res => res.json())
            .then(products => {
                (products || []).forEach(p => addProductForCategory(categoryId, p.id, p.name));
            })
            .catch(err => console.error("addAllProductsForCategory error:", err));

        return;
    }

    // ✅ Remove category block
    const removeBtn = e.target.closest(".cat-remove");
    if (removeBtn) {
        const block = e.target.closest("[data-category]");
        if (!block) return;

        block.remove();
        refreshCategoryHiddenOrder(); // rebuild category_id[] in correct order
        return;
    }

    // Category reorder
    const catUp   = e.target.closest(".cat-move-up");
    const catDown = e.target.closest(".cat-move-down");
    if (catUp || catDown) {
        const block = e.target.closest("[data-category]");
        const container = document.getElementById("categorySelected");
        if (!block || !container) return;

        if (catUp) {
            const prev = block.previousElementSibling;
            if (prev) container.insertBefore(block, prev);
        } else {
            const next = block.nextElementSibling;
            if (next) container.insertBefore(next, block);
        }

        refreshCategoryHiddenOrder();
        return;
    }

    // Product reorder inside category
    const prodUp   = e.target.closest(".cat-prod-move-up");
    const prodDown = e.target.closest(".cat-prod-move-down");
    if (prodUp || prodDown) {
        const tag = e.target.closest(".product-tag");
        if (!tag) return;

        const list = tag.parentElement;
        if (!list) return;

        if (prodUp) {
            const prev = tag.previousElementSibling;
            if (prev) list.insertBefore(tag, prev);
        } else {
            const next = tag.nextElementSibling;
            if (next) list.insertBefore(next, tag);
        }

        return;
    }
});
