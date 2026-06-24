<?php

namespace App\Services\NonTrade\GoodsReceive;

use App\Models\GoodsReceive;
use App\Models\GoodsReturn;
use App\Models\GoodsReturnItem;
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

    public function post(
        GoodsReceive $goodsReceive,
        User $user,
    ): void {
        DB::transaction(function () use (
            $goodsReceive,
            $user,
        ) {
        /*
        |--------------------------------------------------------------------------
        | Lock Goods Receive
        |--------------------------------------------------------------------------
        */
            /** @var GoodsReceive $gr */
            $gr = GoodsReceive::query()
                ->whereKey($goodsReceive->id)
                ->lockForUpdate()
                ->firstOrFail();

            $gr->load([
                'items',
            ]);

            if (
                strtoupper(trim((string) $gr->status))
                !== 'DRAFT'
            ) {
                throw new Exception(
                    'Goods Receipt hanya dapat diposting jika status masih DRAFT.',
                );
            }

            if ($gr->items->isEmpty()) {
                throw new Exception(
                    'Goods Receipt tidak memiliki item.',
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Hindari item PO yang sama muncul dua kali dalam satu GR
        |--------------------------------------------------------------------------
        */
            $duplicatePurchaseOrderItemIds = $gr
                ->items
                ->pluck('purchase_order_item_id')
                ->duplicates();

            if ($duplicatePurchaseOrderItemIds->isNotEmpty()) {
                throw new Exception(
                    'Terdapat item Purchase Order yang duplikat pada Goods Receipt.',
                );
            }

            $isReplacement = (
                $gr->source_goods_return_id !== null
            );

            $sourceGoodsReturn = null;

            /*
        |--------------------------------------------------------------------------
        | Lock Goods Return sumber untuk GR replacement
        |--------------------------------------------------------------------------
        */
            if ($isReplacement) {
                /** @var GoodsReturn $sourceGoodsReturn */
                $sourceGoodsReturn = GoodsReturn::query()
                    ->whereKey(
                        $gr->source_goods_return_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    strtoupper(
                        trim(
                            (string) $sourceGoodsReturn->status,
                        ),
                    ) !== GoodsReturn::STATUS_POSTED
                ) {
                    throw new Exception(
                        'Goods Return sumber harus berstatus POSTED.',
                    );
                }

                if (
                    (int) $sourceGoodsReturn->purchase_order_id
                    !== (int) $gr->purchase_order_id
                ) {
                    throw new Exception(
                        'Purchase Order Goods Receipt tidak sesuai dengan Goods Return sumber.',
                    );
                }
            }

        /*
        |--------------------------------------------------------------------------
        | Lock Purchase Order
        |--------------------------------------------------------------------------
        */
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->whereKey(
                    $gr->purchase_order_id,
                )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                strtoupper(trim((string) $po->status))
                !== 'APPROVED'
            ) {
                throw new Exception(
                    'Purchase Order harus berstatus APPROVED.',
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi dan posting item
        |--------------------------------------------------------------------------
        */
            foreach ($gr->items as $grItem) {
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = PurchaseOrderItem::query()
                    ->where(
                        'purchase_order_id',
                        $po->id,
                    )
                    ->whereKey(
                        $grItem->purchase_order_item_id,
                    )
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (!$poItem) {
                    throw new Exception(
                        'Item Purchase Order tidak ditemukan.',
                    );
                }

                $qtyReceive = (float) (
                    $grItem->qty_receive
                    ?? 0
                );

                if ($qtyReceive <= 0) {
                    throw new Exception(
                        'Qty receive item '
                            . ($poItem->nama_item ?? '-')
                            . ' harus lebih besar dari 0.',
                    );
                }

                $qtyPo = (float) (
                    $poItem->qty
                    ?? 0
                );

                /*
            |--------------------------------------------------------------------------
            | Validasi GR replacement
            |--------------------------------------------------------------------------
            */
                if ($isReplacement) {
                    /*
                |--------------------------------------------------------------------------
                | Total qty yang diretur dari PO item tersebut
                |--------------------------------------------------------------------------
                */
                    $qtyReturn = (float) GoodsReturnItem::query()
                        ->where(
                            'goods_return_id',
                            $sourceGoodsReturn->id,
                        )
                        ->where(
                            'purchase_order_item_id',
                            $poItem->id,
                        )
                        ->sum('qty_return');

                    if ($qtyReturn <= 0) {
                        throw new Exception(
                            'Item '
                                . ($poItem->nama_item ?? '-')
                                . ' tidak termasuk dalam Goods Return sumber.',
                        );
                    }

                    /*
                |--------------------------------------------------------------------------
                | Replacement lain yang sudah menggunakan qty retur
                |--------------------------------------------------------------------------
                | GR saat ini dikecualikan karena qty-nya diperiksa terpisah.
                | GR DRAFT lain tetap dihitung sebagai reservation.
                |--------------------------------------------------------------------------
                */
                    $qtyReplacementOther = (float) DB::table(
                        'goods_receive_items as gri',
                    )
                        ->join(
                            'goods_receives as replacement_gr',
                            'replacement_gr.id',
                            '=',
                            'gri.goods_receive_id',
                        )
                        ->where(
                            'replacement_gr.source_goods_return_id',
                            $sourceGoodsReturn->id,
                        )
                        ->where(
                            'gri.purchase_order_item_id',
                            $poItem->id,
                        )
                        ->where(
                            'replacement_gr.id',
                            '<>',
                            $gr->id,
                        )
                        ->whereRaw(
                            'UPPER(TRIM(replacement_gr.status)) IN (?, ?)',
                            [
                                'DRAFT',
                                'POSTED',
                            ],
                        )
                        ->whereNull(
                            'replacement_gr.deleted_at',
                        )
                        ->sum(
                            'gri.qty_receive',
                        );

                    $qtyReplacementOutstanding = max(
                        $qtyReturn
                            - $qtyReplacementOther,
                        0,
                    );

                    if (
                        $qtyReceive
                        > (
                            $qtyReplacementOutstanding
                            + 0.0001
                        )
                    ) {
                        throw new Exception(
                            'Qty replacement item '
                                . ($poItem->nama_item ?? '-')
                                . ' melebihi outstanding replacement. Maksimal '
                                . $qtyReplacementOutstanding
                                . '.',
                        );
                    }
                } else {
                    /*
                |--------------------------------------------------------------------------
                | Validasi GR normal
                |--------------------------------------------------------------------------
                | Hanya GR normal yang dihitung. GR replacement tidak termasuk
                | dalam outstanding penerimaan pembelian normal.
                |--------------------------------------------------------------------------
                */
                    $qtyNormalOther = (float) DB::table(
                        'goods_receive_items as gri',
                    )
                        ->join(
                            'goods_receives as normal_gr',
                            'normal_gr.id',
                            '=',
                            'gri.goods_receive_id',
                        )
                        ->where(
                            'gri.purchase_order_item_id',
                            $poItem->id,
                        )
                        ->whereNull(
                            'normal_gr.source_goods_return_id',
                        )
                        ->where(
                            'normal_gr.id',
                            '<>',
                            $gr->id,
                        )
                        ->whereRaw(
                            'UPPER(TRIM(normal_gr.status)) IN (?, ?)',
                            [
                                'DRAFT',
                                'POSTED',
                            ],
                        )
                        ->whereNull(
                            'normal_gr.deleted_at',
                        )
                        ->sum(
                            'gri.qty_receive',
                        );

                    $qtyNormalOutstanding = max(
                        $qtyPo
                            - $qtyNormalOther,
                        0,
                    );

                    if (
                        $qtyReceive
                        > (
                            $qtyNormalOutstanding
                            + 0.0001
                        )
                    ) {
                        throw new Exception(
                            'Qty receive item '
                                . ($poItem->nama_item ?? '-')
                                . ' melebihi outstanding penerimaan normal. Maksimal '
                                . $qtyNormalOutstanding
                                . '.',
                        );
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Validasi outstanding netto PO
            |--------------------------------------------------------------------------
            | Berlaku untuk GR normal maupun replacement.
            |--------------------------------------------------------------------------
            */
                $qtyReceivedBefore = (float) (
                    $poItem->qty_received
                    ?? 0
                );

                $qtyOutstandingBefore = max(
                    $qtyPo
                        - $qtyReceivedBefore,
                    0,
                );

                if (
                    $qtyReceive
                    > (
                        $qtyOutstandingBefore
                        + 0.0001
                    )
                ) {
                    throw new Exception(
                        'Qty receive item '
                            . ($poItem->nama_item ?? '-')
                            . ' melebihi outstanding Purchase Order. Maksimal '
                            . $qtyOutstandingBefore
                            . '.',
                    );
                }

                /*
            |--------------------------------------------------------------------------
            | Update qty received PO
            |--------------------------------------------------------------------------
            */
                $qtyReceivedAfter = (
                    $qtyReceivedBefore
                    + $qtyReceive
                );

                $qtyOutstandingAfter = max(
                    $qtyPo
                        - $qtyReceivedAfter,
                    0,
                );

                $poItem->qty_received
                    = $qtyReceivedAfter;

                $poItem->qty_outstanding_receive
                    = $qtyOutstandingAfter;

                $poItem->save();

                /*
            |--------------------------------------------------------------------------
            | Simpan snapshot pada item GR
            |--------------------------------------------------------------------------
            */
                $grItem->qty_received_before
                    = $qtyReceivedBefore;

                $grItem->qty_received_after
                    = $qtyReceivedAfter;

                $grItem->qty_outstanding
                    = $qtyOutstandingAfter;

                $grItem->save();
            }

            /*
        |--------------------------------------------------------------------------
        | Generate nomor final
        |--------------------------------------------------------------------------
        */
            if (
                str_starts_with(
                    (string) $gr->nomor_gr,
                    'DRAFT/',
                )
            ) {
                $gr->nomor_gr = generateGRNumber(
                    $gr,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Posting Goods Receipt
        |--------------------------------------------------------------------------
        */
            $gr->status = 'POSTED';
            $gr->posted_by = $user->id;
            $gr->posted_at = now();
            $gr->save();

            /*
        |--------------------------------------------------------------------------
        | Sinkronisasi status penerimaan PO
        |--------------------------------------------------------------------------
        */
            $this->syncPurchaseOrderReceiveStatus(
                $po,
            );
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

    public function syncPurchaseOrderReceiveStatus(PurchaseOrder $po): void
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
