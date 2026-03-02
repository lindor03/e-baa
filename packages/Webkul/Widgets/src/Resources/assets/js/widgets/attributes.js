console.log("widgets/attributes.js loaded");

function syncAttributeId(value) {
    const hidden = document.getElementById("attributeIdInput");
    if (hidden) hidden.value = value || "";
}

/**
 * Render an orderable selected option row (source of truth for submit)
 */
function renderSelectedOptionRow(optionId, optionName) {
    const wrap = document.getElementById("attributeOptionOrder");
    if (!wrap) return;

    const id = String(optionId);

    if (wrap.querySelector(`[data-order-option="${id}"]`)) return;

    const row = document.createElement("div");
    row.className = "border rounded p-2 bg-white flex items-center justify-between gap-2";
    row.dataset.orderOption = id;

    row.innerHTML = `
        <div class="flex items-center gap-2">
            <button type="button" class="opt-move-up text-xs px-2 py-1 border rounded">↑</button>
            <button type="button" class="opt-move-down text-xs px-2 py-1 border rounded">↓</button>

            <div class="font-medium text-blue-900">${optionName}</div>
            <div class="text-xs text-gray-500">(ID: ${id})</div>
        </div>

        <button type="button" class="opt-remove text-xs text-red-700 font-bold">✕</button>

        <input type="hidden" name="config[attribute_option_id][]" value="${id}">
    `;

    wrap.appendChild(row);
}

function removeSelectedOptionRow(optionId) {
    const wrap = document.getElementById("attributeOptionOrder");
    if (!wrap) return;

    const id = String(optionId);
    const row = wrap.querySelector(`[data-order-option="${id}"]`);
    if (row) row.remove();
}

/**
 * Ensure UI select reflects current ordered list
 */
function syncOrderToSelect() {
    const select = document.getElementById("attributeOptionSelect");
    const wrap = document.getElementById("attributeOptionOrder");
    if (!select || !wrap) return;

    const orderedIds = Array.from(wrap.querySelectorAll("[data-order-option]"))
        .map(el => String(el.dataset.orderOption));

    Array.from(select.options).forEach(o => {
        o.selected = orderedIds.includes(String(o.value));
    });
}

/**
 * Init attributes select
 */
function initAttributes() {
    const attrSelect = document.getElementById("attributeSelect");
    if (!attrSelect) return;

    const selectedAttrId = String(attrSelect.dataset.selected || "");

    fetch("/admin/widgets/attributes")
        .then(res => res.json())
        .then(attrs => {
            attrSelect.innerHTML = "";
            attrSelect.add(new Option("— Select Attribute —", ""));

            (attrs || []).forEach(a => {
                attrSelect.add(new Option(a.admin_name, String(a.id)));
            });

            if (selectedAttrId) {
                attrSelect.value = selectedAttrId;

                if (attrSelect.value === selectedAttrId) {
                    syncAttributeId(selectedAttrId);
                    loadAttributeOptions(selectedAttrId, false);
                } else {
                    console.warn("Failed to restore attributeSelect value:", selectedAttrId);
                }
            } else {
                syncAttributeId("");
            }
        })
        .catch(err => console.error("initAttributes error:", err));
}

/**
 * Load attribute options
 */
function loadAttributeOptions(attributeId, forceReset = true) {
    const optionSelect = document.getElementById("attributeOptionSelect");
    const container    = document.getElementById("attributeOptionsProducts");
    const orderWrap    = document.getElementById("attributeOptionOrder");

    if (!optionSelect) return;

    optionSelect.innerHTML = "";

    // wipe blocks + order list only when user changes attribute
    if (forceReset) {
        if (container) container.innerHTML = "";
        if (orderWrap) orderWrap.innerHTML = "";
    }

    if (!attributeId) return;

    fetch("/admin/widgets/attribute-options/" + attributeId)
        .then(res => res.json())
        .then(options => {
            const preSelected = JSON.parse(optionSelect.dataset.selected || "[]")
                .map(id => parseInt(id, 10));

            (options || []).forEach(o => {
                const opt = new Option(o.admin_name, String(o.id));

                if (preSelected.includes(parseInt(o.id, 10))) {
                    opt.selected = true;

                    ensureAttributeOptionBlock(String(o.id), o.admin_name);
                    renderSelectedOptionRow(String(o.id), o.admin_name);
                }

                optionSelect.add(opt);
            });

            // after rebuild, keep select synced to current order list
            syncOrderToSelect();
        })
        .catch(err => console.error("loadAttributeOptions error:", err));
}

