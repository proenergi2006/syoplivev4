<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends Model
{
    use SoftDeletes;

    protected $table = 'purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'purchase_request_item_id',
        'nama_item',
        'qty',
        'satuan',
        'spesifikasi',
        'keterangan',
        'harga_unit',
        'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
        'harga_unit' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /* =============================
     | RELATIONSHIPS
     ============================= */

    // Item -> PO
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'id');
    }

    public function purchaseRequestItem()
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id', 'id');
    }
}
