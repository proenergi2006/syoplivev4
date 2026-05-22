<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nomor_po',
        'tanggal_po',
        'vendor_id',
        'cabang',
        'id_department',
        'total_nilai',
        'status',
        'notes',
        'created_by',
        'approved_by',
        'dpp',
        'ppn',
        'requester_signature_path',
        'requester_signed_at',
        'requester_signed_by',
    ];

    /* ===============================
     | RELATION: PO ↔ PR
     =============================== */
    public function purchaseRequests()
    {
        return $this->belongsToMany(
            PurchaseRequest::class,
            'po_pr',
            'purchase_order_id',
            'purchase_request_id'
        )->withTimestamps();
    }

    /* ===============================
     | RELATION: PO ITEMS
     =============================== */
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function approvalHistories()
    {
        return $this->hasMany(
            PurchaseOrderApprovalHistory::class,
            'purchase_order_id'
        )->orderBy('id');
    }

    public function cabangData()
    {
        return $this->belongsTo(
            Cabang::class,
            'cabang',
            'id'
        );
    }

    public function departmentData()
    {
        return $this->belongsTo(
            Department::class,
            'id_department',
            'id'
        );
    }

    /**
     * Encrypted ID
     */
    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }

    public function requesterSignedBy()
    {
        return $this->belongsTo(User::class, 'requester_signed_by', 'id');
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseOrderApproval::class, 'purchase_order_id')
            ->orderBy('step_order');
    }
}