/**
 * Ensure product block for attribute option
 */
function ensureAttributeOptionBlock(optionId, optionName) {
    const container = document.getElementById("attributeOptionsProducts");
    if (!container) return;

    const id = String(optionId);

    if (document.getElementById("attr-option-block-" + id)) return;

    const block = document.createElement("div");
    block.id = "attr-option-block-" + id;
    block.dataset.attrOption = id;
    block.className = "border p-2 mb-4 rounded bg-gray-50";

    block.innerHTML = `
        <div class="font-semibold mb-1 text-blue-900">
            ${optionName} (ID: ${id})
        </div>

        <button
            type="button"
            class="addAllProductsForAttribute text-xs p-1 bg-blue-500 text-white rounded mb-2"
            data-option-id="${id}"
        >
            Add ALL products
        </button>

        <div class="pl-2" id="attribute-products-${id}"></div>

        <input
            type="text"
            class="border mt-2 p-1 rounded w-full attrProductSearch"
            placeholder="Search products to add..."
            data-option-id="${id}"
        >

        <div class="attrProductResults border hidden bg-white rounded mt-1 max-h-40 overflow-auto"></div>
    `;

    container.appendChild(block);
}

/**
 * Add product tag
 */
function addProductForAttributeOption(optionId, id, name) {
    const container = document.getElementById("attribute-products-" + optionId);
    if (!container) return;

    // prevent duplicates
    if (container.querySelector(`input[value="${id}"]`)) return;

    container.insertAdjacentHTML(
        "beforeend",
        `
        <div class="product-tag p-1 bg-blue-200 mb-1 rounded flex justify-between items-center"
             data-product-id="${id}"
             data-option-id="${optionId}">
            <div class="flex items-center gap-2">
                <button type="button" class="prod-move-up text-xs px-2 py-1 border rounded">↑</button>
                <button type="button" class="prod-move-down text-xs px-2 py-1 border rounded">↓</button>
                <span>${name}</span>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="text-red-700 text-xs font-bold" onclick="removeTag(this)">✕</button>
                <input type="hidden" name="config[products][${optionId}][]" value="${id}">
            </div>
        </div>
        `
    );
}


/**
 * Safe init on load
 */
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAttributes);
} else {
    initAttributes();
}

/**
 * Change handlers
 */
document.addEventListener("change", e => {
    if (e.target.id === "attributeSelect") {
        const newId = e.target.value || "";

        syncAttributeId(newId);

        // clear preselected options from old attribute
        const optionSelect = document.getElementById("attributeOptionSelect");
        if (optionSelect) optionSelect.dataset.selected = "[]";

        loadAttributeOptions(newId, true);
    }

    if (e.target.id === "attributeOptionSelect") {
        const select = e.target;

        const selected = Array.from(select.selectedOptions).map(o => ({
            id: String(o.value),
            name: o.text
        }));

        // Add newly selected
        selected.forEach(s => {
            ensureAttributeOptionBlock(s.id, s.name);
            renderSelectedOptionRow(s.id, s.name);
        });

        // Remove unselected (row + block)
        const wrap = document.getElementById("attributeOptionOrder");
        if (wrap) {
            Array.from(wrap.querySelectorAll("[data-order-option]")).forEach(row => {
                const id = row.dataset.orderOption;
                const stillSelected = selected.some(s => s.id === id);

                if (!stillSelected) {
                    row.remove();
                    const block = document.getElementById("attr-option-block-" + id);
                    if (block) block.remove();
                }
            });
        }

        syncOrderToSelect();
    }
});

