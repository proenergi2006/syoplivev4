<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\ApprovalFlow;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use Exception;
use Illuminate\Support\Facades\DB;

class PurchaseRequestApprovalGeneratorService
{
    public function generate(PurchaseRequest $pr): void
    {
        DB::transaction(function () use ($pr) {
            $pr->loadMissing([
                'creator',
                'submitter',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Cegah generate ulang
            |--------------------------------------------------------------------------
            | Approval PR hanya boleh dibuat sekali saat submit.
            |--------------------------------------------------------------------------
            */
            $existingApproval = PurchaseRequestApproval::where('purchase_request_id', $pr->id)
                ->exists();

            if ($existingApproval) {
                throw new Exception('Approval Purchase Request sudah pernah digenerate.');
            }

            $areaType = $this->resolveAreaType($pr);
            $departmentId = $this->resolveCreatorDepartmentId($pr);
            $amount = (float) ($pr->total_amount ?? 0);
            $cabang = $this->resolveCabangValue($pr);

            $flow = $this->findApprovalFlow(
                areaType: $areaType,
                cabang: $cabang,
                creatorDepartmentId: $departmentId,
                amount: $amount
            );

            if (!$flow) {
                throw new Exception(
                    'Approval flow PR belum disetting untuk kombinasi area, cabang, department, dan nominal ini.'
                );
            }

            $steps = $flow->steps()
                ->orderBy('step_order')
                ->orderBy('id')
                ->get();

            if ($steps->isEmpty()) {
                throw new Exception('Approval flow PR belum memiliki step approval.');
            }

            /*
            |--------------------------------------------------------------------------
            | Step pertama menjadi WAITING
            |--------------------------------------------------------------------------
            | Karena step_order bisa punya beberapa approver, maka semua row dengan
            | step_order paling kecil menjadi WAITING.
            |--------------------------------------------------------------------------
            */
            $firstStepOrder = (int) $steps->min('step_order');

            foreach ($steps as $step) {
                PurchaseRequestApproval::create([
                    'purchase_request_id' => $pr->id,
                    'approval_flow_id' => $flow->id,
                    'approval_flow_step_id' => $step->id,

                    'step_order' => (int) $step->step_order,
                    'label' => $step->label,

                    'approver_type' => strtoupper((string) $step->approver_type),
                    'approver_id' => (int) $step->approver_id,
                    'approver_name_snapshot' => null,

                    'approval_mode' => strtoupper((string) ($step->approval_mode ?? PurchaseRequestApproval::APPROVAL_MODE_ANY)),

                    'status' => (int) $step->step_order === $firstStepOrder
                        ? PurchaseRequestApproval::STATUS_WAITING
                        : PurchaseRequestApproval::STATUS_PENDING,
                ]);
            }
        });
    }

    private function findApprovalFlow(
        string $areaType,
        ?string $cabang,
        ?int $creatorDepartmentId,
        float $amount
    ): ?ApprovalFlow {
        /*
        |--------------------------------------------------------------------------
        | Prioritas pencarian flow
        |--------------------------------------------------------------------------
        | 1. Flow paling spesifik cabang + department
        | 2. Flow global cabang null + department
        | 3. Flow global cabang null + department null
        |--------------------------------------------------------------------------
        */
        return ApprovalFlow::query()
            ->where('document_type', ApprovalFlow::DOCUMENT_TYPE_PR)
            ->where('is_active', true)
            ->where('area_type', strtoupper($areaType))
            ->where('min_amount', '<=', $amount)
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_amount')
                    ->orWhere('max_amount', '>=', $amount);
            })
            ->where(function ($q) use ($cabang) {
                $q->whereNull('cabang');

                if (!empty($cabang)) {
                    $q->orWhere('cabang', $cabang);
                }
            })
            ->where(function ($q) use ($creatorDepartmentId) {
                $q->whereNull('creator_department_id');

                if (!empty($creatorDepartmentId)) {
                    $q->orWhere('creator_department_id', $creatorDepartmentId);
                }
            })
            ->with('steps')
            /*
            |--------------------------------------------------------------------------
            | Prioritas:
            | - yang cabang spesifik lebih didahulukan daripada cabang null
            | - yang department spesifik lebih didahulukan daripada department null
            |--------------------------------------------------------------------------
            */
            ->orderByRaw('CASE WHEN cabang IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN creator_department_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('min_amount')
            ->first();
    }

    private function resolveCreatorDepartmentId(PurchaseRequest $pr): ?int
    {
        return $pr->id_department
            ? (int) $pr->id_department
            : null;
    }

    private function resolveCabangValue(PurchaseRequest $pr): ?string
    {
        /*
        |--------------------------------------------------------------------------
        | Sesuaikan dengan data approval_flows.cabang
        |--------------------------------------------------------------------------
        | Kalau nanti approval_flows.cabang kamu simpan ID cabang, return ID.
        | Kalau simpan inisial/nama cabang, return sesuai format tersebut.
        |
        | Saat ini purchase_requests.cabang kamu varchar, jadi kita pakai value PR.
        |--------------------------------------------------------------------------
        */
        $cabang = trim((string) ($pr->cabang ?? ''));

        return $cabang !== '' ? $cabang : null;
    }

    private function resolveAreaType(PurchaseRequest $pr): string
    {
        /*
        |--------------------------------------------------------------------------
        | Idealnya area_type diambil dari master cabang.
        |--------------------------------------------------------------------------
        | Jika table cabang sudah punya area_type:
        | - HO
        | - CABANG
        |
        | Nanti bagian ini bisa diganti:
        | return strtoupper($pr->cabangData?->area_type ?? 'CABANG');
        |--------------------------------------------------------------------------
        */

        $cabang = strtoupper(trim((string) ($pr->cabang ?? '')));

        /*
        |--------------------------------------------------------------------------
        | Fallback sementara
        |--------------------------------------------------------------------------
        | Sesuaikan value HO di data cabang kamu.
        |--------------------------------------------------------------------------
        */
        $hoValues = [
            'HO',
            'HEAD OFFICE',
            'JAKARTA',
            'JKT',
        ];

        if (in_array($cabang, $hoValues, true)) {
            return ApprovalFlow::AREA_HO;
        }

        return ApprovalFlow::AREA_CABANG;
    }
}
