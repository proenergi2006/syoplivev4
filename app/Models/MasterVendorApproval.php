<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterVendorApproval extends Model
{
    protected $table = 'master_vendor_approvals';

    protected $fillable = [
        'vendor_id',
        'approval_flow_id',
        'approval_flow_step_id',
        'step_order',
        'approver_type',
        'approver_id',
        'approver_name_snapshot',
        'approval_mode',
        'label',
        'status',
        'notes',
        'approved_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function approvalFlow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approvalFlowStep()
    {
        return $this->belongsTo(ApprovalFlowStep::class, 'approval_flow_step_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_WAITING = 'WAITING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const MODE_ANY = 'ANY';
    public const MODE_ALL = 'ALL';

    public const APPROVER_TYPE_USER = 'USER';
    public const APPROVER_TYPE_ROLE = 'ROLE';
}
