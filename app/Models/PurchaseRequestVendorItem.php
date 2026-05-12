<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequestVendorItem extends Model
{
    use HasFactory, SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'pr_vendor_id',
        'nama_item',
        'qty',
        'satuan',
        'spesifikasi',
        'keterangan',
        'harga_unit',
        'subtotal'
    ];

    // Vendor Item -> PR
    // public function purchaseRequest()
    // {
    //     return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    // }

    // Vendor Item -> PO Items (bisa dipakai lebih dari satu PO kalau mau)
    public function purchaseOrderItems()
    {
        return $this->hasMany(
            PurchaseOrderItem::class,
            'purchase_request_vendor_item_id'
        );
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'satuan', 'id');
    }

    public function prVendor()
    {
        return $this->belongsTo(PurchaseRequestVendor::class, 'pr_vendor_id', 'id');
    }
}
