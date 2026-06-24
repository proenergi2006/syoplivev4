<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiveItem extends Model
{
    protected $fillable = [
        'goods_receive_id',

        'purchase_order_item_id',
        'purchase_request_item_id',

        'nama_item',
        'unit',

        'qty_ordered',
        'qty_received_before',
        'qty_receive',
        'qty_received_after',
        'qty_outstanding',

        'notes',
    ];

    public function goodsReceive()
    {
        return $this->belongsTo(GoodsReceive::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function purchaseRequestItem()
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function unitData()
    {
        return $this->belongsTo(Unit::class, 'unit', 'id');
    }
}
