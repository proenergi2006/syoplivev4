<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'nomor_surat_jalan',

        'cabang',
        'id_department',

        'source_goods_return_id',
    ];

    protected $casts = [
        'tanggal_gr' => 'date',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cabang' => 'integer',
        'id_department' => 'integer',
        'source_goods_return_id' => 'integer',
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

    public function sourceGoodsReturn(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReturn::class,
            'source_goods_return_id',
        );
    }

    public function goodsReturns(): HasMany
    {
        return $this->hasMany(
            GoodsReturn::class,
            'goods_receive_id',
        );
    }

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_CANCELLED = 'CANCELLED';
}
