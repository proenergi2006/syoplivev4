<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalFlowStep extends Model
{

    protected $table = 'approval_flow_steps';

    protected $fillable = [
        'approval_flow_id',
        'step_order',
        'label',
        'approver_type',
        'approver_id',
        'approval_mode',
        'is_required',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'approver_id' => 'integer',
        'is_required' => 'boolean',
    ];

    public const APPROVER_TYPE_USER = 'USER';
    public const APPROVER_TYPE_ROLE = 'ROLE';

    public const APPROVAL_MODE_ANY = 'ANY';
    public const APPROVAL_MODE_ALL = 'ALL';

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approvalFlow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'approver_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approverRole()
    {
        return $this->belongsTo(Role::class, 'approver_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('step_order')->orderBy('id');
    }

    public function isRoleApprover(): bool
    {
        return strtoupper((string) $this->approver_type) === self::APPROVER_TYPE_ROLE;
    }

    public function isUserApprover(): bool
    {
        return strtoupper((string) $this->approver_type) === self::APPROVER_TYPE_USER;
    }

    public function isAnyMode(): bool
    {
        return strtoupper((string) $this->approval_mode) === self::APPROVAL_MODE_ANY;
    }

    public function isAllMode(): bool
    {
        return strtoupper((string) $this->approval_mode) === self::APPROVAL_MODE_ALL;
    }
}
