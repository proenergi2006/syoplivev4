<?php

namespace App\Services\NonTrade\GoodsReturn;

use App\Models\GoodsReceive;
use App\Models\GoodsReceiveItem;
use App\Models\GoodsReturn;
use App\Models\GoodsReturnItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\NonTrade\GoodsReceive\GoodsReceivePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReturnPostingService
{
    public function __construct(
        private readonly GoodsReceivePostingService $goodsReceivePostingService,
    ) {}

    /**
     * Posting Goods Return.
     *
     * Dampak posting:
     * - retur berubah dari DRAFT menjadi POSTED;
     * - qty_received pada item PO berkurang;
     * - qty_outstanding_receive pada item PO bertambah;
     * - status penerimaan PO disinkronkan ulang;
     * - GR asal tidak diubah karena merupakan histori penerimaan.
     */
    public function post(
        GoodsReturn $goodsReturn,
        User $user,
    ): void {
        DB::transaction(function () use (
            $goodsReturn,
            $user,
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
                ) !== GoodsReturn::STATUS_DRAFT
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Goods Return hanya dapat diposting jika status masih DRAFT.',
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
            | Lock GR sumber
            |--------------------------------------------------------------------------
            */
            $sourceGoodsReceive = GoodsReceive::query()
                ->whereKey(
                    $goodsReturn->goods_receive_id,
                )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                strtoupper(
                    trim((string) $sourceGoodsReceive->status),
                ) !== 'POSTED'
            ) {
                throw ValidationException::withMessages([
                    'goods_receive_id' => [
                        'Goods Receipt sumber harus berstatus POSTED.',
                    ],
                ]);
            }

            if (
                (int) $sourceGoodsReceive->purchase_order_id
                !== (int) $goodsReturn->purchase_order_id
            ) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => [
                        'Purchase Order pada Goods Return tidak sesuai dengan Goods Receipt sumber.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Proses setiap item retur
            |--------------------------------------------------------------------------
            */
            foreach (
                $goodsReturn->items as $returnItem
            ) {
                $this->postItem(
                    goodsReturn: $goodsReturn,
                    returnItem: $returnItem,
                    sourceGoodsReceive: $sourceGoodsReceive,
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Generate nomor retur
            |--------------------------------------------------------------------------
            | Helper nomor akan dibuat mengikuti pola dokumen SYOP.
            |--------------------------------------------------------------------------
            */
            if (
                empty($goodsReturn->nomor_return)
                || str_starts_with(
                    (string) $goodsReturn->nomor_return,
                    'DRAFT/',
                )
            ) {
                $goodsReturn->nomor_return
                    = generateGoodsReturnNumber(
                        $goodsReturn,
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | Tandai Goods Return sebagai POSTED
            |--------------------------------------------------------------------------
            */
            $goodsReturn->status
                = GoodsReturn::STATUS_POSTED;

            $goodsReturn->posted_by
                = $user->id;

            $goodsReturn->posted_at
                = now();

            $goodsReturn->save();

            /*
            |--------------------------------------------------------------------------
            | Sinkronisasi status penerimaan PO
            |--------------------------------------------------------------------------
            | Setelah qty_received dikurangi, PO akan kembali mempunyai
            | qty outstanding dan dapat dibuatkan GR replacement.
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

    /**
     * Posting satu item retur.
     */
    private function postItem(
        GoodsReturn $goodsReturn,
        GoodsReturnItem $returnItem,
        GoodsReceive $sourceGoodsReceive,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Lock item GR sumber
        |--------------------------------------------------------------------------
        */
        $sourceGoodsReceiveItem = GoodsReceiveItem::query()
            ->whereKey(
                $returnItem->goods_receive_item_id,
            )
            ->where(
                'goods_receive_id',
                $sourceGoodsReceive->id,
            )
            ->lockForUpdate()
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Validasi item PO sumber
        |--------------------------------------------------------------------------
        */
        if (
            (int) $sourceGoodsReceiveItem->purchase_order_item_id
            !== (int) $returnItem->purchase_order_item_id
        ) {
            throw ValidationException::withMessages([
                'items' => [
                    'Item Purchase Order tidak sesuai dengan item Goods Receipt sumber.',
                ],
            ]);
        }

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

        /*
        |--------------------------------------------------------------------------
        | Hitung total retur POSTED sebelumnya
        |--------------------------------------------------------------------------
        | Goods Return saat ini masih DRAFT sehingga belum masuk hitungan.
        |--------------------------------------------------------------------------
        */
        $qtyReturnedBefore = (float) GoodsReturnItem::query()
            ->join(
                'goods_returns',
                'goods_returns.id',
                '=',
                'goods_return_items.goods_return_id',
            )
            ->where(
                'goods_return_items.goods_receive_item_id',
                $sourceGoodsReceiveItem->id,
            )
            ->where(
                'goods_returns.status',
                GoodsReturn::STATUS_POSTED,
            )
            ->whereNull(
                'goods_returns.deleted_at',
            )
            ->sum(
                'goods_return_items.qty_return',
            );

        $qtyReceivedFromSourceGr = (float) (
            $sourceGoodsReceiveItem->qty_receive
            ?? 0
        );

        $qtyReturn = (float) (
            $returnItem->qty_return
            ?? 0
        );

        $qtyReturnableBefore = max(
            $qtyReceivedFromSourceGr
                - $qtyReturnedBefore,
            0,
        );

        /*
        |--------------------------------------------------------------------------
        | Validasi qty retur terbaru
        |--------------------------------------------------------------------------
        | Perhitungan dilakukan ulang saat POSTING untuk mencegah dua draft
        | retur menggunakan qty yang sama.
        |--------------------------------------------------------------------------
        */
        if ($qtyReturn <= 0) {
            throw ValidationException::withMessages([
                'items' => [
                    'Qty retur harus lebih besar dari nol.',
                ],
            ]);
        }

        if (
            $qtyReturn
            > ($qtyReturnableBefore + 0.0001)
        ) {
            throw ValidationException::withMessages([
                'items' => [
                    'Qty retur item '
                        . ($returnItem->nama_item ?? '-')
                        . ' melebihi qty yang masih dapat diretur. Maksimal '
                        . $this->formatQty(
                            $qtyReturnableBefore,
                        )
                        . '.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validasi qty received PO
        |--------------------------------------------------------------------------
        */
        $qtyPo = (float) (
            $purchaseOrderItem->qty
            ?? 0
        );

        $qtyReceivedPoBefore = (float) (
            $purchaseOrderItem->qty_received
            ?? 0
        );

        if (
            $qtyReturn
            > ($qtyReceivedPoBefore + 0.0001)
        ) {
            throw ValidationException::withMessages([
                'items' => [
                    'Qty retur item '
                        . ($returnItem->nama_item ?? '-')
                        . ' melebihi total qty received pada Purchase Order.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Update snapshot item retur
        |--------------------------------------------------------------------------
        */
        $qtyReturnedAfter = (
            $qtyReturnedBefore
            + $qtyReturn
        );

        $qtyReturnableAfter = max(
            $qtyReceivedFromSourceGr
                - $qtyReturnedAfter,
            0,
        );

        $returnItem->qty_received
            = $qtyReceivedFromSourceGr;

        $returnItem->qty_returned_before
            = $qtyReturnedBefore;

        $returnItem->qty_returned_after
            = $qtyReturnedAfter;

        $returnItem->qty_returnable_after
            = $qtyReturnableAfter;

        $returnItem->save();

        /*
        |--------------------------------------------------------------------------
        | Kembalikan outstanding PO
        |--------------------------------------------------------------------------
        |
        | Sebelum retur:
        | qty_received = 10
        | qty_outstanding_receive = 0
        |
        | Retur 2:
        | qty_received = 8
        | qty_outstanding_receive = 2
        |--------------------------------------------------------------------------
        */
        $qtyReceivedPoAfter = max(
            $qtyReceivedPoBefore
                - $qtyReturn,
            0,
        );

        $qtyOutstandingPoAfter = max(
            $qtyPo
                - $qtyReceivedPoAfter,
            0,
        );

        $purchaseOrderItem->qty_received
            = $qtyReceivedPoAfter;

        $purchaseOrderItem->qty_outstanding_receive
            = $qtyOutstandingPoAfter;

        $purchaseOrderItem->save();
    }

    private function formatQty(
        float $qty,
    ): string {
        return rtrim(
            rtrim(
                number_format(
                    $qty,
                    4,
                    ',',
                    '.',
                ),
                '0',
            ),
            ',',
        );
    }
}
