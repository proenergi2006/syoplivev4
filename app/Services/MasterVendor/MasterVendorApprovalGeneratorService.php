<?php

namespace App\Services\MasterVendor;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\MasterVendor;
use App\Models\MasterVendorApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MasterVendorApprovalGeneratorService
{
    /**
     * Membuat snapshot approval Master Vendor.
     *
     * Step pertama:
     * - seluruh kandidat = WAITING
     *
     * Step berikutnya:
     * - seluruh kandidat = PENDING
     */
    public function generate(
        MasterVendor $vendor,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cari flow aktif Master Vendor
        |--------------------------------------------------------------------------
        |
        | Master Vendor tidak memakai nominal, department, maupun cabang.
        |--------------------------------------------------------------------------
        */
        $flow = ApprovalFlow::query()
            ->where('is_active', true)
            ->whereRaw(
                "REPLACE(UPPER(TRIM(module_name)), ' ', '_') = ?",
                ['MASTER_VENDOR'],
            )
            ->with([
                'steps' => function ($query) {
                    $query
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])
            ->orderByDesc('id')
            ->first();

        if (!$flow) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow Master Vendor belum dikonfigurasi.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil seluruh kandidat approval
        |--------------------------------------------------------------------------
        |
        | Jangan memakai unique(step_order), karena satu step dapat memiliki:
        | - ROLE Finance
        | - ROLE Manager Finance
        |
        | Dengan mode ANY.
        |--------------------------------------------------------------------------
        */
        $steps = ApprovalFlowStep::query()
            ->where('approval_flow_id', $flow->id)
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        if ($steps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow Master Vendor belum memiliki approver.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Mempertahankan perilaku submit lama
        |--------------------------------------------------------------------------
        |
        | Approval sebelumnya dihapus sebelum generate ulang.
        |--------------------------------------------------------------------------
        */
        MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->delete();

        $firstStepOrder = (int) $steps->min('step_order');

        foreach ($steps as $step) {
            $stepOrder = (int) $step->step_order;

            $approverType = strtoupper(
                trim((string) $step->approver_type),
            );

            $approvalMode = strtoupper(
                trim(
                    (string) (
                        $step->approval_mode
                        ?: MasterVendorApproval::MODE_ANY
                    ),
                ),
            );

            if (!in_array(
                $approverType,
                [
                    MasterVendorApproval::APPROVER_TYPE_USER,
                    MasterVendorApproval::APPROVER_TYPE_ROLE,
                ],
                true,
            )) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Tipe approver "%s" pada step %d tidak valid.',
                            $approverType,
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            if (!in_array(
                $approvalMode,
                [
                    MasterVendorApproval::MODE_ANY,
                    MasterVendorApproval::MODE_ALL,
                ],
                true,
            )) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approval mode "%s" pada step %d tidak valid.',
                            $approvalMode,
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            if (empty($step->approver_id)) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approver pada step %d belum ditentukan.',
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            MasterVendorApproval::create([
                'vendor_id' => $vendor->id,

                'approval_flow_id' => $flow->id,

                'approval_flow_step_id' => $step->id,

                'step_order' => $stepOrder,

                'approver_type' => $approverType,

                'approver_id' => (int) $step->approver_id,

                /*
                |--------------------------------------------------------------------------
                | Snapshot nama kandidat
                |--------------------------------------------------------------------------
                |
                | Nanti saat approve/reject akan ditimpa nama user yang benar-benar
                | memproses.
                |--------------------------------------------------------------------------
                */
                'approver_name_snapshot'
                => $this->resolveApproverName($step),

                'approval_mode' => $approvalMode,

                'label' => $step->label,

                'status' => $stepOrder === $firstStepOrder
                    ? MasterVendorApproval::STATUS_WAITING
                    : MasterVendorApproval::STATUS_PENDING,
            ]);
        }

        Log::info(
            '[Master Vendor Approval Generator] Approval berhasil dibuat',
            [
                'vendor_id' => $vendor->id,
                'nama_vendor' => $vendor->nama_vendor,
                'approval_flow_id' => $flow->id,
                'approval_flow_name' => $flow->name,
                'first_step_order' => $firstStepOrder,
                'approval_rows' => $steps->count(),
            ],
        );
    }

    private function resolveApproverName(
        ApprovalFlowStep $step,
    ): ?string {
        $approverType = strtoupper(
            trim((string) $step->approver_type),
        );

        if (
            $approverType
            === MasterVendorApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($step->approver_id)
                ->value('name');
        }

        if (
            $approverType
            === MasterVendorApproval::APPROVER_TYPE_ROLE
        ) {
            return Role::query()
                ->whereKey($step->approver_id)
                ->value('nama');
        }

        return null;
    }
}
