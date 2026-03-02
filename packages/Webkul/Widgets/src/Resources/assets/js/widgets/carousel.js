console.log("widgets/carousel.js loaded");

/**
 * Keep hidden order inputs + link input names synced with DOM order.
 */
function refreshExistingOrderInputs(existingList) {
    if (!existingList) return;

    existingList.querySelectorAll(".carousel-item").forEach(item => {
        const path = item.dataset.imgPath || "";

        // hidden order input
        let hidden = item.querySelector('input[type="hidden"][name="config[images_order][]"]');
        if (!hidden) {
            hidden = document.createElement("input");
            hidden.type = "hidden";
            hidden.name = "config[images_order][]";
            item.appendChild(hidden);
        }
        hidden.value = path;

        // ensure link input name matches current path (important after reorder)
        const linkInput = item.querySelector('input.carousel-link');
        if (linkInput) {
            linkInput.name = `config[images_links][${path}]`;
        }

        // ensure remove checkbox value matches current path
        const removeChk = item.querySelector('input.carousel-remove');
        if (removeChk) {
            removeChk.value = path;
        }
    });
}

/**
 * Delegated handlers for EXISTING images (works after AJAX innerHTML).
 * Bound once globally.
 */
if (!window.__carouselExistingBound) {
    window.__carouselExistingBound = true;

    document.addEventListener("click", (e) => {
        const leftBtn  = e.target.closest(".carousel-move-left");
        const rightBtn = e.target.closest(".carousel-move-right");
        if (!leftBtn && !rightBtn) return;

        const item = e.target.closest(".carousel-item");
        const list = document.getElementById("carouselExistingList");
        if (!item || !list) return;

        if (leftBtn) {
            const prev = item.previousElementSibling;
            if (prev) list.insertBefore(item, prev);
        }

        if (rightBtn) {
            const next = item.nextElementSibling;
            if (next) list.insertBefore(next, item); // swap with next
        }

        refreshExistingOrderInputs(list);
    });

    document.addEventListener("change", (e) => {
        const chk = e.target.closest(".carousel-remove");
        if (!chk) return;

        const item = chk.closest(".carousel-item");
        if (!item) return;

        const linkInput = item.querySelector(".carousel-link");

        item.classList.toggle("opacity-50", chk.checked);

        // optional UX: disable link input when removing
        if (linkInput) {
            linkInput.disabled = chk.checked;
            linkInput.classList.toggle("bg-gray-100", chk.checked);
        }
    });
}

/**
 * Init hook for NEW uploads preview + reorder.
 * (Initialized per render because it stores state)
 */
function initCarousel() {
    const input = document.getElementById("carouselImagesInput");
    const newList = document.getElementById("carouselNewList");
    const existingList = document.getElementById("carouselExistingList");

    // Sync existing order inputs on load/reload
    if (existingList) refreshExistingOrderInputs(existingList);

    if (!input || !newList) return;

    // Prevent double binding for the new uploads area
    if (newList.dataset.bound === "1") return;
    newList.dataset.bound = "1";

    let files = [];

    const syncInputFiles = () => {
        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        input.files = dt.files;
    };

    const renderNew = () => {
        newList.innerHTML = "";

        files.forEach((file, idx) => {
            const wrap = document.createElement("div");
            wrap.className = "border rounded p-2 bg-white";
            wrap.dataset.index = String(idx);

            wrap.innerHTML = `
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="flex items-center gap-2">
                        <button type="button" class="new-move-left text-xs px-2 py-1 border rounded">←</button>
                        <button type="button" class="new-move-right text-xs px-2 py-1 border rounded">→</button>
                    </div>
                    <button type="button" class="new-remove text-xs text-red-700 font-bold" title="Remove">✕</button>
                </div>
            `;

            const img = document.createElement("img");
            img.className = "w-full rounded select-none";
            img.draggable = false;
            img.src = URL.createObjectURL(file);

            wrap.appendChild(img);
            newList.appendChild(wrap);
        });

        syncInputFiles();
    };

    input.addEventListener("change", () => {
        files = Array.from(input.files || []);
        renderNew();
    });

    newList.addEventListener("click", (e) => {
        const item = e.target.closest("[data-index]");
        if (!item) return;

        const idx = parseInt(item.dataset.index, 10);

        if (e.target.closest(".new-remove")) {
            files.splice(idx, 1);
            renderNew();
            return;
        }

        if (e.target.closest(".new-move-left") && idx > 0) {
            [files[idx - 1], files[idx]] = [files[idx], files[idx - 1]];
            renderNew();
            return;
        }

        if (e.target.closest(".new-move-right") && idx < files.length - 1) {
            [files[idx + 1], files[idx]] = [files[idx], files[idx + 1]];
            renderNew();
            return;
        }
    });
}

/* Auto-init on first load */
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCarousel);
} else {
    initCarousel();
}
