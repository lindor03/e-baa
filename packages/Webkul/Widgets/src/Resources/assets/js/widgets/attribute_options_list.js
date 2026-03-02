console.log("widgets/attribute_options_list.js loaded");

function syncAttrListAttributeId(value) {
    const hidden = document.getElementById("attrListAttributeIdInput");
    if (hidden) hidden.value = value || "";
}

function initAttributeOptionsList() {
    const select = document.getElementById("attrListAttributeSelect");
    if (!select) return;

    // prevent double init on same element (AJAX partial reloads)
    if (select.dataset.bound === "1") return;
    select.dataset.bound = "1";

    const selectedAttrId = String(select.dataset.selected || "");
    const hiddenValue = String(document.getElementById("attrListAttributeIdInput")?.value || "");
    const want = selectedAttrId || hiddenValue || "";

    fetch("/admin/widgets/attributes", {
        headers: { "X-Requested-With": "XMLHttpRequest" }
    })
        .then(async (res) => {
            if (!res.ok) {
                const txt = await res.text().catch(() => "");
                throw new Error(`HTTP ${res.status} ${res.statusText} | ${txt.slice(0, 150)}`);
            }

            const ct = (res.headers.get("content-type") || "").toLowerCase();
            if (!ct.includes("application/json")) {
                const txt = await res.text().catch(() => "");
                throw new Error(`Expected JSON, got ${ct || "unknown"} | ${txt.slice(0, 150)}`);
            }

            return res.json();
        })
        .then(attrs => {
            const sel = document.getElementById("attrListAttributeSelect");
            if (!sel) return;

            sel.innerHTML = "";
            sel.add(new Option("— Select Attribute —", ""));

            (attrs || []).forEach(a => sel.add(new Option(a.admin_name, String(a.id))));

            if (want) {
                sel.value = want;

                if (sel.value === want) {
                    syncAttrListAttributeId(want);
                    loadAttrListOptions(want);
                } else {
                    console.warn("Failed to restore attrListAttributeSelect value:", want);
                    syncAttrListAttributeId(want);
                }
            } else {
                syncAttrListAttributeId("");
            }
        })
        .catch(err => console.error("initAttributeOptionsList error:", err));
}

function loadAttrListOptions(attributeId) {
    const results = document.getElementById("attrListOptionResults");
    const search  = document.getElementById("attrListOptionSearch");
    if (!results || !search) return;

    results.__options = [];
    results.innerHTML = "";
    results.classList.add("hidden");
    search.value = "";

    if (!attributeId) return;

    fetch(`/admin/widgets/attribute-options/${attributeId}`)
        .then(res => res.json())
        .then(options => {
            const box = document.getElementById("attrListOptionResults");
            if (!box) return;
            box.__options = options || [];
        })
        .catch(err => console.error("loadAttrListOptions error:", err));
}

function ensureAttrListTag(optionId, optionName) {
    const selected = document.getElementById("attrListSelected");
    if (!selected) return;

    // prevent duplicates (by hidden input value)
    if (selected.querySelector(`input[name="config[attribute_option_id][]"][value="${optionId}"]`)) return;

    selected.insertAdjacentHTML(
        "beforeend",
        `
        <div class="product-tag attr-list-item p-1 bg-blue-200 mb-1 rounded flex justify-between items-center gap-2"
             data-option-id="${optionId}">
            <div class="flex items-center gap-2 min-w-0">
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="attrlist-move-up text-xs px-2 py-1 border rounded bg-white">↑</button>
                    <button type="button" class="attrlist-move-down text-xs px-2 py-1 border rounded bg-white">↓</button>
                </div>
                <span class="truncate">${optionName}</span>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button type="button" class="text-red-700 text-xs font-bold" onclick="removeAttrListOptionTag(this)">✕</button>
                <input type="hidden" name="config[attribute_option_id][]" value="${optionId}" data-option-id-hidden="${optionId}">
            </div>
        </div>
        `
    );
}

function removeAttrListOptionTag(btn) {
    const tag = btn.closest(".attr-list-item");
    if (tag) tag.remove();
}

// ✅ Safe init
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAttributeOptionsList);
} else {
    initAttributeOptionsList();
}

// Attribute change (delegated) — clear selected because new attribute
document.addEventListener("change", (e) => {
    if (e.target.id !== "attrListAttributeSelect") return;

    const newId = e.target.value || "";
    syncAttrListAttributeId(newId);

    const selected = document.getElementById("attrListSelected");
    const results  = document.getElementById("attrListOptionResults");
    if (selected) selected.innerHTML = "";
    if (results) {
        results.__options = [];
        results.innerHTML = "";
        results.classList.add("hidden");
    }

    loadAttrListOptions(newId);
});

// Search/filter options (delegated)
document.addEventListener("input", (e) => {
    if (e.target.id !== "attrListOptionSearch") return;

    const results = document.getElementById("attrListOptionResults");
    const hiddenAttrId = String(document.getElementById("attrListAttributeIdInput")?.value || "");
    if (!results) return;

    if (!hiddenAttrId) {
        results.innerHTML = `<div class="text-sm text-gray-500 p-1">Select attribute first</div>`;
        results.classList.remove("hidden");
        return;
    }

    const q = e.target.value.trim().toLowerCase();
    if (q.length < 1) {
        results.innerHTML = "";
        results.classList.add("hidden");
        return;
    }

    const all = results.__options || [];
    const filtered = all
        .filter(o => (o.admin_name || "").toLowerCase().includes(q))
        .slice(0, 30);

    results.innerHTML = "";
    results.classList.remove("hidden");

    if (!filtered.length) {
        results.innerHTML = `<div class="text-sm text-gray-500 p-1">No results</div>`;
        return;
    }

    filtered.forEach(o => {
        const div = document.createElement("div");
        div.textContent = o.admin_name;
        div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
        div.onclick = () => {
            ensureAttrListTag(o.id, o.admin_name);
            results.innerHTML = "";
            results.classList.add("hidden");
            e.target.value = "";
        };
        results.appendChild(div);
    });
});

// ✅ Reorder selected options (delegated, bound once)
if (!window.__attrListOrderBound) {
    window.__attrListOrderBound = true;

    document.addEventListener("click", (e) => {
        const upBtn = e.target.closest(".attrlist-move-up");
        const dnBtn = e.target.closest(".attrlist-move-down");
        if (!upBtn && !dnBtn) return;

        const item = e.target.closest(".attr-list-item");
        const list = document.getElementById("attrListSelected");
        if (!item || !list) return;

        if (upBtn) {
            const prev = item.previousElementSibling;
            if (prev) list.insertBefore(item, prev);
        }

        if (dnBtn) {
            const next = item.nextElementSibling;
            if (next) list.insertBefore(next, item); // swap with next
        }
    });
}

// Hide results when clicking outside
document.addEventListener("click", (e) => {
    const search = document.getElementById("attrListOptionSearch");
    const results = document.getElementById("attrListOptionResults");
    if (!search || !results) return;

    if (e.target === search || results.contains(e.target)) return;
    results.classList.add("hidden");
});
