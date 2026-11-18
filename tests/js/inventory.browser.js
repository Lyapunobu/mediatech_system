// Run inside the rendered Inventory page using agent-browser eval --stdin.
// Requires inventory.read/create/update/export. No transaction writes are performed.
(async () => {
    const root = document.getElementById('inventory-app');
    if (!root) throw new Error('Open the Inventory page before running these checks.');
    const find = (id) => root.querySelector(`#${id}`);
    const checks = [];
    const assert = (name, condition) => {
        if (!condition) throw new Error(name);
        checks.push(name);
    };
    const click = (selector) => {
        const node = root.querySelector(selector);
        if (!node) throw new Error(`Missing control: ${selector}`);
        node.click();
    };
    const change = (id, value, type = 'change') => {
        find(id).value = value;
        find(id).dispatchEvent(new Event(type, { bubbles: true }));
    };
    const snapshot = () => JSON.stringify([
        find('inventory-sku-count').textContent, find('inventory-stock-total').textContent,
        find('inventory-low-count').textContent, find('inventory-stock-rows').textContent,
        find('movement-rows').textContent,
    ]);
    const nextFrame = () => new Promise((resolve) => requestAnimationFrame(resolve));
    // The embedded pane rounds rem-based geometry to fractional CSS pixels.
    const near = (actual, expected) => Math.abs(parseFloat(actual) - expected) < 0.05;
    const originalFetch = window.fetch;
    const originalSend = XMLHttpRequest.prototype.send;
    const originalCreateUrl = URL.createObjectURL;
    const originalAnchorClick = HTMLAnchorElement.prototype.click;
    const writeRequests = [];
    const csvs = [];
    window.fetch = function (input, init) {
        const method = String(init?.method ?? input?.method ?? 'GET').toUpperCase();
        if (!['GET', 'HEAD'].includes(method)) {
            writeRequests.push(method);
            throw new Error('Unexpected write request');
        }
        return originalFetch.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        writeRequests.push('XHR');
        throw new Error('Unexpected XHR');
    };
    URL.createObjectURL = (blob) => { csvs.push(blob); return 'blob:inventory-check'; };
    HTMLAnchorElement.prototype.click = function () {
        if (!this.download) return originalAnchorClick.apply(this, arguments);
    };
    const inputs = [...root.querySelectorAll('input, select, textarea')].map((node) => ({
        node, value: node.value, checked: node.checked,
    }));
    let extraRows = [];
    let printFrame;
    try {
        if (document.readyState !== 'complete') {
            await new Promise((resolve) => window.addEventListener('load', resolve, { once: true }));
        }
        await document.fonts.ready;
        await nextFrame();
        assert('no preview banner or preview actions', !/Preview UI|Pratinjau|data demo/i.test(root.textContent));
        assert('page title is 25px', getComputedStyle(root.querySelector('h1')).fontSize === '25px');
        const inheritedFamily = getComputedStyle(document.body).fontFamily;
        const heading = getComputedStyle(root.querySelector('h1'));
        assert('title inherits the layout font with the reference tabs 800 weight', heading.fontFamily === inheritedFamily && heading.fontWeight === '800');
        assert('Operations inherits the layout font', getComputedStyle(root.querySelector('p')).fontFamily === inheritedFamily);
        assert('all stat numbers use inherited extra-bold typography', [...root.querySelectorAll('.inventory-stat-value')].every((node) => getComputedStyle(node).fontFamily === inheritedFamily && getComputedStyle(node).fontWeight === '800'));
        assert('all stat captions use inherited regular typography', [...root.querySelectorAll('.inventory-stat-foot')].every((node) => getComputedStyle(node).fontFamily === inheritedFamily && getComputedStyle(node).fontWeight === '400'));
        assert('all modal headings inherit the same font and extra-bold weight', [...root.querySelectorAll('.ui-dialog-title')].every((node) => getComputedStyle(node).fontFamily === inheritedFamily && getComputedStyle(node).fontWeight === '800'));
        assert('Inventory has no page-specific font-face definitions', ![...document.querySelectorAll('style')].some((node) => /@font-face/.test(node.textContent)));
        const outButton = root.querySelector('[data-open-dialog="goods-out-dialog"]');
        const inButton = root.querySelector('[data-open-dialog="goods-in-dialog"]');
        assert('both transaction buttons exist', outButton && inButton);
        await Promise.all(inButton.getAnimations().map((animation) => animation.finished));
        assert('primary button is Meditech blue', getComputedStyle(inButton).backgroundColor === (inButton.matches(':hover') ? 'rgb(9, 23, 114)' : 'rgb(13, 33, 161)'));
        assert('secondary button uses neutral text and border', getComputedStyle(outButton).color === 'rgb(82, 82, 82)' && getComputedStyle(outButton).borderTopColor === 'rgb(229, 229, 229)');
        assert('button text is 12px', getComputedStyle(inButton).fontSize === '12px');
        for (const button of [inButton, outButton]) {
            const icon = button.querySelector('svg');
            const symbol = document.querySelector(icon?.querySelector('use')?.getAttribute('href'));
            assert('action icon resolves at 16px', symbol && near(getComputedStyle(icon).width, 16) && near(getComputedStyle(icon).height, 16));
        }
        assert('filter is 36px', near(find('inventory-search').getBoundingClientRect().height, 36));
        assert('table body is 12px', getComputedStyle(find('inventory-stock-rows').querySelector('td')).fontSize === '12px');
        assert('table heading is 11px', getComputedStyle(find('inventory-stock-rows').closest('table').querySelector('th')).fontSize === '11px');

        change('inventory-stock-size', '5');
        assert('stock pagination first page', find('inventory-stock-rows').rows.length === 5);
        click('#inventory-stock-next');
        assert('stock pagination next page', find('inventory-stock-rows').rows.length === 4);
        change('inventory-search', 'laptop', 'input');
        assert('search resets stale page', find('inventory-stock-rows').rows.length === 2 && find('inventory-stock-page').textContent === 'Halaman 1/1');
        change('inventory-warehouse', 'Gudang Jakarta');
        assert('warehouse combines with search', find('inventory-stock-rows').rows.length === 1 && find('inventory-stock-rows').textContent.includes('PRD-003'));
        change('inventory-search', '<not-a-product>', 'input');
        assert('empty filtered state', find('inventory-stock-rows').querySelector('[data-empty]'));
        change('inventory-search', '', 'input');
        change('inventory-warehouse', '');
        change('inventory-stock-size', '10');
        const before = snapshot();
        const pageBox = root.getBoundingClientRect();
        const pageWidth = document.documentElement.clientWidth;
        const pageOverflow = getComputedStyle(document.body).overflowY;
        const assertStablePage = (state) => {
            const box = root.getBoundingClientRect();
            assert(`${state} preserves the scrollbar and page width`,
                document.documentElement.clientWidth === pageWidth
                && near(box.width, pageBox.width) && near(box.left, pageBox.left)
                && getComputedStyle(document.body).overflowY === pageOverflow);
        };

        click('[data-open-dialog="goods-in-dialog"]');
        assert('Goods In opens its form', find('goods-in-dialog').open);
        assertStablePage('Goods In');
        assert('receipt has six editable fields', find('goods-in-form').querySelectorAll('input, select').length === 6);
        assert('receipt quantity defaults to one', find('receipt-quantity').value === '1');
        assert('modal title is 17px', getComputedStyle(find('goods-in-dialog-title')).fontSize === '17px');
        assert('modal uses 600px cap', getComputedStyle(find('goods-in-dialog')).maxWidth === '600px');
        assert('modal close is 30px', near(find('goods-in-dialog').querySelector('[data-close-dialog]').getBoundingClientRect().width, 30));
        find('goods-in-form').requestSubmit();
        assert('receipt rejects missing reference', find('goods-in-dialog').open && !find('receipt-reference').checkValidity());
        change('receipt-reference', '  ', 'input');
        find('goods-in-form').requestSubmit();
        assert('receipt rejects whitespace reference', find('goods-in-dialog').open && !find('receipt-reference').checkValidity());
        change('receipt-reference', 'GR-CHECK', 'input');
        change('receipt-quantity', '0', 'input');
        find('goods-in-form').requestSubmit();
        assert('receipt rejects zero quantity', find('goods-in-dialog').open && !find('receipt-quantity').checkValidity());
        change('receipt-quantity', '2.5', 'input');
        change('receipt-notes', '<img src=x onerror=alert(1)>', 'input');
        find('goods-in-form').requestSubmit();
        assert('receipt confirmation dismisses without changing data', !find('goods-in-dialog').open && snapshot() === before);
        assertStablePage('Receipt confirmation dismissal');
        click('[data-open-dialog="goods-in-dialog"]');
        assert('reopening retains receipt inputs', find('receipt-reference').value === 'GR-CHECK' && find('receipt-quantity').value === '2.5');
        click('#goods-in-dialog [data-close-dialog]');
        assert('receipt close control works', !find('goods-in-dialog').open);
        assertStablePage('Receipt close');

        click('[data-open-dialog="goods-out-dialog"]');
        assert('Goods Out opens SO form', find('goods-out-dialog').open);
        assertStablePage('Goods Out');
        assert('Goods Out retains six columns including SKU', find('issue-lines').closest('table').tHead.rows[0].cells.length === 6);
        const rows = [...find('issue-lines').querySelectorAll('[data-issue-row]')];
        assert('source SO contains two stock items', rows.length === 2);
        const quantity = rows[0].querySelector('[type="number"]');
        assert('outstanding order limits default quantity', quantity.value === '6' && quantity.max === '6');
        rows.forEach((row) => {
            const box = row.querySelector('[type="checkbox"]');
            box.checked = false;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });
        find('goods-out-form').requestSubmit();
        assert('empty dispatch selection is rejected', !find('issue-error').hidden && !find('goods-out-confirm-dialog').open);
        const firstBox = rows[0].querySelector('[type="checkbox"]');
        firstBox.checked = true;
        firstBox.dispatchEvent(new Event('change', { bubbles: true }));
        quantity.value = '7';
        find('goods-out-form').requestSubmit();
        assert('over-quantity stays in form', find('goods-out-dialog').open && !quantity.checkValidity());
        quantity.value = '3';
        const notes = '<script>window.inventoryInjected=true</script>';
        change('issue-notes', notes, 'input');
        find('goods-out-form').requestSubmit();
        assert('valid partial dispatch opens final confirmation', !find('goods-out-dialog').open && find('goods-out-confirm-dialog').open);
        assertStablePage('Dispatch confirmation');
        assert('summary contains only selected item and entered quantity', find('issue-confirm-rows').rows.length === 1 && find('issue-confirm-rows').textContent.includes('3 unit'));
        assert('notes render as literal text', find('issue-confirm-notes').textContent === notes && !find('issue-confirm-notes').querySelector('script') && !window.inventoryInjected);
        click('[data-confirm-back]');
        assert('back preserves item selections, quantities and notes', find('goods-out-dialog').open && quantity.value === '3' && !rows[1].querySelector('[type="checkbox"]').checked && find('issue-notes').value === notes);
        assertStablePage('Return to dispatch form');

        extraRows = Array.from({ length: 10 }, () => rows[0].cloneNode(true));
        find('issue-lines').append(...extraRows);
        change('inventory-issue-size', '5');
        click('#inventory-issue-next');
        assert('form pagination keeps off-page inputs', rows[0].hidden && quantity.value === '3' && firstBox.checked);
        change('inventory-issue-size', '10');
        assert('row-size change preserves fields', !rows[0].hidden && quantity.value === '3' && firstBox.checked);
        extraRows.forEach((row) => row.remove());
        extraRows = [];
        change('inventory-issue-size', '10');
        find('goods-out-form').requestSubmit();
        click('#goods-out-confirm-dialog button[type="submit"]');
        assert('final confirmation dismisses without changing stock or movements', !find('goods-out-confirm-dialog').open && snapshot() === before);
        assertStablePage('Dispatch confirmation dismissal');
        click('[data-open-dialog="goods-out-dialog"]');
        assert('reopening dispatch does not duplicate or reset rows', find('issue-lines').rows.length === 2 && quantity.value === '3');
        click('#goods-out-dialog [data-close-dialog]');

        click('[data-open-dialog="movements-dialog"]');
        assert('movement dialog opens with unchanged empty state', find('movements-dialog').open && find('movement-rows').querySelector('[data-empty]'));
        assertStablePage('Stock Movement');
        change('movement-search', 'anything', 'input');
        assert('movement filtering and empty pagination work', find('inventory-movement-page').textContent === 'Halaman 1/1');
        click('#movements-dialog [data-close-dialog]');
        assertStablePage('Stock Movement close');

        change('inventory-stock-size', '5');
        click('[data-export-table="inventory-stock-rows"]');
        assert('CSV exports all filtered rows, not only visible page', (await csvs.at(-1).text()).split('\r\n').length === 10);
        change('inventory-search', 'Laptop', 'input');
        click('[data-export-table="inventory-stock-rows"]');
        assert('CSV respects search', (await csvs.at(-1).text()).split('\r\n').length === 3);
        click('[data-print-table="inventory-stock-rows"]');
        printFrame = [...document.querySelectorAll('iframe')].find((frame) => frame.title.startsWith('Cetak '));
        assert('print is scoped to filtered table, not dashboard', printFrame && printFrame.contentDocument.querySelectorAll('tbody tr').length === 3 && !printFrame.contentDocument.querySelector('aside, nav, button'));
        let printed = false;
        printFrame.contentWindow.print = () => { printed = true; };
        await new Promise((resolve, reject) => {
            const deadline = performance.now() + 5000;
            const check = () => {
                if (printed) return resolve();
                if (performance.now() > deadline) return reject(new Error('Print did not become ready.'));
                requestAnimationFrame(check);
            };
            check();
        });
        assert('print control reaches browser print', printed);
        assert('print reuses the existing app font without a separate heading family', getComputedStyle(printFrame.contentDocument.body).fontFamily === getComputedStyle(printFrame.contentDocument.querySelector('h1')).fontFamily && [...printFrame.contentDocument.fonts].some((face) => face.family === 'Inter' && face.status === 'loaded'));
        printFrame.remove();
        change('inventory-search', '', 'input');
        change('inventory-stock-size', '10');
        assert('no fetch/XHR writes occurred', writeRequests.length === 0);
        return { passed: checks.length, checks, viewport: { width: innerWidth, height: innerHeight } };
    } finally {
        window.fetch = originalFetch;
        XMLHttpRequest.prototype.send = originalSend;
        URL.createObjectURL = originalCreateUrl;
        HTMLAnchorElement.prototype.click = originalAnchorClick;
        extraRows.forEach((row) => row.remove());
        printFrame?.remove();
        root.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
        for (const { node, value, checked } of inputs) {
            node.value = value;
            if (node.type === 'checkbox') node.checked = checked;
            node.dispatchEvent(new Event(node.matches('[type="search"]') ? 'input' : 'change', { bubbles: true }));
        }
    }
})();
