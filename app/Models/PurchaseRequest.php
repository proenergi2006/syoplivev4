<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'purchase_requests';
    protected $dates = ['deleted_at'];
    protected $appends = ['encrypted_id', 'full_lampiran_url'];
    protected $fillable = [
        'nomor_pr',
        'tanggal_pr',
        'cabang',
        'id_department',
        'kategori',
        'notes',
        'lampiran_request',
        'path_lampiran',
        'size_lampiran',
        'type_lampiran',
        'status',
        'current_level',
        'requested_by',
        'request_date'
    ];

    protected static function booted()
    {
        static::deleting(function ($pr) {
            if ($pr->isForceDeleting()) {
                // Jika force delete → hapus permanen
                $pr->vendors()->forceDelete();
            } else {
                // Soft delete vendor
                foreach ($pr->vendors as $vendor) {
                    $vendor->delete(); // soft delete vendor & relasinya
                }
            }
        });
    }

    public function approvalHistories()
    {
        return $this->hasMany(
            PurchaseRequestHistoryApproval::class,
            'purchase_request_id'
        )->orderBy('level');
    }

    public function vendors()
    {
        return $this->hasMany(PurchaseRequestVendor::class, 'purchase_request_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseRequestVendorItem::class, 'pr_vendor_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(PrAttachment::class, 'purchase_request_id', 'id');
    }

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }

    public function getFullLampiranUrlAttribute()
    {
        if (!$this->path_lampiran) {
            return null;
        }

        return asset('storage/' . $this->path_lampiran);
    }

    public function purchaseOrders()
    {
        return $this->belongsToMany(
            PurchaseOrder::class,
            'po_pr',
            'purchase_request_id',
            'purchase_order_id'
        )->withTimestamps();
    }

    public function cabangData()
    {
        return $this->belongsTo(Cabang::class, 'cabang', 'id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'id_department', 'id');
    }

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN PROGRESS';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
}
