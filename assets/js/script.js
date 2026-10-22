/* =========================================================
   SMARTSTOCK AI
   APPLICATION JAVASCRIPT
   Pages are now real PHP pages, so this file only handles
   UI behaviour: sidebar, toasts, confirmations, charts,
   image preview and the order line-item editor.
========================================================= */

document.addEventListener("DOMContentLoaded", () => {
    initSidebar();
    initToasts();
    initConfirmForms();
    initAutoSubmitFilters();
    initImagePreview();
    initOrderItems();
    initSettingsTabs();
    initKeyboardShortcuts();
    initLoadingButtons();
    initCharts();
});


/* =========================================================
   MOBILE SIDEBAR
========================================================= */

function initSidebar() {
    const menuBtn = document.getElementById("menuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");

    if (!menuBtn || !sidebar || !overlay) return;

    menuBtn.addEventListener("click", () => {
        sidebar.classList.toggle("open");
        overlay.classList.toggle("show");
    });

    overlay.addEventListener("click", closeSidebar);
}

function closeSidebar() {
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");
    if (sidebar) sidebar.classList.remove("open");
    if (overlay) overlay.classList.remove("show");
}


/* =========================================================
   TOASTS
========================================================= */

function initToasts() {
    document.querySelectorAll("#toastStack .toast").forEach((toast, i) => {
        toast.querySelector(".toast-close")?.addEventListener("click", () => removeToast(toast));
        setTimeout(() => removeToast(toast), 4500 + i * 600);
    });
}

function removeToast(toast) {
    toast.classList.add("hide");
    setTimeout(() => toast.remove(), 300);
}

/* Show a toast from JavaScript */
function showToast(message, type = "success") {
    const stack = document.getElementById("toastStack");
    if (!stack) return;

    const icon = type === "error" ? "fa-xmark" : (type === "warning" ? "fa-exclamation" : "fa-check");
    const title = type === "error" ? "Error" : (type === "warning" ? "Warning" : "Success");

    const toast = document.createElement("div");
    toast.className = `toast show ${type}`;
    toast.innerHTML = `
        <div class="toast-icon"><i class="fa-solid ${icon}"></i></div>
        <div><strong>${title}</strong><span></span></div>
        <button type="button" class="toast-close"><i class="fa-solid fa-xmark"></i></button>`;
    toast.querySelector("span").textContent = message;
    toast.querySelector(".toast-close").addEventListener("click", () => removeToast(toast));

    stack.appendChild(toast);
    setTimeout(() => removeToast(toast), 4000);
}


/* =========================================================
   CONFIRM BEFORE DELETE / DANGEROUS ACTIONS
========================================================= */

