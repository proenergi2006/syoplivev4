<?php

namespace App\Services\NonTrade\GoodsReturn;

use App\Models\GoodsReceive;
use App\Models\GoodsReturn;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\NonTrade\GoodsReceive\GoodsReceivePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReturnCancellationService
{
    public function __construct(
        private readonly GoodsReceivePostingService $goodsReceivePostingService,
    ) {}

    /**
     * Membatalkan Goods Return yang sudah POSTED.
     */
    public function cancel(
        GoodsReturn $goodsReturn,
        User $user,
        string $notes,
    ): void {
        DB::transaction(function () use (
            $goodsReturn,
            $user,
            $notes,
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Goods Return
            |--------------------------------------------------------------------------
            */
            $goodsReturn = GoodsReturn::query()
                ->with([
                    'items',
                ])
                ->lockForUpdate()
                ->findOrFail($goodsReturn->id);

            if (
                strtoupper(
                    trim((string) $goodsReturn->status),
                ) !== GoodsReturn::STATUS_POSTED
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Goods Return hanya dapat dibatalkan jika status masih POSTED.',
                    ],
                ]);
            }

            if ($goodsReturn->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [
                        'Goods Return tidak memiliki detail item.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Cek GR replacement
            |--------------------------------------------------------------------------
            | Goods Return tidak boleh dibatalkan jika sudah digunakan sebagai
            | sumber GR replacement yang masih DRAFT atau sudah POSTED.
            |--------------------------------------------------------------------------
            */
            $replacementGoodsReceive = GoodsReceive::query()
                ->where(
                    'source_goods_return_id',
                    $goodsReturn->id,
                )
                ->whereIn('status', [
                    'DRAFT',
                    'POSTED',
                ])
                ->lockForUpdate()
                ->first();

            if ($replacementGoodsReceive) {
                throw ValidationException::withMessages([
                    'goods_return' => [
                        'Goods Return tidak dapat dibatalkan karena sudah memiliki Goods Receipt replacement '
                            . ($replacementGoodsReceive->nomor_gr ?? '')
                            . '.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Kembalikan qty received PO
            |--------------------------------------------------------------------------
            | Posting retur sebelumnya:
            | qty_received PO dikurangi qty_return.
            |
            | Saat cancel:
            | qty_received PO ditambah kembali qty_return.
            |--------------------------------------------------------------------------
            */
            foreach ($goodsReturn->items as $returnItem) {
                $purchaseOrderItem = PurchaseOrderItem::query()
                    ->whereKey(
                        $returnItem->purchase_order_item_id,
                    )
                    ->where(
                        'purchase_order_id',
                        $goodsReturn->purchase_order_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyPo = (float) (
                    $purchaseOrderItem->qty
                    ?? 0
                );

                $qtyReceivedBefore = (float) (
                    $purchaseOrderItem->qty_received
                    ?? 0
                );

                $qtyReturn = (float) (
                    $returnItem->qty_return
                    ?? 0
                );

                if ($qtyReturn <= 0) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Qty retur item '
                                . ($returnItem->nama_item ?? '-')
                                . ' tidak valid.',
                        ],
                    ]);
                }

                $qtyReceivedAfter = (
                    $qtyReceivedBefore
                    + $qtyReturn
                );

                /*
                |--------------------------------------------------------------------------
                | Tidak boleh melebihi qty PO
                |--------------------------------------------------------------------------
                */
                if (
                    $qtyReceivedAfter
                    > ($qtyPo + 0.0001)
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Pembatalan retur item '
                                . ($returnItem->nama_item ?? '-')
                                . ' menyebabkan qty received melebihi qty Purchase Order.',
                        ],
                    ]);
                }

                $qtyOutstandingAfter = max(
                    $qtyPo - $qtyReceivedAfter,
                    0,
                );

                $purchaseOrderItem->qty_received
                    = $qtyReceivedAfter;

                $purchaseOrderItem->qty_outstanding_receive
                    = $qtyOutstandingAfter;

                $purchaseOrderItem->save();
            }

            /*
            |--------------------------------------------------------------------------
            | Tandai Goods Return sebagai CANCELLED
            |--------------------------------------------------------------------------
            */
            $goodsReturn->status
                = GoodsReturn::STATUS_CANCELLED;

            $goodsReturn->cancelled_by
                = $user->id;

            $goodsReturn->cancelled_at
                = now();

            $goodsReturn->cancel_notes
                = $notes;

            $goodsReturn->save();

            /*
            |--------------------------------------------------------------------------
            | Sinkronisasi ulang status penerimaan PO
            |--------------------------------------------------------------------------
            */
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey(
                    $goodsReturn->purchase_order_id,
                )
                ->lockForUpdate()
                ->firstOrFail();

            $this->goodsReceivePostingService
                ->syncPurchaseOrderReceiveStatus(
                    $purchaseOrder,
                );
        });
    }
}
