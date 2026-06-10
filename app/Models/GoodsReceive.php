<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceive extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nomor_gr',
        'purchase_order_id',
        'vendor_id',
        'tanggal_gr',
        'status',
        'notes',

        'created_by',

        'posted_by',
        'posted_at',

        'cancelled_by',
        'cancelled_at',
        'cancel_notes',
        'nomor_surat_jalan'
    ];

    protected $casts = [
        'tanggal_gr' => 'date',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $appends = [
        'encrypted_id',
    ];

    public function getEncryptedIdAttribute(): string
    {
        return encrypt($this->id);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiveItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function attachments()
    {
        return $this->hasMany(GoodsReceiveAttachment::class, 'goods_receive_id');
    }

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_CANCELLED = 'CANCELLED';
}
