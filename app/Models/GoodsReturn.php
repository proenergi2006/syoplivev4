<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class GoodsReturn extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'goods_returns';

    protected $fillable = [
        'nomor_return',
        'goods_receive_id',
        'purchase_order_id',
        'vendor_id',
        'cabang',
        'id_department',
        'tanggal_return',
        'status',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_notes',
    ];

    protected $appends = [
        'encrypted_id',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Public ID terenkripsi
    |--------------------------------------------------------------------------
    */
    protected function encryptedId(): Attribute
    {
        return Attribute::get(
            fn(): string => Crypt::encryptString(
                (string) $this->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Goods Receipt asal
    |--------------------------------------------------------------------------
    */
    public function goodsReceive(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReceive::class,
            'goods_receive_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Purchase Order asal
    |--------------------------------------------------------------------------
    */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrder::class,
            'purchase_order_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Vendor
    |--------------------------------------------------------------------------
    */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(
            MasterVendor::class,
            'vendor_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Department
    |--------------------------------------------------------------------------
    */
    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'id_department',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Detail item retur
    |--------------------------------------------------------------------------
    */
    public function items(): HasMany
    {
        return $this->hasMany(
            GoodsReturnItem::class,
            'goods_return_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GR replacement
    |--------------------------------------------------------------------------
    */
    public function replacementGoodsReceives(): HasMany
    {
        return $this->hasMany(
            GoodsReceive::class,
            'source_goods_return_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Audit user
    |--------------------------------------------------------------------------
    */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
        );
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'posted_by',
        );
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Attachment retur
    |--------------------------------------------------------------------------
    */
    public function attachments(): HasMany
    {
        return $this->hasMany(
            GoodsReturnAttachment::class,
            'goods_return_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper status
    |--------------------------------------------------------------------------
    */
    public function isDraft(): bool
    {
        return strtoupper(
            trim((string) $this->status),
        ) === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return strtoupper(
            trim((string) $this->status),
        ) === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return strtoupper(
            trim((string) $this->status),
        ) === self::STATUS_CANCELLED;
    }
}
