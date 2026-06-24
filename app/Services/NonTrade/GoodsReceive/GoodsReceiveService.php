<?php

namespace App\Services\NonTrade\GoodsReceive;

use App\Models\GoodsReceive;
use App\Models\GoodsReturn;
use App\Models\GoodsReturnItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GoodsReceiveService
{
    public function createDraftFromPurchaseOrder(
        PurchaseOrder $po,
        array $payload,
        int $userId,
    ): GoodsReceive {
        return DB::transaction(function () use (
            $po,
            $payload,
            $userId,
        ) {

            Log::info(
                '[GR DEBUG] createDraftFromPurchaseOrder terpanggil',
                [
                    'class' => static::class,
                    'file' => __FILE__,
                    'po_id' => $po->id,
                ],
            );
        /*
        |--------------------------------------------------------------------------
        | Lock Purchase Order
        |--------------------------------------------------------------------------
        | Gunakan data terbaru dari database, jangan hanya mengandalkan model
        | PurchaseOrder yang dikirim dari controller.
        |--------------------------------------------------------------------------
        */
            /** @var PurchaseOrder $lockedPurchaseOrder */
            $lockedPurchaseOrder = PurchaseOrder::query()
                ->whereKey($po->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPurchaseOrder->loadMissing([
                'vendor',
            ]);

            if (
                strtoupper(
                    trim(
                        (string) $lockedPurchaseOrder->status,
                    ),
                ) !== 'APPROVED'
            ) {
                throw ValidationException::withMessages([
                    'purchase_order_public_id' => [
                        'Goods Receipt hanya dapat dibuat dari Purchase Order yang sudah APPROVED.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil item yang benar-benar memiliki qty receive
        |--------------------------------------------------------------------------
        */
            $items = collect(
                $payload['items']
                    ?? [],
            )
                ->filter(function ($item) {
                    return (float) (
                        $item['qty_receive']
                        ?? 0
                    ) > 0;
                })
                ->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [
                        'Minimal harus ada satu item dengan qty receive lebih dari 0.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Hindari item PO yang sama dikirim dua kali
        |--------------------------------------------------------------------------
        */
            $duplicatePurchaseOrderItemIds = $items
                ->pluck('purchase_order_item_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->duplicates();

            if ($duplicatePurchaseOrderItemIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [
                        'Terdapat item Purchase Order yang dipilih lebih dari satu kali.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Goods Return sumber
        |--------------------------------------------------------------------------
        | Nilai ini harus sudah berupa database ID, bukan encrypted public ID.
        |--------------------------------------------------------------------------
        */
            $sourceGoodsReturnId = (
                isset($payload['source_goods_return_id'])
                && $payload['source_goods_return_id'] !== ''
            )
                ? (int) $payload['source_goods_return_id']
                : null;

            $sourceGoodsReturn = null;

            if ($sourceGoodsReturnId !== null) {
                /** @var GoodsReturn $sourceGoodsReturn */
                $sourceGoodsReturn = GoodsReturn::query()
                    ->whereKey($sourceGoodsReturnId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    strtoupper(
                        trim(
                            (string) $sourceGoodsReturn->status,
                        ),
                    ) !== GoodsReturn::STATUS_POSTED
                ) {
                    throw ValidationException::withMessages([
                        'source_goods_return_id' => [
                            'Goods Return sumber replacement harus berstatus POSTED.',
                        ],
                    ]);
                }

                if (
                    (int) $sourceGoodsReturn->purchase_order_id
                    !== (int) $lockedPurchaseOrder->id
                ) {
                    throw ValidationException::withMessages([
                        'source_goods_return_id' => [
                            'Purchase Order tidak sesuai dengan Goods Return sumber.',
                        ],
                    ]);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi seluruh item sebelum membuat header GR
        |--------------------------------------------------------------------------
        */
            $preparedItems = [];

            foreach ($items as $index => $itemPayload) {
                $purchaseOrderItemId = (int) (
                    $itemPayload['purchase_order_item_id']
                    ?? 0
                );

            /*
            |--------------------------------------------------------------------------
            | Lock item Purchase Order
            |--------------------------------------------------------------------------
            */
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = PurchaseOrderItem::query()
                    ->whereKey($purchaseOrderItemId)
                    ->where(
                        'purchase_order_id',
                        $lockedPurchaseOrder->id,
                    )
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (!$poItem) {
                    throw ValidationException::withMessages([
                        "items.{$index}.purchase_order_item_id" => [
                            'Item Purchase Order tidak valid atau tidak ditemukan.',
                        ],
                    ]);
                }

                $qtyPo = (float) (
                    $poItem->qty
                    ?? 0
                );

                $qtyReceived = (float) (
                    $poItem->qty_received
                    ?? 0
                );

                $qtyReceive = (float) (
                    $itemPayload['qty_receive']
                    ?? 0
                );

                if ($qtyReceive <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_receive" => [
                            'Qty receive item '
                                . ($poItem->nama_item ?? '-')
                                . ' harus lebih besar dari nol.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Snapshot outstanding terkini
            |--------------------------------------------------------------------------
            | Ini merupakan sumber utama outstanding karena nilainya sudah berubah
            | ketika Goods Receipt atau Goods Return diposting/dibatalkan.
            |--------------------------------------------------------------------------
            */
                $baseOutstanding = max(
                    $qtyPo - $qtyReceived,
                    0,
                );

                /*
            |--------------------------------------------------------------------------
            | Reservation dari GR DRAFT existing
            |--------------------------------------------------------------------------
            | Draft yang sedang dibuat belum ada di database, sehingga belum perlu
            | dikecualikan dengan ID.
            |--------------------------------------------------------------------------
            */
                $qtyDraftReserved = (float) DB::table(
                    'goods_receive_items as gri',
                )
                    ->join(
                        'goods_receives as draft_gr',
                        'draft_gr.id',
                        '=',
                        'gri.goods_receive_id',
                    )
                    ->where(
                        'gri.purchase_order_item_id',
                        $poItem->id,
                    )
                    ->whereRaw(
                        'UPPER(TRIM(draft_gr.status)) = ?',
                        ['DRAFT'],
                    )
                    ->whereNull(
                        'draft_gr.deleted_at',
                    )
                    ->sum(
                        'gri.qty_receive',
                    );

                $availableOutstanding = max(
                    $baseOutstanding
                        - $qtyDraftReserved,
                    0,
                );

                if (
                    $qtyReceive
                    > (
                        $availableOutstanding
                        + 0.0001
                    )
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_receive" => [
                            'Qty receive item '
                                . ($poItem->nama_item ?? '-')
                                . ' melebihi outstanding Purchase Order. Maksimal '
                                . $availableOutstanding
                                . '.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Validasi qty replacement
            |--------------------------------------------------------------------------
            | Dijalankan hanya jika source_goods_return_id sudah terisi.
            |--------------------------------------------------------------------------
            */
                if ($sourceGoodsReturn !== null) {
                    $qtyReturnSource = (float) GoodsReturnItem::query()
                        ->where(
                            'goods_return_id',
                            $sourceGoodsReturn->id,
                        )
                        ->where(
                            'purchase_order_item_id',
                            $poItem->id,
                        )
                        ->sum(
                            'qty_return',
                        );

                    if ($qtyReturnSource <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$index}.purchase_order_item_id" => [
                                'Item '
                                    . ($poItem->nama_item ?? '-')
                                    . ' tidak termasuk dalam Goods Return sumber.',
                            ],
                        ]);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Replacement yang sudah menggunakan qty Return
                |--------------------------------------------------------------------------
                | DRAFT menjadi reservation dan POSTED berarti sudah direalisasikan.
                |--------------------------------------------------------------------------
                */
                    $qtyReplacementUsed = (float) DB::table(
                        'goods_receive_items as replacement_item',
                    )
                        ->join(
                            'goods_receives as replacement_gr',
                            'replacement_gr.id',
                            '=',
                            'replacement_item.goods_receive_id',
                        )
                        ->where(
                            'replacement_gr.source_goods_return_id',
                            $sourceGoodsReturn->id,
                        )
                        ->where(
                            'replacement_item.purchase_order_item_id',
                            $poItem->id,
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
                            'replacement_item.qty_receive',
                        );

                    $replacementOutstanding = max(
                        $qtyReturnSource
                            - $qtyReplacementUsed,
                        0,
                    );

                    if (
                        $qtyReceive
                        > (
                            $replacementOutstanding
                            + 0.0001
                        )
                    ) {
                        throw ValidationException::withMessages([
                            "items.{$index}.qty_receive" => [
                                'Qty replacement item '
                                    . ($poItem->nama_item ?? '-')
                                    . ' melebihi outstanding replacement. Maksimal '
                                    . $replacementOutstanding
                                    . '.',
                            ],
                        ]);
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Unit item
            |--------------------------------------------------------------------------
            */
                $unitId = null;

                if (
                    $poItem->satuan !== null
                    && $poItem->satuan !== ''
                    && is_numeric($poItem->satuan)
                ) {
                    $unitId = (int) $poItem->satuan;
                }

                if ($unitId === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}.purchase_order_item_id" => [
                            'Unit item '
                                . ($poItem->nama_item ?? '-')
                                . ' tidak ditemukan.',
                        ],
                    ]);
                }

                $preparedItems[] = [
                    'purchase_order_item_id'
                    => $poItem->id,

                    'purchase_request_item_id'
                    => $poItem->purchase_request_item_id
                        ?? null,

                    'nama_item'
                    => $poItem->nama_item
                        ?? $poItem->item_name
                        ?? '-',

                    'unit'
                    => $unitId,

                    'qty_ordered'
                    => $qtyPo,

                    'qty_received_before'
                    => $qtyReceived,

                    'qty_receive'
                    => $qtyReceive,

                    'qty_received_after'
                    => $qtyReceived
                        + $qtyReceive,

                    /*
                |--------------------------------------------------------------------------
                | Sisa yang belum direservasi setelah draft ini dibuat
                |--------------------------------------------------------------------------
                */
                    'qty_outstanding'
                    => max(
                        $availableOutstanding
                            - $qtyReceive,
                        0,
                    ),

                    'notes'
                    => $itemPayload['notes']
                        ?? null,
                ];
            }

        /*
        |--------------------------------------------------------------------------
        | Buat header Goods Receipt
        |--------------------------------------------------------------------------
        */
            /** @var GoodsReceive $goodsReceive */
            $goodsReceive = GoodsReceive::query()->create([
                'nomor_gr'
                => $payload['nomor_gr'],

                'purchase_order_id'
                => $lockedPurchaseOrder->id,

                'source_goods_return_id'
                => $sourceGoodsReturn?->id,

                'vendor_id'
                => $lockedPurchaseOrder->vendor_id,

                'cabang'
                => $lockedPurchaseOrder->cabang,

                'id_department'
                => $lockedPurchaseOrder->id_department,

                'tanggal_gr'
                => $payload['tanggal_gr']
                    ?? now()->toDateString(),

                'nomor_surat_jalan'
                => $payload['nomor_surat_jalan']
                    ?? null,

                'status'
                => 'DRAFT',

                'notes'
                => $payload['notes']
                    ?? null,

                'created_by'
                => $userId,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Buat detail Goods Receipt
        |--------------------------------------------------------------------------
        */
            foreach ($preparedItems as $preparedItem) {
                $goodsReceive->items()->create(
                    $preparedItem,
                );
            }

            return $goodsReceive->load([
                'items',
                'purchaseOrder',
                'vendor',
                'sourceGoodsReturn',
            ]);
        });
    }
}
