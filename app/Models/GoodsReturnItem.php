<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReturnItem extends Model
{
    protected $table = 'goods_return_items';

    protected $fillable = [
        'goods_return_id',
        'goods_receive_item_id',
        'purchase_order_item_id',
        'nama_item',
        'unit_id',
        'qty_received',
        'qty_returned_before',
        'qty_return',
        'qty_returned_after',
        'qty_returnable_after',
        'reason_id',
        'reason_notes',
    ];

    protected function casts(): array
    {
        return [
            'goods_return_id' => 'integer',
            'goods_receive_item_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'unit_id' => 'integer',
            'reason_id' => 'integer',

            'qty_received' => 'decimal:4',
            'qty_returned_before' => 'decimal:4',
            'qty_return' => 'decimal:4',
            'qty_returned_after' => 'decimal:4',
            'qty_returnable_after' => 'decimal:4',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Header retur
    |--------------------------------------------------------------------------
    */
    public function goodsReturn(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReturn::class,
            'goods_return_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Item GR asal
    |--------------------------------------------------------------------------
    */
    public function goodsReceiveItem(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReceiveItem::class,
            'goods_receive_item_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Item PO asal
    |--------------------------------------------------------------------------
    */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrderItem::class,
            'purchase_order_item_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Satuan
    |--------------------------------------------------------------------------
    */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(
            Unit::class,
            'unit_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Alasan retur
    |--------------------------------------------------------------------------
    */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReturnReason::class,
            'reason_id',
        );
    }
}
