console.log("widgets/promotions.js loaded");

function escapeHtml(str) {
    return String(str ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function enhancePromotionMultiSelect(select) {
    if (!select || select.dataset.enhanced === "1") return;
    select.dataset.enhanced = "1";

    const searchUrl = select.dataset.searchUrl;
    if (!searchUrl) return;

    // Hide the native select (we still submit it)
    select.style.display = "none";

    const wrapper = document.createElement("div");
    wrapper.className = "border rounded p-2 bg-white";

    const input = document.createElement("input");
    input.type = "text";
    input.className = "control w-full";
    input.placeholder = "Search promotions...";
    input.autocomplete = "off";

    const results = document.createElement("div");
    results.className = "border hidden bg-white rounded mt-1 max-h-40 overflow-auto";

    const selectedWrap = document.createElement("div");
    selectedWrap.className = "mt-2 flex flex-wrap gap-2";

    // Insert UI after select
    select.parentNode.insertBefore(wrapper, select.nextSibling);
    wrapper.appendChild(input);
    wrapper.appendChild(results);
    wrapper.appendChild(selectedWrap);

    function rebuildSelectedTags() {
        selectedWrap.innerHTML = "";

        const selectedOptions = Array.from(select.options).filter(o => o.selected);

        selectedOptions.forEach((opt) => {
            const tag = document.createElement("div");
            tag.className = "px-2 py-1 rounded bg-green-200 flex items-center gap-2";
            tag.dataset.value = opt.value;

            tag.innerHTML = `
                <button type="button" class="promo-up text-xs px-2 py-1 border rounded">↑</button>
                <button type="button" class="promo-down text-xs px-2 py-1 border rounded">↓</button>
                <span>${escapeHtml(opt.text || ("#" + opt.value))}</span>
                <button type="button" class="promo-remove text-red-700 text-xs font-bold">✕</button>
            `;

            selectedWrap.appendChild(tag);
        });
    }

    function addPromotion(id, label) {
        id = String(id);

        let opt = Array.from(select.options).find(o => String(o.value) === id);
        if (!opt) {
            opt = document.createElement("option");
            opt.value = id;
            opt.text = label || ("#" + id);
            select.appendChild(opt);
        } else if (label && (opt.text === ("#" + id) || opt.text.trim() === "")) {
            opt.text = label;
        }

        opt.selected = true;
        rebuildSelectedTags();
    }

    async function hydrateSelectedLabels() {
        // If edit page renders only "#ID", fetch nicer labels
        // We try: search endpoint with q=ID (fast enough in most setups)
        const ids = Array.from(select.options).filter(o => o.selected).map(o => String(o.value));
        if (!ids.length) return;

        for (const id of ids) {
            const opt = Array.from(select.options).find(o => String(o.value) === id);
            if (!opt) continue;
            if (opt.text && opt.text !== ("#" + id)) continue;

            try {
                const res = await fetch(`${searchUrl}?q=${encodeURIComponent(id)}`);
                const arr = await res.json();
                const found = (arr || []).find(x => String(x.id) === id);
                if (found) {
                    opt.text = found.name ? `${found.name} (#${found.id})` : `#${found.id}`;
                }
            } catch (e) {
                // ignore
            }
        }

        rebuildSelectedTags();
    }

    let t = null;

    input.addEventListener("input", () => {
        const q = input.value.trim();

        if (t) clearTimeout(t);

        if (q.length < 2) {
            results.innerHTML = "";
            results.classList.add("hidden");
            return;
        }

        t = setTimeout(async () => {
            try {
                const res = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}`);
                const items = await res.json();

                results.innerHTML = "";
                results.classList.remove("hidden");

                (items || []).forEach((p) => {
                    const div = document.createElement("div");
                    const label = p.name ? `${p.name} (#${p.id})` : `#${p.id}`;
                    div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                    div.textContent = label;
                    div.onclick = () => {
                        addPromotion(p.id, label);
                        results.innerHTML = "";
                        results.classList.add("hidden");
                        input.value = "";
                    };
                    results.appendChild(div);
                });
            } catch (err) {
                console.error("promotion search error:", err);
            }
        }, 150);
    });

    document.addEventListener("click", (e) => {
        if (!wrapper.contains(e.target)) {
            results.classList.add("hidden");
        }
    });

    selectedWrap.addEventListener("click", (e) => {
        const tag = e.target.closest("[data-value]");
        if (!tag) return;

        const val = tag.dataset.value;

        // remove
        if (e.target.closest(".promo-remove")) {
            const opt = Array.from(select.options).find(o => String(o.value) === String(val));
            if (opt) opt.selected = false;
            rebuildSelectedTags();
            return;
        }

        // reorder up/down: move option nodes in DOM (preserves order in POST)
        const opt = Array.from(select.options).find(o => String(o.value) === String(val));
        if (!opt) return;

        if (e.target.closest(".promo-up")) {
            const prev = opt.previousElementSibling;
            if (prev) select.insertBefore(opt, prev);
            rebuildSelectedTags();
            return;
        }

        if (e.target.closest(".promo-down")) {
            const next = opt.nextElementSibling;
            if (next) select.insertBefore(next, opt);
            rebuildSelectedTags();
            return;
        }
    });

    // initial
    rebuildSelectedTags();
    hydrateSelectedLabels();
}

