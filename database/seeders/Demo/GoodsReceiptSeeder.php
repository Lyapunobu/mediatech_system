<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Support\SeederSupport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GoodsReceiptSeeder extends Seeder
{
    use SeederSupport;

    // Nomor serial contoh yang sudah dipakai data demo sebelumnya; unit lainnya mendapat serial otomatis.
    private const NAMED_SERIALS = [
        'PRD-001' => ['ACER-VX-2026-0001', 'ACER-VX-2026-0002'],
        'PRD-002' => ['LEN-E14-2026-0001'],
        'PRD-004' => ['DELL-T150-2026-0001'],
    ];

    public function run(): void
    {
        if (!$this->isDemoEnvironment()) return;

        $supplierId = $this->supplierId('SUP-0003');
        $warehouseOperator = $this->userId('jakoy@meditech.co.id');
        $receipts = [
            ['GR-2026-099', 'WH-001', '2026-10-01', [
                'PRD-001' => 42, 'PRD-002' => 9, 'PRD-004' => 2, 'PRD-005' => 6, 'PRD-008' => 5,
                'PRD-010' => 9, 'PRD-011' => 24, 'PRD-012' => 11,
            ]],
            ['GR-2026-098', 'WH-002', '2026-10-02', [
                'PRD-003' => 18, 'PRD-006' => 25, 'PRD-009' => 12,
            ]],
        ];

        DB::transaction(function () use ($receipts, $supplierId, $warehouseOperator) {
            foreach ($receipts as [$receiptNumber, $warehouseCode, $date, $items]) {
                $warehouseId = $this->warehouseId($warehouseCode);
                DB::table('goods_receipts')->updateOrInsert(
                    ['receipt_number' => $receiptNumber],
                    [
                        'supplier_id' => $supplierId,
                        'warehouse_id' => $warehouseId,
                        'received_by' => $warehouseOperator,
                        'receipt_date' => $date,
                        'status' => 'Posted',
                        'posted_at' => $date . ' 10:00:00',
                        'notes' => 'Stok awal demo untuk pengujian inventory.',
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'deletion_reason' => null,
                    ]
                );
                $receiptId = (int) DB::table('goods_receipts')->where('receipt_number', $receiptNumber)->value('receipt_id');

                foreach ($items as $sku => $quantity) {
                    $product = DB::table('products')->where('sku', $sku)->first();
                    DB::table('goods_receipt_items')->updateOrInsert(
                        ['receipt_id' => $receiptId, 'product_id' => $product->product_id],
                        [
                            'quantity_received' => $quantity,
                            'unit_cost' => $product->purchase_price,
                            'unit' => $product->unit,
                        ]
                    );
                    $receiptItemId = (int) DB::table('goods_receipt_items')
                        ->where('receipt_id', $receiptId)->where('product_id', $product->product_id)->value('receipt_item_id');

                    $movementValues = [
                        'product_id' => $product->product_id,
                        'warehouse_id' => $warehouseId,
                        'receipt_item_id' => $receiptItemId,
                        'goods_issue_item_id' => null,
                        'reversal_of_movement_id' => null,
                        'recorded_by' => $warehouseOperator,
                        'movement_date' => $date . ' 10:00:00',
                        'movement_type' => 'GOODS_IN',
                        'reference_number' => $receiptNumber,
                        'adjustment_reason' => null,
                    ];

                    if ($product->track_serial) {
                        $this->seedSerializedReceipt($product, $warehouseId, $receiptItemId, $receiptNumber, $quantity, $movementValues);
                        continue;
                    }

                    $movement = DB::table('stock_movements')
                        ->where('receipt_item_id', $receiptItemId)
                        ->where('movement_type', 'GOODS_IN')
                        ->first();
                    $movementValues += ['serial_id' => null, 'quantity' => $quantity];
                    if ($movement) {
                        DB::table('stock_movements')->where('movement_id', $movement->movement_id)->update($movementValues);
                    } else {
                        DB::table('stock_movements')->insert($movementValues);
                    }
                }
            }
        });
    }

    /**
     * Same shape as ReceiveGoodsService: one serial row and one 1-unit GOODS_IN movement per unit,
     * so every seeded unit of a serial-tracked product can be issued through Goods Out.
     */
    private function seedSerializedReceipt(object $product, int $warehouseId, int $receiptItemId, string $receiptNumber, int $quantity, array $movementValues): void
    {
        // Older demo data stored serial-tracked stock as one bulk movement without serials.
        DB::table('stock_movements')
            ->where('receipt_item_id', $receiptItemId)
            ->where('movement_type', 'GOODS_IN')
            ->whereNull('serial_id')
            ->delete();

        $named = self::NAMED_SERIALS[$product->sku] ?? [];
        for ($index = 0; $index < $quantity; $index++) {
            $number = $named[$index] ?? sprintf('SN-%s-%s-%03d', $receiptNumber, $product->sku, $index + 1);
            $serialId = DB::table('product_serials')->where('serial_number', $number)->value('serial_id');
            // Existing serials keep their status so a re-run never returns already-issued units to stock.
            $serialId ??= DB::table('product_serials')->insertGetId([
                'product_id' => $product->product_id,
                'warehouse_id' => $warehouseId,
                'serial_number' => $number,
                'status' => 'In Stock',
            ], 'serial_id');

            $exists = DB::table('stock_movements')
                ->where('receipt_item_id', $receiptItemId)
                ->where('movement_type', 'GOODS_IN')
                ->where('serial_id', $serialId)
                ->exists();
            if (! $exists) {
                DB::table('stock_movements')->insert($movementValues + ['serial_id' => $serialId, 'quantity' => 1]);
            }
        }
    }
}
