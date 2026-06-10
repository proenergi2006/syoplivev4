<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalFlow extends Model
{
    use SoftDeletes;

    protected $table = 'approval_flows';

    protected $fillable = [
        'module_name',
        'document_type',
        'name',
        'min_amount',
        'max_amount',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function steps()
    {
        return $this->hasMany(ApprovalFlowStep::class, 'approval_flow_id')
            ->orderBy('step_order');
    }

    public function activeSteps()
    {
        return $this->hasMany(ApprovalFlowStep::class, 'approval_flow_id')
            ->where('is_required', true)
            ->orderBy('step_order');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
