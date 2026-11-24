{{-- Shared Blade components remain the markup source for dynamically populated rows. --}}
<template id="inventory-empty-row">
    <tr data-empty><td><x-ui.empty-state><span data-field="message"></span></x-ui.empty-state></td></tr>
</template>
<template id="inventory-available-badge"><x-ui.badge>Available</x-ui.badge></template>
<template id="inventory-low-badge"><x-ui.badge variant="error">Low stock</x-ui.badge></template>
<template id="inventory-stock-row">
    <tr>
        <td data-field="sku" class="font-bold text-primary-500"></td><td data-field="name"></td>
        <td data-field="category" class="text-neutral-600"></td><td data-field="warehouse" class="text-neutral-600"></td>
        <td data-field="qty" class="font-extrabold"></td><td data-field="min" class="text-neutral-600"></td><td data-field="status"></td>
    </tr>
</template>
<template id="inventory-movement-row">
    <tr><td data-field="time"></td><td data-field="reference"></td><td data-field="action"></td><td data-field="detail"></td></tr>
</template>
<template id="inventory-confirm-row">
    <tr><td data-field="sku"></td><td data-field="name"></td><td data-field="quantity"></td><td data-field="warehouse"></td></tr>
</template>
@if(auth()->user()->can('inventory.update') && auth()->user()->can('inventory.post'))
    <template id="inventory-issue-row">
        <tr data-issue-row>
            <td><x-ui.input type="checkbox" data-issue-include/></td>
            <td data-field="sku"></td>
            <td>
                <span data-field="name"></span><span data-field="remaining" class="mt-1 block text-[11px] text-neutral-500"></span>
                <fieldset data-serial-control class="mt-3 min-w-0 whitespace-normal" hidden>
                    <legend class="text-[11px] font-bold text-neutral-600">Nomor serial yang dikirim</legend>
                    <p data-serial-hint class="mt-[2px] text-[11px] text-neutral-500"></p>
                    <div data-serial-list role="group" class="mt-[6px] max-h-[150px] overflow-y-auto rounded-lg border border-neutral-200 py-1"></div>
                    <p data-serial-note class="mt-[6px] text-[11px] text-neutral-500" hidden></p>
                </fieldset>
            </td>
            <td data-available></td>
            <td><x-ui.input type="number" min="1" step="1" required/><span data-qty-max class="mt-1 block text-[11px] text-neutral-500"></span></td>
            <td><x-ui.select data-warehouse/></td>
        </tr>
    </template>
    <template id="inventory-serial-option">
        <label class="flex cursor-pointer items-center gap-2 px-[10px] py-[6px] text-[12px] text-neutral-700 hover:bg-neutral-50 has-[:disabled]:cursor-default has-[:disabled]:text-neutral-500">
            <x-ui.input type="checkbox" data-serial-option/><span data-field="number"></span>
        </label>
    </template>
@endif
