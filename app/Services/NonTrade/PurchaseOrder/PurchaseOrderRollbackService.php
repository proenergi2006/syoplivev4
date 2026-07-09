<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;

class PurchaseOrderRollbackService
{
    public function rollbackPurchaseRequestItems(PurchaseOrder $po): void
    {
        $po->loadMissing(['items']);

        $affectedPurchaseRequestIds = [];

        /*
        |--------------------------------------------------------------------------
        | 1. Rollback qty_po dan qty_outstanding di PR item
        |--------------------------------------------------------------------------
        */
        foreach ($po->items as $item) {
            if (!$item->purchase_request_item_id) {
                continue;
            }

            $prItem = PurchaseRequestItem::where('id', $item->purchase_request_item_id)
                ->lockForUpdate()
                ->first();

            if (!$prItem) {
                continue;
            }

            $qtyPoRollback = (float) ($item->qty ?? 0);
            $qtyRequest = (float) ($prItem->qty ?? 0);
            $currentQtyPo = (float) ($prItem->qty_po ?? 0);

            /*
            |--------------------------------------------------------------------------
            | Qty PO dikurangi qty dari PO yang dihapus/reject.
            |--------------------------------------------------------------------------
            */
            $newQtyPo = max($currentQtyPo - $qtyPoRollback, 0);

            /*
            |--------------------------------------------------------------------------
            | Outstanding dihitung ulang dari qty request - qty_po terbaru.
            |--------------------------------------------------------------------------
            */
            $newQtyOutstanding = max($qtyRequest - $newQtyPo, 0);

            $prItem->update([
                'qty_po' => $newQtyPo,
                'qty_outstanding' => $newQtyOutstanding,
            ]);

            if ($prItem->purchase_request_id) {
                $affectedPurchaseRequestIds[] = (int) $prItem->purchase_request_id;
            }
        }

        $affectedPurchaseRequestIds = array_values(array_unique($affectedPurchaseRequestIds));

        /*
        |--------------------------------------------------------------------------
        | 2. Refresh status_po PR
        |--------------------------------------------------------------------------
        */
        foreach ($affectedPurchaseRequestIds as $purchaseRequestId) {
            $this->refreshPurchaseRequestPOStatus($purchaseRequestId);
        }
    }

    private function refreshPurchaseRequestPOStatus(int $purchaseRequestId): void
    {
        $pr = PurchaseRequest::where('id', $purchaseRequestId)
            ->lockForUpdate()
            ->first();

        if (!$pr) {
            return;
        }

        $summary = PurchaseRequestItem::query()
            ->where('purchase_request_id', $purchaseRequestId)
            ->whereNull('deleted_at')
            ->selectRaw('
            COALESCE(SUM(qty), 0) as total_qty_request,
            COALESCE(SUM(qty_po), 0) as total_qty_po,
            COALESCE(SUM(qty_outstanding), 0) as total_qty_outstanding
        ')
            ->first();

        $totalQtyRequest = (float) ($summary->total_qty_request ?? 0);
        $totalQtyPo = (float) ($summary->total_qty_po ?? 0);
        $totalQtyOutstanding = (float) ($summary->total_qty_outstanding ?? 0);

        if ($totalQtyPo <= 0) {
            $statusPo = 'OPEN';
        } elseif ($totalQtyOutstanding > 0 && $totalQtyPo < $totalQtyRequest) {
            $statusPo = 'PARTIAL';
        } else {
            /*
        |--------------------------------------------------------------------------
        | PO dibuat penuh bukan berarti PR selesai/closed.
        |--------------------------------------------------------------------------
        */
            $statusPo = 'OPEN';
        }

        $pr->update([
            'status_po' => $statusPo,
        ]);
    }
}
