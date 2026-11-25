<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Support\SeederSupport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GoodsOutSeeder extends Seeder
{
    use SeederSupport;

    public function run(): void
    {
        if (!$this->isDemoEnvironment()) return;

        DB::transaction(fn () => $this->seedIssues());
    }

    private function seedIssues(): void
    {
        $warehouseUserId = $this->userId('jakoy@meditech.co.id');
        $issues = [
            ['GI-2026-058', 'SO-2026-031', '2026-10-17', 'Pengiriman sebagian 10 unit Kamera CCTV Hikvision 2MP.', ['PRD-011' => 10]],
            ['GI-2026-057', 'SO-2026-030', '2026-10-12', 'Pengiriman 5 unit Projector NEC NP-M350X.', ['PRD-010' => 5]],
        ];

        foreach ($issues as [$number, $soNumber, $date, $notes, $items]) {
            $salesOrderId = $this->salesOrderId($soNumber);
            DB::table('goods_issues')->updateOrInsert(
                ['goods_issue_number' => $number],
                [
                    'sales_order_id' => $salesOrderId,
                    'created_by' => $warehouseUserId,
                    'created_at' => $date . ' 15:00:00',
                    'posted_at' => $date . ' 16:00:00',
                    'status' => 'Posted',
                    'notes' => $notes,
                    'reversed_by' => null,
                    'reversed_at' => null,
                    'reversal_reason' => null,
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'deletion_reason' => null,
                ]
            );
            $goodsIssueId = (int) DB::table('goods_issues')->where('goods_issue_number', $number)->value('goods_issue_id');

            foreach ($items as $sku => $quantity) {
                $productId = $this->productId($sku);
                $soItem = DB::table('sales_order_items')
                    ->where('sales_order_id', $salesOrderId)
                    ->where('product_id', $productId)
                    ->first();
                if (!$soItem) {
                    continue;
                }

                $warehouseId = $this->warehouseId('WH-001');
                DB::table('goods_issue_items')->updateOrInsert(
                    ['goods_issue_id' => $goodsIssueId, 'so_item_id' => $soItem->so_item_id, 'warehouse_id' => $warehouseId],
                    ['quantity_issued' => $quantity, 'unit' => $soItem->unit_snapshot, 'notes' => $notes]
                );
                $issueItemId = (int) DB::table('goods_issue_items')
                    ->where('goods_issue_id', $goodsIssueId)
                    ->where('so_item_id', $soItem->so_item_id)
                    ->where('warehouse_id', $warehouseId)
                    ->value('goods_issue_item_id');

                $movementValues = [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'receipt_item_id' => null,
                    'goods_issue_item_id' => $issueItemId,
                    'reversal_of_movement_id' => null,
                    'recorded_by' => $warehouseUserId,
                    'movement_date' => $date . ' 16:00:00',
                    'movement_type' => 'GOODS_OUT',
                    'reference_number' => $number,
                    'adjustment_reason' => null,
                ];
                if (DB::table('products')->where('product_id', $productId)->value('track_serial')) {
                    $this->issueSerials($productId, $warehouseId, $issueItemId, $quantity, $movementValues);
                } else {
                    $movement = DB::table('stock_movements')->where('goods_issue_item_id', $issueItemId)->where('movement_type', 'GOODS_OUT')->first();
                    $movementValues += ['serial_id' => null, 'quantity' => $quantity];
                    if ($movement) {
                        DB::table('stock_movements')->where('movement_id', $movement->movement_id)->update($movementValues);
                    } else {
                        DB::table('stock_movements')->insert($movementValues);
                    }
                }

                DB::table('sales_order_items')->where('so_item_id', $soItem->so_item_id)
                    ->update(['quantity_delivered' => min((float) $soItem->quantity_ordered, (float) $quantity)]);
            }
        }

        // Baris jasa SO-030 juga ditandai selesai tanpa stock movement karena bukan barang gudang.
        $so30 = $this->salesOrderId('SO-2026-030');
        $serviceId = $this->productId('PRD-017');
        DB::table('sales_order_items')->where('sales_order_id', $so30)->where('product_id', $serviceId)
            ->update(['quantity_delivered' => DB::raw('quantity_ordered')]);
    }

    /**
     * Same shape as PostGoodsIssueService: each issued unit consumes one in-stock serial with a 1-unit GOODS_OUT movement.
     */
    private function issueSerials(int $productId, int $warehouseId, int $issueItemId, int $quantity, array $movementValues): void
    {
        // Older demo data issued serial-tracked stock as one bulk movement without serials.
        DB::table('stock_movements')
            ->where('goods_issue_item_id', $issueItemId)
            ->where('movement_type', 'GOODS_OUT')
            ->whereNull('serial_id')
            ->delete();

        $issued = DB::table('stock_movements')
            ->where('goods_issue_item_id', $issueItemId)
            ->where('movement_type', 'GOODS_OUT')
            ->whereNotNull('serial_id')
            ->count();
        $serialIds = DB::table('product_serials')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'In Stock')
            ->orderBy('serial_id')
            ->limit(max(0, $quantity - $issued))
            ->pluck('serial_id');
        if ($issued + $serialIds->count() < $quantity) {
            throw new RuntimeException("Serial demo tidak cukup untuk produk {$productId}. Jalankan GoodsReceiptSeeder lebih dahulu.");
        }

        foreach ($serialIds as $serialId) {
            DB::table('stock_movements')->insert($movementValues + ['serial_id' => $serialId, 'quantity' => 1]);
            DB::table('product_serials')->where('serial_id', $serialId)->update(['status' => 'Issued', 'warehouse_id' => null]);
        }
    }
}