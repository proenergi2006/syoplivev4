<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalFlowStep extends Model
{
    protected $fillable = [
        'approval_flow_id',
        'step_order',
        'approver_type',
        'approver_id',
        'label',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }
}
