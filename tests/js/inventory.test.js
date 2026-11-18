import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/inventory/partials/scripts.blade.php', import.meta.url), 'utf8');
const script = source.match(/<script type="module">([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'the Blade partial must contain its page-scoped script');
const { stocks, orders, filterStocks, paginate, shipmentLimit, validateShipment, toCsv } = runInNewContext(
    `'use strict';\n${script}\n;({ stocks, orders, filterStocks, paginate, shipmentLimit, validateShipment, toCsv });`,
    { document: { getElementById: () => null } },
    { filename: 'inventory/partials/scripts.blade.php' },
);

test('the page script has no external module or Vite imports', () => {
    assert.doesNotMatch(script, /^\s*import\s/m);
    assert.doesNotMatch(source, /<script[^>]+\bsrc=/);
});

test('source fixture totals reflect the actual nine stock positions, not static headline values', () => {
    assert.equal(stocks.length, 9);
    assert.equal(stocks.reduce((sum, row) => sum + row.qty, 0), 142);
    assert.equal(stocks.filter((row) => row.qty < row.min).length, 4);
    assert.equal(new Set(stocks.map((row) => row.warehouse)).size, 2);
});

test('search and warehouse filters combine and support no results', () => {
    assert.equal(filterStocks(stocks, { search: '  lApToP ' }).length, 2);
    assert.equal(filterStocks(stocks, { search: 'PRD-003', warehouse: 'Gudang Jakarta' }).length, 1);
    assert.equal(filterStocks(stocks, { search: 'PRD-002', warehouse: 'Gudang Jakarta' }).length, 0);
    assert.equal(filterStocks(stocks, { search: '<script>' }).length, 0);
});

test('pagination retains filtered input, handles empty results and clamps stale pages', () => {
    const first = paginate(stocks, 1, 5);
    const second = paginate(stocks, 2, 5);
    assert.equal(first.rows.length, 5);
    assert.equal(second.rows.length, 4);
    assert.equal(second.start, 6);
    assert.equal(second.end, 9);
    assert.equal(paginate(filterStocks(stocks, { search: 'laptop' }), 2, 5).page, 1);
    assert.equal(paginate([], 9, 5).start, 0);
    assert.equal(paginate([], 9, 5).pages, 1);
    assert.equal(paginate(stocks, 1, -5).rows.length, 9);
});

test('dispatch quantity is limited by both stock and outstanding order quantity', () => {
    assert.equal(shipmentLimit({ ordered: 16, delivered: 10 }, { qty: 14 }), 6);
    assert.equal(shipmentLimit({ ordered: 16, delivered: 10 }, { qty: 2 }), 2);
    assert.equal(shipmentLimit({ ordered: 16, delivered: 16 }, { qty: 14 }), 0);
    assert.equal(shipmentLimit({ ordered: 16, delivered: 10 }, undefined), 0);
});

test('shipment validation accepts partial selections but rejects empty, invalid and over-allocated lines', () => {
    const row = { sku: 'PRD-011', warehouse: 'Gudang Utama', quantity: 3 };
    const snapshot = JSON.stringify({ stocks, orders });
    assert.equal(validateShipment(orders[0], [row], stocks), '');
    assert.notEqual(validateShipment(orders[0], [], stocks), '');
    for (const quantity of [0, -1, NaN, Infinity, 7]) {
        assert.notEqual(validateShipment(orders[0], [{ ...row, quantity }], stocks), '');
    }
    assert.notEqual(validateShipment(orders[0], [{ ...row, warehouse: 'Gudang Jakarta' }], stocks), '');
    assert.notEqual(validateShipment(orders[0], [{ ...row, quantity: 4 }, { ...row, quantity: 4 }], stocks), '');
    assert.equal(JSON.stringify({ stocks, orders }), snapshot, 'validation must not mutate stock or orders');
});

test('CSV is Excel-readable, quotes commas/newlines and neutralizes spreadsheet formulas', () => {
    assert.equal(toCsv([['SKU', 'Nama'], ['PRD-001', 'A, "B"\nC']]),
        '\uFEFF"SKU","Nama"\r\n"PRD-001","A, ""B""\nC"');
    assert.equal(toCsv([['=1+1', '+CMD', '-CMD', '@SUM(A1)', '\t=1', '  =1', -2, null]]),
        '\uFEFF"\'=1+1","\'+CMD","\'-CMD","\'@SUM(A1)","\'\t=1","\'  =1","-2",""');
});