function enhancePromotionSingleSelect(select) {
    if (!select || select.dataset.enhanced === "1") return;
    select.dataset.enhanced = "1";

    const searchUrl = select.dataset.searchUrl;
    const getUrlTpl = select.dataset.getUrl; // contains __ID__
    if (!searchUrl) return;

    select.style.display = "none";

    const wrapper = document.createElement("div");
    wrapper.className = "border rounded p-2 bg-white";

    const input = document.createElement("input");
    input.type = "text";
    input.className = "control w-full";
    input.placeholder = "Search promotion...";
    input.autocomplete = "off";

    const results = document.createElement("div");
    results.className = "border hidden bg-white rounded mt-1 max-h-40 overflow-auto";

    const picked = document.createElement("div");
    picked.className = "mt-2";

    select.parentNode.insertBefore(wrapper, select.nextSibling);
    wrapper.appendChild(input);
    wrapper.appendChild(results);
    wrapper.appendChild(picked);

    const metaBox = wrapper.closest(".mt-4")?.querySelector(".js-promo-meta") || null;

    function setValue(id, label) {
        id = String(id);

        select.innerHTML = "";

        const opt = document.createElement("option");
        opt.value = id;
        opt.text = label || ("#" + id);
        opt.selected = true;
        select.appendChild(opt);

        picked.innerHTML = `
            <div class="px-2 py-1 rounded bg-green-200 inline-flex items-center gap-2">
                <span>${escapeHtml(opt.text)}</span>
                <button type="button" class="promo-clear text-red-700 text-xs font-bold">✕</button>
            </div>
        `;

        if (metaBox && getUrlTpl) {
            const url = getUrlTpl.replace("__ID__", encodeURIComponent(id));

            fetch(url)
                .then(r => r.json())
                .then(p => {
                    const name = p?.name || ("#" + id);
                    const slug = p?.slug ? `Slug: ${p.slug}` : "";
                    const active = (p?.is_active !== undefined) ? `Active: ${p.is_active ? "Yes" : "No"}` : "";
                    const from = p?.from ? `From: ${p.from}` : "";
                    const to = p?.to ? `To: ${p.to}` : "";

                    metaBox.innerHTML = [name, slug, active, from, to].filter(Boolean).join(" • ");
                })
                .catch(() => {
                    metaBox.innerHTML = "";
                });
        }
    }

    function clearValue() {
        select.innerHTML = "";
        picked.innerHTML = "";
        if (metaBox) metaBox.innerHTML = "";
    }

    // If edit page has selected option already
    const existing = select.value ? String(select.value) : "";
    if (existing) {
        const existingOpt = Array.from(select.options).find(o => o.selected);
        setValue(existing, existingOpt?.text || ("#" + existing));
    }

    let t = null;

    input.addEventListener("input", () => {
        const q = input.value.trim();

        if (t) clearTimeout(t);

        if (q.length < 2) {
            results.innerHTML = "";
            results.classList.add("hidden");
            return;
        }

        t = setTimeout(async () => {
            try {
                const res = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}`);
                const items = await res.json();

                results.innerHTML = "";
                results.classList.remove("hidden");

                (items || []).forEach((p) => {
                    const div = document.createElement("div");
                    const label = p.name ? `${p.name} (#${p.id})` : `#${p.id}`;
                    div.className = "p-1 cursor-pointer hover:bg-gray-200 rounded";
                    div.textContent = label;
                    div.onclick = () => {
                        setValue(p.id, label);
                        results.innerHTML = "";
                        results.classList.add("hidden");
                        input.value = "";
                    };
                    results.appendChild(div);
                });
            } catch (err) {
                console.error("promotion search error:", err);
            }
        }, 150);
    });

    document.addEventListener("click", (e) => {
        if (!wrapper.contains(e.target)) {
            results.classList.add("hidden");
        }
    });

    picked.addEventListener("click", (e) => {
        if (e.target.closest(".promo-clear")) {
            clearValue();
        }
    });
}

function initPromotions() {
    document.querySelectorAll(".js-promotion-multiselect").forEach(enhancePromotionMultiSelect);
    document.querySelectorAll(".js-promotion-singeselect").forEach(enhancePromotionSingleSelect);
}

// Export init function to global like your other modules
window.initPromotions = initPromotions;

// auto-run on initial load
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPromotions);
} else {
    initPromotions();
}