function initConfirmForms() {
    document.querySelectorAll("form[data-confirm]").forEach(form => {
        form.addEventListener("submit", event => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
}


/* =========================================================
   FILTER DROPDOWNS SUBMIT THEIR FORM AUTOMATICALLY
========================================================= */

function initAutoSubmitFilters() {
    document.querySelectorAll("select[data-autosubmit]").forEach(select => {
        select.addEventListener("change", () => select.form.submit());
    });
}


/* =========================================================
   BUTTON LOADING STATE (e.g. Sync Inventory, Run Prediction)
========================================================= */

function initLoadingButtons() {
    document.querySelectorAll("form[data-loading]").forEach(form => {
        form.addEventListener("submit", () => {
            const button = form.querySelector("button[type=submit]");
            if (!button) return;
            button.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${form.dataset.loading}`;
            setTimeout(() => (button.disabled = true), 10);
        });
    });
}


/* =========================================================
   IMAGE PREVIEW ON FILE INPUT
========================================================= */

function initImagePreview() {
    document.querySelectorAll("input[type=file][data-preview]").forEach(input => {
        const img = document.getElementById(input.dataset.preview);
        if (!img) return;

        input.addEventListener("change", () => {
            const file = input.files[0];
            if (!file) return;
            img.src = URL.createObjectURL(file);
            img.closest(".image-preview")?.removeAttribute("hidden");
        });
    });
}


/* =========================================================
   ORDER LINE ITEMS (add/remove rows, live totals)
========================================================= */

function initOrderItems() {
    const table = document.getElementById("itemsTable");
    if (!table) return;

    const body = table.querySelector("tbody");
    const template = document.getElementById("itemRowTemplate");
    const addBtn = document.getElementById("addItemBtn");
    const totalEl = document.getElementById("orderTotal");
    const typeSelect = document.getElementById("order_type");

    const format = value =>
        (window.SS_CURRENCY || "$") +
        Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function recalc() {
        let total = 0;
        body.querySelectorAll("tr").forEach(row => {
            const qty = parseFloat(row.querySelector(".item-qty").value) || 0;
            const price = parseFloat(row.querySelector(".item-price").value) || 0;
            const line = qty * price;
            row.querySelector(".line-total").textContent = format(line);
            total += line;
        });
        totalEl.textContent = format(total);
    }

    /* Fill unit price automatically from product cost/sale price */
    function onProductChange(row) {
        const select = row.querySelector(".item-product");
        const priceInput = row.querySelector(".item-price");
        const option = select.options[select.selectedIndex];
        if (!option || !option.value) return;

        const isSale = typeSelect && typeSelect.value === "sale";
        const price = isSale ? option.dataset.price : option.dataset.cost;
        if (price !== undefined && (!priceInput.value || priceInput.dataset.auto === "1")) {
            priceInput.value = Number(price).toFixed(2);
            priceInput.dataset.auto = "1";
        }
        recalc();
    }

    function bindRow(row) {
        row.querySelector(".item-product").addEventListener("change", () => onProductChange(row));
        row.querySelector(".item-qty").addEventListener("input", recalc);
        row.querySelector(".item-price").addEventListener("input", e => {
            e.target.dataset.auto = "0";
            recalc();
        });
        row.querySelector(".remove-item").addEventListener("click", () => {
            if (body.querySelectorAll("tr").length > 1) {
                row.remove();
                recalc();
            } else {
                showToast("An order needs at least one item", "warning");
            }
        });
    }

    body.querySelectorAll("tr").forEach(bindRow);

    addBtn.addEventListener("click", () => {
        const row = template.content.firstElementChild.cloneNode(true);
        body.appendChild(row);
        bindRow(row);
        recalc();
    });

    /* Switching purchase/sale re-applies automatic prices and shows the right party field */
    if (typeSelect) {
        const toggleParty = () => {
            const isSale = typeSelect.value === "sale";

            /* swap the automatic order number PO-xxxx <-> SO-xxxx */
            const orderNo = document.getElementById("order_no");
            if (orderNo && orderNo.dataset.purchase) {
                if ([orderNo.dataset.purchase, orderNo.dataset.sale].includes(orderNo.value)) {
                    orderNo.value = orderNo.dataset[typeSelect.value];
                }
            }

            document.querySelectorAll("[data-show-for]").forEach(el => {
                el.hidden = el.dataset.showFor !== typeSelect.value;
            });
            body.querySelectorAll("tr").forEach(row => {
                const priceInput = row.querySelector(".item-price");
                if (priceInput.dataset.auto === "1" || !priceInput.value) {
                    priceInput.dataset.auto = "1";
                    onProductChange(row);
                }
            });
            return isSale;
        };
        typeSelect.addEventListener("change", toggleParty);
        toggleParty();
    }

    recalc();
}


/* =========================================================
   SETTINGS TABS
========================================================= */

function initSettingsTabs() {
    const tabs = document.querySelectorAll(".settings-tab[data-tab]");
    if (!tabs.length) return;

    tabs.forEach(tab => {
        tab.addEventListener("click", () => {
            tabs.forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            document.querySelectorAll("[data-tab-panel]").forEach(panel => {
                panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab;
            });
        });
    });
}


/* =========================================================
   KEYBOARD SHORTCUTS
========================================================= */

function initKeyboardShortcuts() {
    document.addEventListener("keydown", event => {
        if (event.key === "/" && !["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)) {
            const search = document.getElementById("globalSearch");
            if (search) {
                event.preventDefault();
                search.focus();
            }
        }
        if (event.key === "Escape") {
            closeSidebar();
        }
    });
}


/* =========================================================
   CHARTS (data comes from PHP via window.SS_CHARTS)
========================================================= */

const chartAxis = {
    x: { grid: { display: false }, ticks: { color: "#98a2b3", font: { size: 9 } } },
    y: { border: { display: false }, grid: { color: "#f0f2f5" }, ticks: { color: "#98a2b3", font: { size: 9 } } }
};

const shortNumber = value => (Math.abs(value) >= 1000 ? (value / 1000).toFixed(1).replace(/\.0$/, "") + "k" : value);

function initCharts() {
    if (typeof Chart === "undefined" || !window.SS_CHARTS) return;

    const data = window.SS_CHARTS;

    if (data.inventory) createInventoryChart(data.inventory);
    if (data.stockHealth) createStockHealthChart(data.stockHealth);
    if (data.prediction) createPredictionChart(data.prediction);
    if (data.supplier) createSupplierChart(data.supplier);
}

function createInventoryChart(cfg) {
    const canvas = document.getElementById("inventoryChart");
    if (!canvas) return;

    const ctx = canvas.getContext("2d");
    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, "rgba(99,91,255,.22)");
    gradient.addColorStop(1, "rgba(99,91,255,0)");

    new Chart(ctx, {
        type: "line",
        data: {
            labels: cfg.labels,
            datasets: [{
                label: "Stock Units",
                data: cfg.data,
                borderColor: "#635bff",
                backgroundColor: gradient,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: "#635bff",
                fill: true,
                tension: .4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: "#111827", padding: 10, titleFont: { size: 10 }, bodyFont: { size: 10 } }
            },
            scales: {
                x: chartAxis.x,
                y: { ...chartAxis.y, ticks: { ...chartAxis.y.ticks, callback: shortNumber } }
            }
        }
    });
}

function createStockHealthChart(cfg) {
    const canvas = document.getElementById("stockHealthChart");
    if (!canvas) return;

    new Chart(canvas.getContext("2d"), {
        type: "doughnut",
        data: {
            labels: cfg.labels,
            datasets: [{
                data: cfg.data,
                backgroundColor: ["#12b76a", "#f79009", "#f04438", "#98a2b3"],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "76%",
            plugins: { legend: { display: false } }
        }
    });
}

function createPredictionChart(cfg) {
    const canvas = document.getElementById("predictionChart");
    if (!canvas) return;

    new Chart(canvas.getContext("2d"), {
        type: "line",
        data: {
            labels: cfg.labels,
            datasets: [
                {
                    label: "Actual Demand",
                    data: cfg.actual,
                    borderColor: "#12b76a",
                    backgroundColor: "rgba(18,183,106,.06)",
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: "#12b76a",
                    fill: true,
                    tension: .35,
                    spanGaps: false
                },
                {
                    label: "Predicted Demand",
                    data: cfg.forecast,
                    borderColor: "#635bff",
                    backgroundColor: "rgba(99,91,255,.08)",
                    borderWidth: 2,
                    borderDash: [6, 4],
                    pointRadius: 4,
                    pointBackgroundColor: "#635bff",
                    fill: true,
                    tension: .35,
                    spanGaps: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: "bottom", labels: { boxWidth: 10, font: { size: 10 } } }
            },
            scales: { x: chartAxis.x, y: { ...chartAxis.y, beginAtZero: true } }
        }
    });
}

function createSupplierChart(cfg) {
    const canvas = document.getElementById("supplierChart");
    if (!canvas) return;

    new Chart(canvas.getContext("2d"), {
        type: "bar",
        data: {
            labels: cfg.labels,
            datasets: [
                { label: "On-time Delivery %", data: cfg.onTime, backgroundColor: "#635bff", borderRadius: 7 },
                { label: "Quality Score %", data: cfg.quality, backgroundColor: "#b0aaff", borderRadius: 7 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: "bottom", labels: { boxWidth: 10, font: { size: 10 } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: "#667085", font: { size: 9 } } },
                y: { beginAtZero: true, max: 100, grid: { color: "#f0f2f5" }, ticks: { color: "#98a2b3", font: { size: 9 } } }
            }
        }
    });
}
