console.log("widgets/category_list.js loaded");

/**
 * Ensure visible tag exists (tag contains hidden input, so order persists)
 */
function ensureCategoryListTag(catId, catName) {
    const selected = document.getElementById("categoryListSelected");
    if (!selected) return;

    // prevent duplicates (by hidden input value)
    if (selected.querySelector(`input[name="config[category_id][]"][value="${catId}"]`)) return;

    selected.insertAdjacentHTML(
        "beforeend",
        `
        <div class="product-tag category-list-item p-1 bg-green-200 mb-1 rounded flex justify-between items-center gap-2"
             data-category="${catId}">
            <div class="flex items-center gap-2 min-w-0">
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="catlist-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                    <button type="button" class="catlist-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                </div>
                <span class="truncate">${catName}</span>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button type="button" class="text-red-700 text-xs font-bold" onclick="removeCategoryListTag(this)">✕</button>
                <input type="hidden" name="config[category_id][]" value="${catId}" data-category-id-hidden="${catId}">
            </div>
        </div>
        `
    );
}

/**
 * Remove tag (removes hidden input automatically because it's inside)
 */
function removeCategoryListTag(btn) {
    const tag = btn.closest(".category-list-item");
    if (tag) tag.remove();
}

/**
 * Init hook (kept for symmetry)
 */
function initCategoryList() {
    // no-op
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCategoryList);
} else {
    initCategoryList();
}

/**
 * Search categories (delegated)
 */
document.addEventListener("input", (e) => {
    if (e.target.id !== "categoryListSearch") return;

    const q = e.target.value.trim();
    const box = document.getElementById("categoryListResults");
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

            if (!cats.length) {
                box.innerHTML = `<div class="text-sm text-gray-500 p-1">No results</div>`;
                return;
            }

            cats.forEach(c => {
                const div = document.createElement("div");
                div.textContent = c.name;
                div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                div.onclick = () => {
                    ensureCategoryListTag(c.id, c.name);
                    box.innerHTML = "";
                    box.classList.add("hidden");
                    e.target.value = "";
                };
                box.appendChild(div);
            });
        })
        .catch(err => console.error("categoryListSearch error:", err));
});

/**
 * Reorder selected categories (delegated, bound once)
 */
if (!window.__categoryListOrderBound) {
    window.__categoryListOrderBound = true;

    document.addEventListener("click", (e) => {
        const upBtn = e.target.closest(".catlist-move-up");
        const dnBtn = e.target.closest(".catlist-move-down");
        if (!upBtn && !dnBtn) return;

        const item = e.target.closest(".category-list-item");
        const list = document.getElementById("categoryListSelected");
        if (!item || !list) return;

        if (upBtn) {
            const prev = item.previousElementSibling;
            if (prev) list.insertBefore(item, prev);
        }

        if (dnBtn) {
            const next = item.nextElementSibling;
            if (next) list.insertBefore(next, item); // swap
        }
    });
}

/**
 * Hide results when clicking outside
 */
document.addEventListener("click", (e) => {
    const search = document.getElementById("categoryListSearch");
    const box = document.getElementById("categoryListResults");
    if (!search || !box) return;

    if (e.target === search || box.contains(e.target)) return;
    box.classList.add("hidden");
});
