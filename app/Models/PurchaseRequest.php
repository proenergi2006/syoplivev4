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

    protected $appends = [
        'encrypted_id',
        'full_lampiran_url',
    ];

    protected $fillable = [
        'nomor_pr',
        'tanggal_pr',
        'cabang',
        'id_department',
        'kategori',
        'pr_type',
        'recommended_vendor_id',
        'notes',
        'total_amount',

        'status',
        'status_po',

        'submitted_at',
        'submitted_by',

        'final_approved_at',
        'final_approved_by',

        'rejected_at',
        'rejected_by',
        'rejected_notes',

        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_pr' => 'date',
        'submitted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::deleting(function ($pr) {

            // Soft delete / force delete items
            if ($pr->isForceDeleting()) {

                $pr->items()->forceDelete();
                $pr->attachments()->forceDelete();
                $pr->approvalHistories()->forceDelete();
            } else {

                foreach ($pr->items as $item) {
                    $item->delete();
                }

                foreach ($pr->attachments as $attachment) {
                    $attachment->delete();
                }

                foreach ($pr->approvalHistories as $history) {
                    $history->delete();
                }
            }
        });
    }

    /**
     * Approval Histories
     */
    public function approvalHistories()
    {
        return $this->hasMany(
            PurchaseRequestHistoryApproval::class,
            'purchase_request_id'
        )->orderBy('level');
    }

    /**
     * PR Items
     */
    public function items()
    {
        return $this->hasMany(
            PurchaseRequestItem::class,
            'purchase_request_id',
            'id'
        );
    }

    /**
     * Attachments
     */
    public function attachments()
    {
        return $this->hasMany(
            PrAttachment::class,
            'purchase_request_id',
            'id'
        );
    }

    /**
     * Recommended Vendor
     */
    public function recommendedVendor()
    {
        return $this->belongsTo(
            MasterVendor::class,
            'recommended_vendor_id',
            'id'
        );
    }

    /**
     * Purchase Orders
     */
    public function purchaseOrders()
    {
        return $this->belongsToMany(
            PurchaseOrder::class,
            'po_pr',
            'purchase_request_id',
            'purchase_order_id'
        )->withTimestamps();
    }

    /**
     * Cabang
     */
    public function cabangData()
    {
        return $this->belongsTo(
            Cabang::class,
            'cabang',
            'id'
        );
    }

    /**
     * Department
     */
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

    /**
     * Full Lampiran URL
     */
    public function getFullLampiranUrlAttribute()
    {
        if (!$this->path_lampiran) {
            return null;
        }

        return asset('storage/' . $this->path_lampiran);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function finalApprover()
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function waitingApprovals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->where('status', PurchaseRequestApproval::STATUS_WAITING)
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function pendingApprovals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->where('status', PurchaseRequestApproval::STATUS_PENDING)
            ->orderBy('step_order')
            ->orderBy('id');
    }

    /**
     * Status Constants
     */
    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN PROGRESS';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    // Status PO
    const STATUS_PO_OPEN = 'OPEN';
    const STATUS_PO_PARTIAL = 'PARTIAL';
    const STATUS_PO_COMPLETED = 'COMPLETED';
}
