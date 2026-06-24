<?php

namespace App\Services\NonTrade\GoodsReceive;

use App\Models\GoodsReceive;
use App\Models\PurchaseOrder;
use Exception;
use Illuminate\Support\Facades\DB;

class GoodsReceiveService
{
    public function createDraftFromPurchaseOrder(
        PurchaseOrder $po,
        array $payload,
        int $userId
    ): GoodsReceive {
        return DB::transaction(function () use ($po, $payload, $userId) {
            $po->loadMissing(['items', 'vendor']);

            if (strtoupper((string) $po->status) !== 'APPROVED') {
                throw new Exception('Goods Receive hanya dapat dibuat dari Purchase Order yang sudah APPROVED.');
            }

            if (strtoupper((string) ($po->status_receive ?? 'OPEN')) === 'FULL RECEIVED') {
                throw new Exception('Purchase Order sudah full received.');
            }

            $items = collect($payload['items'] ?? [])
                ->filter(fn($item) => (float) ($item['qty_receive'] ?? 0) > 0)
                ->values();

            if ($items->isEmpty()) {
                throw new Exception('Minimal harus ada satu item dengan qty receive lebih dari 0.');
            }

            $gr = GoodsReceive::create([
                'nomor_gr' => $payload['nomor_gr'],
                'purchase_order_id' => $po->id,
                'source_goods_return_id' => $payload['source_goods_return_id'] ?? null,
                'vendor_id' => $po->vendor_id,
                'cabang' => $po->cabang,
                'id_department' => $po->id_department,
                'tanggal_gr' => $payload['tanggal_gr'] ?? now()->toDateString(),
                'nomor_surat_jalan' => $payload['nomor_surat_jalan'] ?? null,
                'status' => 'DRAFT',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $itemPayload) {
                $poItem = $po->items
                    ->where('id', (int) $itemPayload['purchase_order_item_id'])
                    ->first();

                if (!$poItem) {
                    throw new Exception('Item PO tidak valid.');
                }

                $qtyPo = (float) ($poItem->qty ?? 0);
                $qtyReceived = (float) ($poItem->qty_received ?? 0);
                $qtyOutstanding = $qtyPo - $qtyReceived;
                $qtyReceive = (float) ($itemPayload['qty_receive'] ?? 0);

                if ($qtyReceive <= 0) {
                    continue;
                }

                if ($qtyReceive > $qtyOutstanding) {
                    throw new Exception('Qty receive tidak boleh melebihi outstanding PO.');
                }

                $gr->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'purchase_request_item_id' => $poItem->purchase_request_item_id ?? null,

                    'nama_item' => $poItem->nama_item ?? $poItem->item_name ?? null,
                    'unit' => (int) $poItem->satuan,

                    'qty_ordered' => $qtyPo,
                    'qty_received_before' => $qtyReceived,
                    'qty_receive' => $qtyReceive,
                    'qty_received_after' => $qtyReceived + $qtyReceive,
                    'qty_outstanding' => max($qtyOutstanding - $qtyReceive, 0),

                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }

            return $gr->load(['items', 'purchaseOrder', 'vendor']);
        });
    }
}
