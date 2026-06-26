<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\PermissionModule;
use Illuminate\Validation\ValidationException;

class ApprovalFlowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 0 ? $perPage : 10;
            $perPage = $perPage > 100 ? 100 : $perPage;

            $search = trim((string) $request->input('search', ''));
            $documentType = strtoupper(trim((string) $request->input('document_type', '')));
            $status = strtolower(trim((string) $request->input('status', 'all')));

            if ($status === '' || $status === 'semua') {
                $status = 'all';
            }

            $query = ApprovalFlow::query()
                ->with([
                    'steps.role',
                    'steps.user',
                    'creatorDepartment',
                    'permissionModule',
                ])
                ->when($documentType !== '' && $documentType !== 'ALL' && $documentType !== 'SEMUA', function ($query) use ($documentType) {
                    $query->where('document_type', $documentType);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'module_name',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhereHas(
                                'permissionModule',
                                function ($moduleQuery) use ($search) {
                                    $moduleQuery
                                        ->where(
                                            'code',
                                            'ILIKE',
                                            "%{$search}%",
                                        )
                                        ->orWhere(
                                            'name',
                                            'ILIKE',
                                            "%{$search}%",
                                        );
                                },
                            )
                            ->orWhere('document_type', 'ILIKE', "%{$search}%")
                            ->orWhere('name', 'ILIKE', "%{$search}%")
                            ->orWhere('description', 'ILIKE', "%{$search}%")
                            ->orWhereHas('steps', function ($stepQuery) use ($search) {
                                $stepQuery
                                    ->where('label', 'ILIKE', "%{$search}%")
                                    ->orWhere('approver_type', 'ILIKE', "%{$search}%")
                                    ->orWhereHas('role', function ($roleQuery) use ($search) {
                                        $roleQuery->where('name', 'ILIKE', "%{$search}%");
                                    })
                                    ->orWhereHas('user', function ($userQuery) use ($search) {
                                        $userQuery->where('name', 'ILIKE', "%{$search}%");
                                    });
                            });
                    });
                });

            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }

            if ($request->filled('area_type')) {
                $query->where('area_type', strtoupper((string) $request->input('area_type')));
            }

            if ($request->filled('creator_department_id')) {
                $query->where('creator_department_id', (int) $request->input('creator_department_id'));
            }

            $approvalFlows = $query
                ->orderBy('document_type')
                ->orderByRaw('COALESCE(min_amount, 0) ASC')
                ->orderByRaw('COALESCE(max_amount, 999999999999999999) ASC')
                ->orderBy('id')
                ->paginate($perPage);

            $approvalFlows->getCollection()->transform(function (ApprovalFlow $flow) {
                $steps = $flow->steps->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    $approverName = '-';

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->kode
                            ?? $step->approverRole?->kode
                            ?? $step->role?->kode
                            ?? $step->approverRole?->kode
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),
                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,
                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                        'approver_scope' => $step->approver_scope
                            ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                    ];
                })->values();

                $firstStep = $steps->first();

                return [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    /**
                     * Untuk compatibility dengan index.vue sebelumnya.
                     * Karena data asli sekarang ada di steps.
                     */
                    'sequence' => $firstStep['step_order'] ?? null,
                    'approval_order' => $firstStep['step_order'] ?? null,
                    'approval_role_name' => $firstStep['approver_name'] ?? '-',
                    'role_name' => $firstStep['approver_name'] ?? '-',

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? $flow->creatorDepartment?->kode
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    'steps_count' => $steps->count(),
                    'steps' => $steps,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data approval flow berhasil dimuat.',
                'data' => $approvalFlows,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Approval Flow] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function toggleStatus(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])->findOrFail($id);

            $flow->update([
                'is_active' => !$flow->is_active,
                'updated_by' => $request->user()->id ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $flow->is_active
                    ? 'Approval flow berhasil diaktifkan.'
                    : 'Approval flow berhasil dinonaktifkan.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => $flow->encrypted_id ?? Crypt::encryptString((string) $flow->id),
                    'is_active' => (bool) $flow->is_active,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Toggle status error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah status approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'document_type' => ['required', 'string', 'max:50'],
                'module_name' => ['nullable', 'string', 'max:100'],
                'permission_module_id' => [
                    'nullable',
                    'integer',
                    'exists:permission_modules,id',
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],

                /*
            |--------------------------------------------------------------------------
            | PR Matrix Condition
            |--------------------------------------------------------------------------
            */
                'area_type' => ['nullable', 'string', 'max:50'],
                'cabang' => ['nullable', 'string', 'max:100'],
                'creator_department_id' => ['nullable', 'integer'],

                /*
            |--------------------------------------------------------------------------
            | Nested Steps
            |--------------------------------------------------------------------------
            */
                'steps' => ['required', 'array', 'min:1'],
                'steps.*.step_order' => ['required', 'integer', 'min:1'],
                'steps.*.label' => ['nullable', 'string', 'max:255'],
                'steps.*.approval_mode' => ['nullable', 'string', 'in:ANY,ALL'],

                'steps.*.approvers' => ['required', 'array', 'min:1'],
                'steps.*.approvers.*.approver_type' => ['required', 'string', 'in:ROLE,USER'],
                'steps.*.approvers.*.approver_id' => ['required', 'integer'],
                'steps.*.approver_scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
                    ]),
                ],
            ]);

            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])
                ->lockForUpdate()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Normalize Document Type
        |--------------------------------------------------------------------------
        | Jangan dibatasi hanya PO / PR, karena Vendor juga akan masuk.
        |--------------------------------------------------------------------------
        */
            $documentType = $this->normalizeDocumentType(
                (string) $request->input('document_type'),
            );

            $documentTypeUpper = strtoupper(
                $documentType,
            );

            $permissionModule = $this->resolvePermissionModule(
                request: $request,
                documentType: $documentType,
            );

            /*
        |--------------------------------------------------------------------------
        | Module Name
        |--------------------------------------------------------------------------
        */
            $moduleName = $request->filled('module_name')
                ? trim((string) $request->input('module_name'))
                : 'Procurement';

            if ($moduleName === '') {
                $moduleName = 'Procurement';
            }

            /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */
            $minAmount = $request->filled('min_amount')
                ? (float) $request->input('min_amount')
                : null;

            $maxAmount = $request->filled('max_amount')
                ? (float) $request->input('max_amount')
                : null;

            if (
                $minAmount !== null
                && $maxAmount !== null
                && $maxAmount > 0
                && $maxAmount < $minAmount
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | PR Condition
        |--------------------------------------------------------------------------
        */
            $areaType = $request->filled('area_type')
                ? strtoupper(trim((string) $request->input('area_type')))
                : null;

            $creatorDepartmentId = $request->filled('creator_department_id')
                ? (int) $request->input('creator_department_id')
                : null;

            if ($documentTypeUpper === 'PR') {
                if (!in_array($areaType, ['HO', 'CABANG'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Area type wajib dipilih untuk approval PR.',
                    ], 422);
                }

                if (!$creatorDepartmentId) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Department wajib dipilih untuk approval PR.',
                    ], 422);
                }
            } else {
                $areaType = null;
                $creatorDepartmentId = null;
            }

            /*
        |--------------------------------------------------------------------------
        | Cabang
        |--------------------------------------------------------------------------
        | Untuk konsep sekarang:
        | HO     = area_type HO, cabang null
        | CABANG = area_type CABANG, cabang null, artinya semua cabang
        |--------------------------------------------------------------------------
        */
            $cabang = null;

            $steps = collect($request->input('steps', []))
                ->values();

            if ($steps->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Minimal harus ada 1 step approval.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi Duplicate Approver dalam Step yang Sama
        |--------------------------------------------------------------------------
        | ROLE-10 dan USER-10 dianggap berbeda.
        |--------------------------------------------------------------------------
        */
            foreach ($steps as $stepIndex => $step) {
                $approvers = collect($step['approvers'] ?? [])->values();

                if ($approvers->isEmpty()) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Step ' . ($stepIndex + 1) . ' minimal memiliki 1 approver.',
                    ], 422);
                }

                $usedApproversInStep = [];

                foreach ($approvers as $approver) {
                    $approverType = strtoupper((string) ($approver['approver_type'] ?? ''));
                    $approverId = (int) ($approver['approver_id'] ?? 0);

                    $approverKey = $approverType . '-' . $approverId;

                    if (in_array($approverKey, $usedApproversInStep, true)) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Approver pada step ' . ($stepIndex + 1) . ' duplikat.',
                        ], 422);
                    }

                    $usedApproversInStep[] = $approverKey;
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Update Approval Flow Header
        |--------------------------------------------------------------------------
        */
            $flow->update([
                'document_type' => $documentType,

                'permission_module_id'
                => $permissionModule->id,

                'module_name' => $this->getLegacyModuleName(
                    documentType: $documentType,
                    permissionModule: $permissionModule,
                ),
                'name' => $request->input('name'),
                'description' => $request->input('description'),

                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_active' => $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : (bool) $flow->is_active,

                'area_type' => $areaType,
                'cabang' => $cabang,
                'creator_department_id' => $creatorDepartmentId,

                'updated_by' => $request->user()->id ?? null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Replace Approval Flow Steps
        |--------------------------------------------------------------------------
        | Hapus step lama, lalu insert ulang dari payload nested.
        | Aman karena ini master flow. Transaksi lama sudah punya snapshot approval sendiri.
        |--------------------------------------------------------------------------
        */
            DB::table('approval_flow_steps')
                ->where('approval_flow_id', $flow->id)
                ->delete();

            foreach ($steps as $stepIndex => $step) {
                $stepOrder = (int) ($step['step_order'] ?? ($stepIndex + 1));
                $label = $step['label'] ?? null;

                $approvalMode = strtoupper((string) ($step['approval_mode'] ?? 'ANY'));

                if (!in_array($approvalMode, ['ANY', 'ALL'], true)) {
                    $approvalMode = 'ANY';
                }

                $approvers = collect($step['approvers'] ?? [])->values();

                foreach ($approvers as $approver) {
                    DB::table('approval_flow_steps')->insert([
                        'approval_flow_id' => $flow->id,
                        'step_order' => $stepOrder,
                        'approver_type' => strtoupper((string) $approver['approver_type']),
                        'approver_id' => (int) $approver['approver_id'],
                        'label' => $label,
                        'approval_mode' => $approvalMode,
                        'approver_scope' => strtoupper(
                            trim(
                                (string) (
                                    $step['approver_scope']
                                    ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
                                )
                            )
                        ),
                        'is_required' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            $flow->load([
                'steps.role',
                'steps.user',
                'creatorDepartment',
                'permissionModule',
            ]);

            $stepsResponse = $flow->steps
                ->sortBy([
                    ['step_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->name
                            ?? $step->role?->nama
                            ?? $step->approverRole?->name
                            ?? $step->approverRole?->nama
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),

                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,

                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil diperbarui.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps_count' => $stepsResponse->count(),
                    'steps' => $stepsResponse,

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Update error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])->findOrFail($id);

            /*
            |--------------------------------------------------------------------------
            | Soft delete approval flow
            |--------------------------------------------------------------------------
            | Data PO lama aman, karena PO yang sudah submit sudah punya snapshot
            | approval di table purchase_order_approvals.
            |--------------------------------------------------------------------------
            */

            $flow->update([
                'updated_by' => $request->user()->id ?? null,
            ]);

            $flow->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'document_type' => ['required', 'string', 'max:50'],
                'module_name' => ['nullable', 'string', 'max:100'],
                'permission_module_id' => [
                    'nullable',
                    'integer',
                    'exists:permission_modules,id',
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],

                /*
            |--------------------------------------------------------------------------
            | PR Matrix Condition
            |--------------------------------------------------------------------------
            | Field ini hanya wajib kalau document_type = PR.
            |--------------------------------------------------------------------------
            */
                'area_type' => ['nullable', 'string', 'max:50'],
                'cabang' => ['nullable', 'string', 'max:100'],
                'creator_department_id' => ['nullable', 'integer'],

                /*
            |--------------------------------------------------------------------------
            | Nested Steps
            |--------------------------------------------------------------------------
            | FE mengirim:
            | steps[].approvers[]
            |--------------------------------------------------------------------------
            */
                'steps' => ['required', 'array', 'min:1'],
                'steps.*.step_order' => ['required', 'integer', 'min:1'],
                'steps.*.label' => ['nullable', 'string', 'max:255'],
                'steps.*.approval_mode' => ['nullable', 'string', 'in:ANY,ALL'],

                'steps.*.approvers' => ['required', 'array', 'min:1'],
                'steps.*.approvers.*.approver_type' => ['required', 'string', 'in:ROLE,USER'],
                'steps.*.approvers.*.approver_id' => ['required', 'integer'],
                'steps.*.approver_scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
                    ]),
                ],
            ]);

            $documentType = $this->normalizeDocumentType(
                (string) $request->input('document_type'),
            );

            $documentTypeUpper = strtoupper(
                $documentType,
            );

            $permissionModule = $this->resolvePermissionModule(
                request: $request,
                documentType: $documentType,
            );

            $minAmount = $request->filled('min_amount')
                ? (float) $request->input('min_amount')
                : null;

            $maxAmount = $request->filled('max_amount')
                ? (float) $request->input('max_amount')
                : null;

            if (
                $minAmount !== null
                && $maxAmount !== null
                && $maxAmount > 0
                && $maxAmount < $minAmount
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai.',
                ], 422);
            }

            $areaType = $request->filled('area_type')
                ? strtoupper(trim((string) $request->input('area_type')))
                : null;

            $creatorDepartmentId = $request->filled('creator_department_id')
                ? (int) $request->input('creator_department_id')
                : null;

            /*
        |--------------------------------------------------------------------------
        | Validasi khusus PR
        |--------------------------------------------------------------------------
        | Untuk PR, area_type dan creator_department_id wajib.
        | Cabang tidak wajib karena CABANG berarti semua cabang.
        |--------------------------------------------------------------------------
        */
            if ($documentTypeUpper === 'PR') {
                if (!in_array($areaType, ['HO', 'CABANG'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Area type wajib dipilih untuk approval PR.',
                    ], 422);
                }

                if (!$creatorDepartmentId) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Creator department wajib dipilih untuk approval PR.',
                    ], 422);
                }
            } else {
                $areaType = null;
                $creatorDepartmentId = null;
            }

            /*
        |--------------------------------------------------------------------------
        | Cabang
        |--------------------------------------------------------------------------
        | Untuk konsep saat ini:
        | - HO = cabang null
        | - CABANG = cabang null, artinya semua cabang
        |--------------------------------------------------------------------------
        */
            $cabang = null;

            $steps = collect($request->input('steps', []))
                ->values();

            if ($steps->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Minimal harus ada 1 step approval.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi duplicate approver dalam step yang sama
        |--------------------------------------------------------------------------
        | ROLE-10 dan USER-10 dianggap berbeda.
        | Approver yang sama boleh muncul di step berbeda? Sebaiknya tidak.
        | Tapi untuk aman terhadap existing flow, kita validasi per step dulu.
        |--------------------------------------------------------------------------
        */
            foreach ($steps as $stepIndex => $step) {
                $approvers = collect($step['approvers'] ?? [])->values();

                if ($approvers->isEmpty()) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Step ' . ($stepIndex + 1) . ' minimal memiliki 1 approver.',
                    ], 422);
                }

                $usedApproversInStep = [];

                foreach ($approvers as $approverIndex => $approver) {
                    $approverType = strtoupper((string) ($approver['approver_type'] ?? ''));
                    $approverId = (int) ($approver['approver_id'] ?? 0);

                    $approverKey = $approverType . '-' . $approverId;

                    if (in_array($approverKey, $usedApproversInStep, true)) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Approver pada step ' . ($stepIndex + 1) . ' duplikat.',
                        ], 422);
                    }

                    $usedApproversInStep[] = $approverKey;
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Create Approval Flow Header
        |--------------------------------------------------------------------------
        */
            $flow = ApprovalFlow::create([
                'document_type' => $documentType,
                'permission_module_id'
                => $permissionModule->id,
                'module_name' => $this->getLegacyModuleName(
                    documentType: $documentType,
                    permissionModule: $permissionModule,
                ),
                'name' => $request->input('name'),
                'description' => $request->input('description'),

                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_active' => $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : true,

                'area_type' => $areaType,
                'cabang' => $cabang,
                'creator_department_id' => $creatorDepartmentId,

                'created_by' => $request->user()->id ?? null,
                'updated_by' => $request->user()->id ?? null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Create Approval Flow Steps
        |--------------------------------------------------------------------------
        | Nested payload di-flatten.
        | Kalau step 1 punya Adm / ADH, akan insert 2 row dengan step_order = 1.
        |--------------------------------------------------------------------------
        */
            foreach ($steps as $stepIndex => $step) {
                $stepOrder = (int) ($step['step_order'] ?? ($stepIndex + 1));
                $label = $step['label'] ?? null;
                $approvalMode = strtoupper((string) ($step['approval_mode'] ?? 'ANY'));

                if (!in_array($approvalMode, ['ANY', 'ALL'], true)) {
                    $approvalMode = 'ANY';
                }

                $approvers = collect($step['approvers'] ?? [])->values();

                foreach ($approvers as $approver) {
                    DB::table('approval_flow_steps')->insert([
                        'approval_flow_id' => $flow->id,
                        'step_order' => $stepOrder,
                        'approver_type' => strtoupper((string) $approver['approver_type']),
                        'approver_id' => (int) $approver['approver_id'],
                        'label' => $label,
                        'approval_mode' => $approvalMode,
                        'approver_scope' => strtoupper(
                            trim(
                                (string) (
                                    $step['approver_scope']
                                    ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
                                )
                            )
                        ),
                        'is_required' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            $flow->load([
                'steps.role',
                'steps.user',
                'creatorDepartment',
                'permissionModule',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil dibuat.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'description' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps' => $flow->steps
                        ->sortBy([
                            ['step_order', 'asc'],
                            ['id', 'asc'],
                        ])
                        ->values()
                        ->map(function ($step) {
                            $approverType = strtoupper((string) $step->approver_type);

                            if ($approverType === 'ROLE') {
                                $approverName = $step->role?->kode
                                    ?? $step->approverRole?->kode
                                    ?? $step->role?->kode
                                    ?? $step->approverRole?->kode
                                    ?? null;
                            } elseif ($approverType === 'USER') {
                                $approverName = $step->user?->fullname
                                    ?? $step->user?->name
                                    ?? $step->approverUser?->fullname
                                    ?? $step->approverUser?->name
                                    ?? null;
                            } else {
                                $approverName = null;
                            }

                            return [
                                'id' => $step->id,
                                'public_id' => Crypt::encrypt($step->id),
                                'step_order' => $step->step_order,
                                'sequence' => $step->step_order,
                                'approver_type' => $approverType,
                                'approver_id' => $step->approver_id,
                                'approver_public_id' => Crypt::encrypt($step->approver_id),

                                'approver_name' => $approverName,
                                'approval_role_name' => $approverName,
                                'role_name' => $approverName,

                                'label' => $step->label,
                                'approval_mode' => $step->approval_mode ?? 'ANY',
                                'is_required' => (bool) $step->is_required,
                            ];
                        }),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::query()
                ->with([
                    'steps.role',
                    'steps.user',
                    'creatorDepartment',
                    'permissionModule',
                ])
                ->findOrFail($id);

            $steps = $flow->steps
                ->sortBy([
                    ['step_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->name
                            ?? $step->role?->nama
                            ?? $step->approverRole?->name
                            ?? $step->approverRole?->nama
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),
                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,
                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                        'approver_scope' => $step->approver_scope
                            ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Detail approval flow berhasil dimuat.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps_count' => $steps->count(),
                    'steps' => $steps,

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Approval Flow] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function getDocumentTypeLabel(?string $documentType): string
    {
        return match (strtoupper((string) $documentType)) {
            'PO' => 'Purchase Order (PO)',
            'PR' => 'Purchase Requisition (PR)',
            'Vendor' => 'Master Vendor',
            default => $documentType ?: '-',
        };
    }

    /**
     * Menormalkan document type yang diterima API.
     */
    private function normalizeDocumentType(
        string $documentType,
    ): string {
        $documentType = strtoupper(
            trim($documentType),
        );

        return match ($documentType) {
            'PR' => ApprovalFlow::DOCUMENT_TYPE_PR,
            'PO' => ApprovalFlow::DOCUMENT_TYPE_PO,
            'VENDOR' => 'Vendor',

            default => throw ValidationException::withMessages([
                'document_type' => [
                    'Jenis dokumen approval flow tidak valid.',
                ],
            ]),
        };
    }

    /**
     * Menentukan code permission_modules berdasarkan document type.
     */
    private function getPermissionModuleCode(
        string $documentType,
    ): string {
        return match (strtoupper(trim($documentType))) {
            'PR' => 'purchase_request',
            'PO' => 'purchase_order',
            'VENDOR' => 'vendor',

            default => throw ValidationException::withMessages([
                'permission_module_id' => [
                    'Module untuk jenis dokumen tersebut belum dikonfigurasi.',
                ],
            ]),
        };
    }

    /**
     * Mengambil module yang sesuai dengan jenis dokumen.
     *
     * permission_module_id dari frontend tetap diverifikasi.
     * Frontend tidak dapat mengirim module Vendor untuk dokumen PR.
     */
    private function resolvePermissionModule(
        Request $request,
        string $documentType,
    ): PermissionModule {
        $expectedCode = $this->getPermissionModuleCode(
            $documentType,
        );

        $query = PermissionModule::query()
            ->where('code', $expectedCode)
            ->where('is_active', true);

        /*
     * Selama masa transisi, permission_module_id dibuat
     * opsional. Jika dikirim frontend, ID-nya harus sesuai
     * dengan code yang diharapkan.
     */
        if ($request->filled('permission_module_id')) {
            $query->whereKey(
                (int) $request->input(
                    'permission_module_id',
                ),
            );
        }

        $permissionModule = $query->first();

        if (!$permissionModule) {
            throw ValidationException::withMessages([
                'permission_module_id' => [
                    sprintf(
                        'Module aktif dengan code "%s" tidak ditemukan atau tidak sesuai dengan jenis dokumen.',
                        $expectedCode,
                    ),
                ],
            ]);
        }

        return $permissionModule;
    }

    /**
     * Nilai module_name lama tetap disimpan sementara
     * untuk kompatibilitas code existing.
     *
     * Sumber utama modul tetap permission_module_id.
     */
    private function getLegacyModuleName(
        string $documentType,
        PermissionModule $permissionModule,
    ): string {
        return match (strtoupper(trim($documentType))) {
            /*
         * PR dan PO sebelumnya berada dalam kelompok Procurement.
         */
            'PR', 'PO' => 'Procurement',

            'VENDOR' => 'Master Vendor',

            default => $permissionModule->name,
        };
    }

    /**
     * Format module untuk response API.
     */
    private function getPermissionModuleData(
        ApprovalFlow $flow,
    ): ?array {
        if (!$flow->permissionModule) {
            return null;
        }

        return [
            'id' => $flow->permissionModule->id,
            'code' => $flow->permissionModule->code,
            'name' => $flow->permissionModule->name,
            'is_active' => (bool) $flow
                ->permissionModule
                ->is_active,
        ];
    }

    /**
     * Nama module yang ditampilkan frontend.
     */
    private function getModuleDisplayName(
        ApprovalFlow $flow,
    ): string {
        return $flow->permissionModule?->name
            ?? $flow->module_name
            ?? '-';
    }
}
