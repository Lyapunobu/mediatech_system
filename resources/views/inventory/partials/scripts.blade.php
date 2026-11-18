<script type="module">
function initializeInventory(root) {
    const find = (id) => root.querySelector(`#${id}`);
    const format = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
    const clone = (id) => find(id).content.firstElementChild.cloneNode(true);
    const setFields = (node, fields) => {
        for (const [key, value] of Object.entries(fields)) node.querySelector(`[data-field="${key}"]`).textContent = value;
        return node;
    };
    const stockState = { page: 1, size: 10 };
    const movementState = { page: 1, size: 10 };
    const dialogTriggers = new WeakMap();

    function emptyRow(columns, message) {
        const row = setFields(clone('inventory-empty-row'), { message });
        row.firstElementChild.colSpan = columns;
        return row;
    }

    find('inventory-sku-count').textContent = format(new Set(stocks.map((row) => row.sku)).size);
    find('inventory-stock-total').textContent = format(stocks.reduce((sum, row) => sum + row.qty, 0));
    find('inventory-low-count').textContent = `${format(new Set(stocks.filter((row) => row.qty < row.min).map((row) => row.sku)).size)} SKU`;
    find('inventory-warehouse-count').textContent = `Di ${new Set(stocks.map((row) => row.warehouse)).size} gudang`;

    function updatePager(prefix, state, data) {
        state.page = data.page;
        find(`${prefix}-count`).textContent = data.total ? `Menampilkan ${data.start}–${data.end} dari ${data.total} data` : 'Tidak ada data';
        find(`${prefix}-page`).textContent = `Halaman ${data.page}/${data.pages}`;
        find(`${prefix}-prev`).disabled = data.page <= 1;
        find(`${prefix}-next`).disabled = data.page >= data.pages;
    }

    function bindPager(prefix, state, render) {
        find(`${prefix}-size`).addEventListener('change', (event) => {
            state.size = Number(event.target.value);
            state.page = 1;
            render();
        });
        for (const [direction, increment] of [['prev', -1], ['next', 1]]) {
            find(`${prefix}-${direction}`).addEventListener('click', () => { state.page += increment; render(); });
        }
    }

    // Hide existing form rows instead of rebuilding them, preserving every page's inputs.
    function bindRowPager(prefix, bodyId) {
        const state = { page: 1, size: 10 };
        const rows = () => [...find(bodyId).children].filter((row) => !row.hasAttribute('data-empty'));
        function render() {
            const all = rows();
            const data = paginate(all, state.page, state.size);
            const visible = new Set(data.rows);
            all.forEach((row) => { row.hidden = !visible.has(row); });
            updatePager(prefix, state, data);
        }
        bindPager(prefix, state, render);
        return {
            reset() { state.page = 1; render(); },
            show(row) { state.page = Math.floor(rows().indexOf(row) / state.size) + 1; render(); },
        };
    }

    function filteredStocks() {
        return filterStocks(stocks, {
            search: find('inventory-search').value,
            warehouse: find('inventory-warehouse').value,
        });
    }

    function renderStocks() {
        const data = paginate(filteredStocks(), stockState.page, stockState.size);
        const rows = data.rows.map((stock) => {
            const row = setFields(clone('inventory-stock-row'), {
                sku: stock.sku, name: stock.name, category: stock.category, warehouse: stock.warehouse,
                qty: `${format(stock.qty)} unit`, min: `${format(stock.min)} unit`,
            });
            row.querySelector('[data-field="status"]').append(clone(stock.qty < stock.min ? 'inventory-low-badge' : 'inventory-available-badge'));
            return row;
        });
        find('inventory-stock-rows').replaceChildren(...(rows.length ? rows : [emptyRow(7, 'Tidak ada barang yang sesuai. Coba kata kunci atau filter lain.')]));
        updatePager('inventory-stock', stockState, data);
    }

    function filteredMovements() {
        const query = find('movement-search').value.trim().toLowerCase();
        return movements.filter((row) => `${row.reference} ${row.detail}`.toLowerCase().includes(query));
    }

    function renderMovements() {
        const data = paginate(filteredMovements(), movementState.page, movementState.size);
        const rows = data.rows.map((row) => setFields(clone('inventory-movement-row'), {
            time: row.time, reference: row.reference, action: row.action, detail: row.detail,
        }));
        find('movement-rows').replaceChildren(...(rows.length ? rows : [emptyRow(4, 'Belum ada movement baru di sesi ini.')]));
        updatePager('inventory-movement', movementState, data);
    }

    function openDialog(dialog, trigger) {
        dialogTriggers.set(dialog, trigger);
        dialog.showModal();
    }

    function tableData(target) {
        const table = find(target).closest('table');
        const header = [...table.tHead.rows[0].cells].map((cell) => cell.textContent.trim());
        let rows;
        if (target === 'inventory-stock-rows') {
            rows = filteredStocks().map((row) => [
                row.sku, row.name, row.category, row.warehouse, `${format(row.qty)} unit`, `${format(row.min)} unit`,
                row.qty < row.min ? 'Low stock' : 'Available',
            ]);
        } else if (target === 'movement-rows') {
            rows = filteredMovements().map((row) => [row.time, row.reference, row.action, row.detail]);
        } else {
            rows = [...find(target).rows].filter((row) => !row.hasAttribute('data-empty')).map((row) => [...row.cells].map((cell) => {
                const input = cell.querySelector('input, select');
                if (input) return input.type === 'checkbox' ? (input.checked ? 'Ya' : 'Tidak') : input.value;
                return [...cell.childNodes].map((node) => node.textContent).join(' ').trim().replace(/\s+/g, ' ');
            }));
        }
        return { title: table.caption.textContent.trim(), rows: [header, ...rows] };
    }

    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-open-dialog]');
        if (opener) {
            const dialog = find(opener.dataset.openDialog);
            if (dialog) openDialog(dialog, opener);
        }
        const closer = event.target.closest('[data-close-dialog]');
        if (closer) closer.closest('dialog').close();
        const exporter = event.target.closest('[data-export-table]');
        const printer = event.target.closest('[data-print-table]');
        if (exporter || printer) {
            const data = tableData(exporter?.dataset.exportTable ?? printer.dataset.printTable);
            if (exporter) downloadCsv(data.title, data.rows);
            else printTable(data.title, data.rows);
        }
    });
    root.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
        dialog.addEventListener('close', () => {
            if (!root.querySelector('dialog[open]')) dialogTriggers.get(dialog)?.focus();
        });
    });

    const receiptForm = find('goods-in-form');
    if (receiptForm) {
        find('receipt-product').replaceChildren(...stocks.map((row) => new Option(`${row.sku} — ${row.name}`, row.sku)));
        receiptForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(receiptForm);
            const product = stocks.find((row) => row.sku === data.get('product'));
            const quantity = Number(data.get('quantity'));
            const reference = data.get('reference').trim();
            find('receipt-reference').setCustomValidity(reference ? '' : 'Nomor dokumen wajib diisi.');
            find('receipt-quantity').setCustomValidity(Number.isFinite(quantity) && quantity > 0 ? '' : 'Jumlah harus lebih dari nol.');
            if (!product || !receiptForm.reportValidity()) return;
            // Frontend boundary: no write request or stock mutation until backend integration.
            find('goods-in-dialog').close();
        });
        for (const id of ['receipt-reference', 'receipt-quantity']) find(id).addEventListener('input', () => find(id).setCustomValidity(''));
    }

    const issueForm = find('goods-out-form');
    if (issueForm) {
        const issuePager = bindRowPager('inventory-issue', 'issue-lines');
        const confirmPager = bindRowPager('inventory-confirm', 'issue-confirm-rows');
        const confirmDialog = find('goods-out-confirm-dialog');
        const selectedOrder = () => orders.find((order) => order.id === find('issue-order').value);
        find('issue-order').replaceChildren(...orders.map((order) => new Option(`${order.id} — ${order.customer}`, order.id)));

        function renderIssueLines() {
            find('issue-error').hidden = true;
            const rows = (selectedOrder()?.items ?? []).filter((item) => item.ordered > item.delivered).map((item, index) => {
                const available = stocks.filter((row) => row.sku === item.sku && row.qty > 0);
                const limit = shipmentLimit(item, available[0]);
                const row = setFields(clone('inventory-issue-row'), {
                    sku: item.sku, name: item.name, remaining: `Sisa pesanan: ${format(item.ordered - item.delivered)} unit`,
                });
                row.dataset.sku = item.sku;
                row.querySelector('[data-available]').textContent = `${format(available[0]?.qty ?? 0)} unit`;
                const checkbox = row.querySelector('[type="checkbox"]');
                checkbox.name = `include_${index}`;
                checkbox.setAttribute('aria-label', `Pilih ${item.name}`);
                checkbox.checked = limit > 0;
                checkbox.disabled = limit <= 0;
                const quantity = row.querySelector('[type="number"]');
                quantity.name = `quantity_${index}`;
                quantity.setAttribute('aria-label', `Jumlah keluar ${item.sku}`);
                quantity.max = limit;
                quantity.value = limit;
                quantity.disabled = limit <= 0;
                const warehouse = row.querySelector('select');
                warehouse.name = `warehouse_${index}`;
                warehouse.setAttribute('aria-label', `Gudang ${item.sku}`);
                warehouse.disabled = limit <= 0;
                warehouse.replaceChildren(...(available.length ? available.map((stock) => new Option(stock.warehouse)) : [new Option('Stok kosong')]));
                return row;
            });
            find('issue-lines').replaceChildren(...(rows.length ? rows : [emptyRow(6, 'Tidak ada produk yang masih perlu dikirim.')]));
            issuePager.reset();
        }

        find('issue-order').addEventListener('change', renderIssueLines);
        find('issue-lines').addEventListener('change', (event) => {
            const row = event.target.closest('[data-issue-row]');
            if (!row) return;
            const checkbox = row.querySelector('[type="checkbox"]');
            const quantity = row.querySelector('[type="number"]');
            const warehouse = row.querySelector('select');
            const item = selectedOrder().items.find((entry) => entry.sku === row.dataset.sku);
            const stock = stocks.find((entry) => entry.sku === row.dataset.sku && entry.warehouse === warehouse.value);
            const limit = shipmentLimit(item, stock);
            quantity.disabled = !checkbox.checked || limit <= 0;
            if (event.target === warehouse) {
                quantity.max = limit;
                quantity.value = Math.min(Number(quantity.value), limit);
                row.querySelector('[data-available]').textContent = `${format(stock?.qty ?? 0)} unit`;
            }
            find('issue-error').hidden = true;
        });
        issueForm.addEventListener('submit', (event) => {
            event.preventDefault();
            // Reveal an invalid field's page before the browser tries to focus it.
            if (!issueForm.checkValidity()) {
                const invalid = issueForm.querySelector(':invalid');
                if (invalid.closest('[data-issue-row]')) issuePager.show(invalid.closest('tr'));
                issueForm.reportValidity();
                return;
            }
            const order = selectedOrder();
            const selections = [...find('issue-lines').querySelectorAll('[data-issue-row]')]
                .filter((row) => row.querySelector('[type="checkbox"]').checked)
                .map((row) => ({
                    sku: row.dataset.sku,
                    name: order.items.find((item) => item.sku === row.dataset.sku).name,
                    quantity: Number(row.querySelector('[type="number"]').value),
                    warehouse: row.querySelector('select').value,
                }));
            const error = validateShipment(order, selections, stocks);
            find('issue-error').textContent = error;
            find('issue-error').hidden = !error;
            if (error) return;
            find('issue-confirm-order').textContent = order.id;
            find('issue-confirm-customer').textContent = order.customer;
            find('issue-confirm-notes').textContent = find('issue-notes').value.trim() || '—';
            find('issue-confirm-rows').replaceChildren(...selections.map((row) => setFields(clone('inventory-confirm-row'), {
                ...row, quantity: `${format(row.quantity)} unit`,
            })));
            confirmPager.reset();
            const source = find('goods-out-dialog');
            source.close();
            openDialog(confirmDialog, dialogTriggers.get(source));
        });
        confirmDialog.querySelector('[data-confirm-back]').addEventListener('click', () => {
            confirmDialog.close();
            find('goods-out-dialog').showModal();
        });
        renderIssueLines();
    }

    for (const id of ['inventory-search', 'inventory-warehouse']) {
        find(id).addEventListener(id === 'inventory-search' ? 'input' : 'change', () => { stockState.page = 1; renderStocks(); });
    }
    find('movement-search').addEventListener('input', () => { movementState.page = 1; renderMovements(); });
    bindPager('inventory-stock', stockState, renderStocks);
    bindPager('inventory-movement', movementState, renderMovements);
    renderStocks();
    renderMovements();
}

