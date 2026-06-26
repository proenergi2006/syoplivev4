<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalFlowRule extends Model
{
    protected $table = 'approval_flow_rules';

    protected $fillable = [
        'approval_flow_id',
        'requester_role_id',
        'cabang_id',
        'department_id',
        'min_amount',
        'max_amount',
        'priority',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'approval_flow_id' => 'integer',
        'requester_role_id' => 'integer',
        'cabang_id' => 'integer',
        'department_id' => 'integer',

        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',

        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function approvalFlow(): BelongsTo
    {
        return $this->belongsTo(
            ApprovalFlow::class,
            'approval_flow_id',
        );
    }

    public function requesterRole(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'requester_role_id',
        );
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(
            Cabang::class,
            'cabang_id',
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
        );
    }
}
