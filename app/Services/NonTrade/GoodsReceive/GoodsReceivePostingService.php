<?php

namespace App\Services\NonTrade\GoodsReceive;

use App\Models\GoodsReceive;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class GoodsReceivePostingService
{
    public function __construct(
        protected GoodsReceiveRollbackService $rollbackService,
    ) {}

    public function post(GoodsReceive $gr, User $user): void
    {
        DB::transaction(function () use ($gr, $user) {
            $gr->loadMissing(['items', 'purchaseOrder.items']);

            if ($gr->status !== 'DRAFT') {
                throw new Exception('Goods Receive hanya dapat diposting jika status masih DRAFT.');
            }

            if (str_starts_with((string) $gr->nomor_gr, 'DRAFT/')) {
                $gr->nomor_gr = generateGRNumber($gr);
            }

            foreach ($gr->items as $grItem) {
                $poItem = PurchaseOrderItem::where('id', $grItem->purchase_order_item_id)
                    ->lockForUpdate()
                    ->first();

                if (!$poItem) {
                    throw new Exception('Item PO tidak ditemukan.');
                }

                $qtyReceive = (float) $grItem->qty_receive;
                $qtyPo = (float) $poItem->qty;
                $qtyReceivedBefore = (float) ($poItem->qty_received ?? 0);
                $qtyOutstandingBefore = $qtyPo - $qtyReceivedBefore;

                if ($qtyReceive <= 0) {
                    throw new Exception('Qty receive harus lebih dari 0.');
                }

                if ($qtyReceive > $qtyOutstandingBefore) {
                    throw new Exception('Qty receive tidak boleh melebihi outstanding PO.');
                }

                $qtyReceivedAfter = $qtyReceivedBefore + $qtyReceive;
                $qtyOutstandingAfter = max($qtyPo - $qtyReceivedAfter, 0);

                $poItem->qty_received = $qtyReceivedAfter;
                $poItem->qty_outstanding_receive = $qtyOutstandingAfter;
                $poItem->save();

                $grItem->qty_received_before = $qtyReceivedBefore;
                $grItem->qty_received_after = $qtyReceivedAfter;
                $grItem->qty_outstanding = $qtyOutstandingAfter;
                $grItem->save();
            }

            $gr->status = 'POSTED';
            $gr->posted_by = $user->id;
            $gr->posted_at = now();
            $gr->save();

            $po = PurchaseOrder::query()
                ->where('id', $gr->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncPurchaseOrderReceiveStatus($po);
        });
    }

    public function cancel(GoodsReceive $gr, User $user, ?string $notes = null): void
    {
        DB::transaction(function () use ($gr, $user, $notes) {
            $gr->loadMissing(['items', 'purchaseOrder.items']);

            if ($gr->status !== 'POSTED') {
                throw new Exception('Goods Receive hanya dapat dibatalkan jika status sudah POSTED.');
            }

            $this->rollbackService->rollback($gr);

            $gr->status = 'CANCELLED';
            $gr->cancelled_by = $user->id;
            $gr->cancelled_at = now();
            $gr->cancel_notes = $notes;
            $gr->save();

            $this->syncPurchaseOrderReceiveStatus($gr->purchaseOrder);
        });
    }

    private function syncPurchaseOrderReceiveStatus(PurchaseOrder $po): void
    {
        $po->load('items');

        $items = $po->items;

        if ($items->isEmpty()) {
            $po->status_receive = 'OPEN';
            $po->save();

            return;
        }

        $totalQtyPo = $items->sum(function ($item) {
            return (float) ($item->qty ?? 0);
        });

        $totalQtyReceived = $items->sum(function ($item) {
            return (float) ($item->qty_received ?? 0);
        });

        if ($totalQtyReceived <= 0) {
            $statusReceive = 'OPEN';
        } elseif ($totalQtyReceived < $totalQtyPo) {
            $statusReceive = 'PARTIAL';
        } else {
            $statusReceive = 'COMPLETED';
        }

        $po->status_receive = $statusReceive;
        $po->save();
    }
}