/**
 * Reorder + remove selected options (arrows + X)
 */
document.addEventListener("click", (e) => {
    const wrap = document.getElementById("attributeOptionOrder");
    if (!wrap) return;

    const row = e.target.closest("[data-order-option]");
    if (!row) return;

    if (e.target.classList.contains("opt-move-up")) {
        const prev = row.previousElementSibling;
        if (prev) wrap.insertBefore(row, prev);
        syncOrderToSelect();
        return;
    }

    if (e.target.classList.contains("opt-move-down")) {
        const next = row.nextElementSibling;
        if (next) wrap.insertBefore(next, row); // swap
        syncOrderToSelect();
        return;
    }

    if (e.target.classList.contains("opt-remove")) {
        const optionId = row.dataset.orderOption;

        // unselect in select
        const select = document.getElementById("attributeOptionSelect");
        if (select) {
            Array.from(select.options).forEach(o => {
                if (String(o.value) === String(optionId)) o.selected = false;
            });
        }

        // remove row + product block
        row.remove();
        const block = document.getElementById("attr-option-block-" + optionId);
        if (block) block.remove();

        syncOrderToSelect();
        return;
    }
});

/**
 * Product search per option
 */
document.addEventListener("input", e => {
    if (!e.target.classList.contains("attrProductSearch")) return;

    const q        = e.target.value.trim();
    const optionId = e.target.dataset.optionId;

    const optionBlock = e.target.closest("[data-attr-option]");
    if (!optionBlock) return;

    const resultsBox = optionBlock.querySelector(".attrProductResults");
    if (!resultsBox) return;

    if (q.length < 2) {
        resultsBox.innerHTML = "";
        resultsBox.classList.add("hidden");
        return;
    }

    fetch(`/admin/widgets/search-products?q=${encodeURIComponent(q)}&optionId=${optionId}`)
        .then(res => res.json())
        .then(products => {
            if (!document.body.contains(optionBlock)) return;

            resultsBox.innerHTML = "";
            resultsBox.classList.remove("hidden");

            (products || []).forEach(p => {
                const div = document.createElement("div");
                div.textContent = `${p.name} (SKU: ${p.sku})`;
                div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                div.onclick = () => {
                    addProductForAttributeOption(optionId, p.id, p.name);
                    resultsBox.innerHTML = "";
                    resultsBox.classList.add("hidden");
                    e.target.value = "";
                };
                resultsBox.appendChild(div);
            });
        })
        .catch(err => console.error("attrProductSearch error:", err));
});

/**
 * Add ALL products
 */
document.addEventListener("click", e => {
    if (!e.target.classList.contains("addAllProductsForAttribute")) return;

    const optionId = e.target.dataset.optionId;

    fetch(`/admin/widgets/get-products-by-attribute-option/${optionId}`)
        .then(res => res.json())
        .then(products => {
            (products || []).forEach(p => addProductForAttributeOption(optionId, p.id, p.name));
        })
        .catch(err => console.error("addAllProductsForAttribute error:", err));
});

// product reorder buttons
document.addEventListener("click", (e) => {
    // product reorder buttons
    const up = e.target.closest(".prod-move-up");
    const down = e.target.closest(".prod-move-down");
    if (!up && !down) return;

    const tag = e.target.closest(".product-tag");
    if (!tag) return;

    const container = tag.parentElement;
    if (!container) return;

    if (up) {
        const prev = tag.previousElementSibling;
        if (prev) container.insertBefore(tag, prev);
        return;
    }

    if (down) {
        const next = tag.nextElementSibling;
        if (next) container.insertBefore(next, tag); // swap
        return;
    }
});

