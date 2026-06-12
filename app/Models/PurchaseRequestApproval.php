<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestApproval extends Model
{
    protected $table = 'purchase_request_approvals';

    protected $fillable = [
        'purchase_request_id',
        'approval_flow_id',
        'approval_flow_step_id',

        'step_order',
        'label',

        'approver_type',
        'approver_id',
        'approver_name_snapshot',

        'approval_mode',
        'status',

        'signature_path',
        'signed_at',
        'approved_at',
        'rejected_at',
        'notes',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'approver_id' => 'integer',
        'signed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const APPROVER_TYPE_USER = 'USER';
    public const APPROVER_TYPE_ROLE = 'ROLE';

    public const APPROVAL_MODE_ANY = 'ANY';
    public const APPROVAL_MODE_ALL = 'ALL';

    public const STATUS_WAITING = 'WAITING';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function approvalFlow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approvalFlowStep()
    {
        return $this->belongsTo(ApprovalFlowStep::class, 'approval_flow_step_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approverRole()
    {
        return $this->belongsTo(Role::class, 'approver_id');
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('step_order')->orderBy('id');
    }

    public function isWaiting(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_WAITING;
    }

    public function isPending(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_REJECTED;
    }

    public function isSkipped(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_SKIPPED;
    }

    public function isAnyMode(): bool
    {
        return strtoupper((string) $this->approval_mode) === self::APPROVAL_MODE_ANY;
    }

    public function isAllMode(): bool
    {
        return strtoupper((string) $this->approval_mode) === self::APPROVAL_MODE_ALL;
    }

    public function isRoleApprover(): bool
    {
        return strtoupper((string) $this->approver_type) === self::APPROVER_TYPE_ROLE;
    }

    public function isUserApprover(): bool
    {
        return strtoupper((string) $this->approver_type) === self::APPROVER_TYPE_USER;
    }
}
