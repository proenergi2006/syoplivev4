<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                ])
                ->when($documentType !== '' && $documentType !== 'ALL' && $documentType !== 'SEMUA', function ($query) use ($documentType) {
                    $query->where('document_type', $documentType);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('module_name', 'ILIKE', "%{$search}%")
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
                        $approverName = $step->role?->name ?? $step->label ?? '-';
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->name ?? $step->label ?? '-';
                    } else {
                        $approverName = $step->label ?? '-';
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
                        'is_required' => (bool) $step->is_required,
                    ];
                })->values();

                $firstStep = $steps->first();

                return [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'module' => $flow->module_name,
                    'module_name' => $flow->module_name,

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

    public function update(Request $request, string $publicId)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],

                'steps' => ['required', 'array', 'min:1'],
                'steps.*.step_order' => ['nullable', 'integer', 'min:1'],
                'steps.*.approver_type' => ['required', 'string', 'in:ROLE,USER'],
                'steps.*.approver_id' => ['required', 'integer'],
                'steps.*.label' => ['nullable', 'string', 'max:255'],
            ]);

            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])
                ->lockForUpdate()
                ->findOrFail($id);

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
        | Validasi duplicate approver
        |--------------------------------------------------------------------------
        | ROLE-15 dan USER-15 dianggap berbeda karena beda approver_type.
        |--------------------------------------------------------------------------
        */
            $usedApprovers = [];

            foreach ($steps as $index => $step) {
                $approverType = strtoupper((string) ($step['approver_type'] ?? ''));
                $approverId = (int) ($step['approver_id'] ?? 0);

                $approverKey = $approverType . '-' . $approverId;

                if (in_array($approverKey, $usedApprovers, true)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Approver pada step ' . ($index + 1) . ' duplikat. Silakan pilih approver yang berbeda.',
                    ], 422);
                }

                $usedApprovers[] = $approverKey;
            }

            /*
        |--------------------------------------------------------------------------
        | Update approval flow header
        |--------------------------------------------------------------------------
        */
            $flow->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_active' => $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : (bool) $flow->is_active,
                'updated_by' => $request->user()->id ?? null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Replace approval flow steps
        |--------------------------------------------------------------------------
        | Step order dibuat ulang berdasarkan urutan payload:
        | 1, 2, 3, dst.
        |
        | is_required selalu true.
        |--------------------------------------------------------------------------
        */
            DB::table('approval_flow_steps')
                ->where('approval_flow_id', $flow->id)
                ->delete();

            foreach ($steps as $index => $step) {
                DB::table('approval_flow_steps')->insert([
                    'approval_flow_id' => $flow->id,
                    'step_order' => $index + 1,
                    'approver_type' => strtoupper((string) $step['approver_type']),
                    'approver_id' => (int) $step['approver_id'],
                    'label' => $step['label'] ?? null,
                    'is_required' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            $flow->load([
                'steps.role',
                'steps.user',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil diperbarui.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),
                    'module_name' => $flow->module_name,
                    'document_type' => $flow->document_type,
                    'name' => $flow->name,
                    'description' => $flow->description,
                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,
                    'is_active' => (bool) $flow->is_active,
                    'steps' => $flow->steps
                        ->sortBy('step_order')
                        ->values()
                        ->map(function ($step) {
                            $approverType = strtoupper((string) $step->approver_type);

                            if ($approverType === 'ROLE') {
                                $approverName = $step->role?->name ?? $step->label ?? '-';
                            } elseif ($approverType === 'USER') {
                                $approverName = $step->user?->name ?? $step->label ?? '-';
                            } else {
                                $approverName = $step->label ?? '-';
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
                                'is_required' => true,
                            ];
                        }),
                ],
            ], 200);
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

    private function getDocumentTypeLabel(?string $documentType): string
    {
        return match (strtoupper((string) $documentType)) {
            'PO' => 'Purchase Order (PO)',
            'PR' => 'Purchase Requisition (PR)',
            'Vendor' => 'Master Vendor',
            default => $documentType ?: '-',
        };
    }
}