function filterStocks(rows, { search = '', warehouse = '' } = {}) {
    const query = search.trim().toLocaleLowerCase('id-ID');
    return rows.filter((row) => (
        `${row.sku} ${row.name}`.toLocaleLowerCase('id-ID').includes(query)
        && (!warehouse || row.warehouse === warehouse)
    ));
}

function paginate(rows, page = 1, size = 10) {
    const perPage = [5, 10, 25, 50].includes(Number(size)) ? Number(size) : 10;
    const pages = Math.max(1, Math.ceil(rows.length / perPage));
    const current = Math.max(1, Math.min(pages, Math.trunc(Number(page)) || 1));
    const start = (current - 1) * perPage;
    return { rows: rows.slice(start, start + perPage), page: current, pages, total: rows.length, start: rows.length ? start + 1 : 0, end: Math.min(start + perPage, rows.length) };
}

function shipmentLimit(item, stock) {
    return Math.max(0, Math.min(Number(stock?.qty ?? 0), Number(item.ordered) - Number(item.delivered)));
}

function validateShipment(order, selections, stockRows) {
    if (!order || !selections.length) return 'Pilih minimal satu barang untuk dikeluarkan.';
    const usedStock = new Map();
    const usedItems = new Map();
    for (const row of selections) {
        const item = order.items.find((entry) => entry.sku === row.sku);
        const stock = stockRows.find((entry) => entry.sku === row.sku && entry.warehouse === row.warehouse);
        const quantity = Number(row.quantity);
        if (!item || !stock || !Number.isFinite(quantity) || quantity <= 0) return 'Barang, gudang, atau jumlah keluar tidak valid.';
        const stockKey = `${row.sku}|${row.warehouse}`;
        const stockTotal = (usedStock.get(stockKey) ?? 0) + quantity;
        const itemTotal = (usedItems.get(row.sku) ?? 0) + quantity;
        if (stockTotal > stock.qty || itemTotal > item.ordered - item.delivered) return 'Jumlah keluar melebihi stok tersedia atau sisa pesanan.';
        usedStock.set(stockKey, stockTotal);
        usedItems.set(row.sku, itemTotal);
    }
    return '';
}

