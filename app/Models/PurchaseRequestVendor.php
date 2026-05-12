<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequestVendor extends Model
{
    use HasFactory, SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'purchase_request_vendors';
    protected $fillable = [
        'purchase_request_id',
        'vendor_id',
        'price_offer',
        'dpp',
        'ppn',
        'is_selected',
        'keterangan',
    ];

    protected static function booted()
    {
        static::deleting(function ($vendor) {

            if ($vendor->isForceDeleting()) {
                $vendor->items()->forceDelete();
            } else {
                $vendor->items()->delete();
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseRequestVendorItem::class, 'pr_vendor_id', 'id');
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(
            PurchaseRequest::class,
            'purchase_request_id'
        );
    }
}
