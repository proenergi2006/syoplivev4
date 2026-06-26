<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalFlow extends Model
{
    use SoftDeletes;

    protected $table = 'approval_flows';

    protected $fillable = [
        'document_type',
        'module_name',
        'area_type',
        'cabang',
        'creator_department_id',
        'permission_module_id',

        'name',
        'description',
        'min_amount',
        'max_amount',
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
        'permission_module_id' => 'integer',
    ];

    public const DOCUMENT_TYPE_PO = 'PO';
    public const DOCUMENT_TYPE_PR = 'PR';

    public const AREA_HO = 'HO';
    public const AREA_CABANG = 'CABANG';

    public function steps()
    {
        return $this->hasMany(ApprovalFlowStep::class, 'approval_flow_id')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(
            ApprovalFlowRule::class,
            'approval_flow_id',
        );
    }

    public function permissionModule(): BelongsTo
    {
        return $this->belongsTo(
            PermissionModule::class,
            'permission_module_id',
        );
    }

    public function activeSteps()
    {
        return $this->hasMany(ApprovalFlowStep::class, 'approval_flow_id')
            ->where('is_active', true)
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function creatorDepartment()
    {
        return $this->belongsTo(Department::class, 'creator_department_id');
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

    public function scopeForDocument($query, string $documentType)
    {
        return $query->where('document_type', strtoupper($documentType));
    }

    public function scopeForAmount($query, $amount)
    {
        return $query
            ->where('min_amount', '<=', $amount)
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_amount')
                    ->orWhere('max_amount', '>=', $amount);
            });
    }

    public function scopeForPurchaseRequestMatrix($query, string $areaType, ?string $cabang, ?int $creatorDepartmentId, $amount)
    {
        return $query
            ->active()
            ->forDocument(self::DOCUMENT_TYPE_PR)
            ->where('area_type', strtoupper($areaType))
            ->where(function ($q) use ($cabang) {
                /*
                |--------------------------------------------------------------------------
                | Cabang optional
                |--------------------------------------------------------------------------
                | Jika flow cabang null, berarti berlaku global untuk area tersebut.
                | Jika flow cabang diisi, berarti spesifik cabang tersebut.
                |--------------------------------------------------------------------------
                */
                $q->whereNull('cabang');

                if (!empty($cabang)) {
                    $q->orWhere('cabang', $cabang);
                }
            })
            ->where(function ($q) use ($creatorDepartmentId) {
                /*
                |--------------------------------------------------------------------------
                | Department optional
                |--------------------------------------------------------------------------
                | Untuk PR sebaiknya diisi.
                | Tapi dibuat nullable supaya bisa ada flow fallback global.
                |--------------------------------------------------------------------------
                */
                $q->whereNull('creator_department_id');

                if (!empty($creatorDepartmentId)) {
                    $q->orWhere('creator_department_id', $creatorDepartmentId);
                }
            })
            ->forAmount($amount);
    }
}