function toCsv(rows) {
    return '\uFEFF' + rows.map((row) => row.map((value) => {
        const text = String(value ?? '');
        const safe = typeof value === 'string' && /^\s*[=+\-@\t\r]/.test(text) ? `'${text}` : text;
        return `"${safe.replaceAll('"', '""')}"`;
    }).join(',')).join('\r\n');
}

function downloadCsv(title, rows) {
    const url = URL.createObjectURL(new Blob([toCsv(rows)], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${title}.csv`;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function printTable(title, rows) {
    const frame = document.createElement('iframe');
    frame.title = `Cetak ${title}`;
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.style.cssText = 'position:fixed;width:0;height:0;border:0';
    document.body.append(frame);
    const doc = frame.contentDocument;
    doc.title = title;
    const style = doc.createElement('style');
    style.textContent = '@font-face{font-family:Inter;src:url("/fonts/inter/Inter-Variable.woff2");font-weight:100 900}@page{size:landscape;margin:15mm}body{font:12px/1.5 Inter,sans-serif;color:#262626}h1{font-size:18px;font-weight:800}table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #e5e5e5;text-align:left}th{background:#fafafa}';
    doc.head.append(style);
    const heading = doc.createElement('h1');
    heading.textContent = title;
    const table = doc.createElement('table');
    rows.forEach((values, index) => {
        const row = table.insertRow();
        for (const value of values) {
            const cell = doc.createElement(index === 0 ? 'th' : 'td');
            cell.textContent = value;
            row.append(cell);
        }
    });
    doc.body.replaceChildren(heading, table);
    frame.contentWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
    setTimeout(() => frame.remove(), 60000);
    doc.fonts.load('800 18px Inter').then(() => doc.fonts.ready).then(() => requestAnimationFrame(() => {
        if (!frame.isConnected) return;
        frame.contentWindow.focus();
        frame.contentWindow.print();
    })).catch(() => frame.remove());
}

// Static inputs from index-meditech-v6.html, replaced when the backend is connected.
const stocks = [
    ['PRD-001', 'PC Desktop Acer Veriton X', 'Hardware', 'Gudang Utama', 42, 20],
    ['PRD-002', 'Laptop Lenovo ThinkPad E14', 'Hardware', 'Gudang Utama', 9, 15],
    ['PRD-003', 'Laptop Dell Latitude 3440', 'Hardware', 'Gudang Jakarta', 18, 10],
    ['PRD-005', 'Printer HP LaserJet Pro M404dn', 'Printer', 'Gudang Utama', 6, 10],
    ['PRD-006', 'Printer Epson EcoTank L3250', 'Printer', 'Gudang Jakarta', 25, 10],
    ['PRD-008', 'Projector Epson EB-X06', 'Projector', 'Gudang Utama', 5, 8],
    ['PRD-009', 'Projector InFocus IN114xa', 'Projector', 'Gudang Jakarta', 12, 6],
    ['PRD-011', 'Kamera CCTV Hikvision 2MP', 'CCTV & Networking', 'Gudang Utama', 14, 24],
    ['PRD-012', 'NVR Hikvision 16 Channel', 'CCTV & Networking', 'Gudang Utama', 11, 5],
].map(([sku, name, category, warehouse, qty, min]) => ({ sku, name, category, warehouse, qty, min }));

const orders = [{
    id: 'SO-2026-031',
    customer: 'PT Cipta Distribusi Nusantara',
    items: [
        { sku: 'PRD-011', name: 'Kamera CCTV Hikvision 2MP', ordered: 16, delivered: 10 },
        { sku: 'PRD-012', name: 'NVR Hikvision 16 Channel', ordered: 1, delivered: 0 },
    ],
}];
const movements = [];

const app = document.getElementById('inventory-app');
if (app) initializeInventory(app);
</script>
