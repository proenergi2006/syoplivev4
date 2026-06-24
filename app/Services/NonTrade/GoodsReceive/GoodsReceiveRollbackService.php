<?php

namespace App\Services\NonTrade\GoodsReceive;

use App\Models\GoodsReceive;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

class GoodsReceiveRollbackService
{
    public function rollback(GoodsReceive $gr): void
    {
        $gr->loadMissing([
            'items',
            'purchaseOrder.items',
        ]);

        foreach ($gr->items as $grItem) {

            $poItem = PurchaseOrderItem::where('id', $grItem->purchase_order_item_id)
                ->lockForUpdate()
                ->first();

            if (!$poItem) {
                continue;
            }

            $qtyReceive = (float) ($grItem->qty_receive ?? 0);

            $qtyReceivedNow = (float) ($poItem->qty_received ?? 0);

            $qtyPo = (float) ($poItem->qty ?? 0);

            $qtyReceivedAfterRollback = max(
                $qtyReceivedNow - $qtyReceive,
                0
            );

            $qtyOutstandingAfterRollback = max(
                $qtyPo - $qtyReceivedAfterRollback,
                0
            );

            $poItem->qty_received = $qtyReceivedAfterRollback;
            $poItem->qty_outstanding_receive = $qtyOutstandingAfterRollback;
            $poItem->save();
        }

        $this->syncPurchaseOrderReceiveStatus(
            $gr->purchaseOrder
        );
    }

    private function syncPurchaseOrderReceiveStatus(
        PurchaseOrder $po
    ): void {
        $po->loadMissing('items');

        $totalQtyPo = $po->items->sum(
            fn($item) => (float) ($item->qty ?? 0)
        );

        $totalQtyReceived = $po->items->sum(
            fn($item) => (float) ($item->qty_received ?? 0)
        );

        if ($totalQtyReceived <= 0) {

            $po->status_receive = 'OPEN';
        } elseif ($totalQtyReceived < $totalQtyPo) {

            $po->status_receive = 'PARTIAL RECEIVED';
        } else {

            $po->status_receive = 'FULL RECEIVED';
        }

        $po->save();
    }
}
