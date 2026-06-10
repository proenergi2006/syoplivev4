<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalFlowStep extends Model
{
    protected $table = 'approval_flow_steps';

    protected $fillable = [
        'approval_flow_id',
        'step_order',
        'approver_type',
        'approver_id',
        'label',
        'is_required',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'is_required' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function approvalFlow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    /**
     * Relasi ini dipakai kalau approver_type = ROLE.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'approver_id');
    }

    /**
     * Relasi ini dipakai kalau approver_type = USER.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function getApproverNameAttribute(): string
    {
        if (strtoupper((string) $this->approver_type) === 'ROLE') {
            return $this->role?->name ?? $this->label ?? '-';
        }

        if (strtoupper((string) $this->approver_type) === 'USER') {
            return $this->user?->name ?? $this->label ?? '-';
        }

        return $this->label ?? '-';
    }
}
