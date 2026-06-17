<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseRequestApprovalGeneratorService
{
    public function generate(
        PurchaseRequest $purchaseRequest,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cegah approval tergenerate dua kali
        |--------------------------------------------------------------------------
        */
        $alreadyExists = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Request sudah pernah dibuat.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hitung nominal PR
        |--------------------------------------------------------------------------
        */
        $totalAmount = $this->calculateTotalAmount(
            $purchaseRequest,
        );

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai Purchase Request harus lebih besar dari 0.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cari flow yang sesuai
        |--------------------------------------------------------------------------
        */
        $approvalFlow = $this->findMatchingFlow(
            $purchaseRequest,
            $totalAmount,
        );

        if (!$approvalFlow) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Purchase Request tidak ditemukan untuk nominal Rp %s.',
                        number_format(
                            $totalAmount,
                            0,
                            ',',
                            '.',
                        ),
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil seluruh step flow
        |--------------------------------------------------------------------------
        */
        $flowSteps = ApprovalFlowStep::query()
            ->where(
                'approval_flow_id',
                $approvalFlow->id,
            )
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        if ($flowSteps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow ditemukan, tetapi belum memiliki approver.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Tentukan step pertama
        |--------------------------------------------------------------------------
        */
        $firstStepOrder = (int) $flowSteps
            ->min('step_order');

        /*
        |--------------------------------------------------------------------------
        | Snapshot seluruh approver ke transaksi PR
        |--------------------------------------------------------------------------
        */
        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            PurchaseRequestApproval::create([
                'purchase_request_id' => $purchaseRequest->id,

                'approval_flow_id' => $approvalFlow->id,

                'approval_flow_step_id' => $flowStep->id,

                'step_order' => $stepOrder,

                'label' => $flowStep->label,

                'approver_type' => strtoupper(
                    trim(
                        (string) $flowStep->approver_type,
                    ),
                ),

                'approver_id' => $flowStep->approver_id,

                'approver_name_snapshot'
                => $this->resolveApproverName(
                    $flowStep,
                ),

                'approval_mode' => strtoupper(
                    trim(
                        (string) (
                            $flowStep->approval_mode
                            ?: 'ANY'
                        ),
                    ),
                ),

                /*
                |--------------------------------------------------------------------------
                | Step pertama aktif
                |--------------------------------------------------------------------------
                | WAITING  = bisa diproses approver sekarang
                | PENDING  = menunggu step sebelumnya selesai
                |--------------------------------------------------------------------------
                */
                'status' => $stepOrder === $firstStepOrder
                    ? PurchaseRequestApproval::STATUS_WAITING
                    : PurchaseRequestApproval::STATUS_PENDING,
            ]);
        }
    }

    private function calculateTotalAmount(
        PurchaseRequest $purchaseRequest,
    ): float {
        $purchaseRequest->loadMissing('items');

        /*
        |--------------------------------------------------------------------------
        | Gunakan total header jika tersedia
        |--------------------------------------------------------------------------
        */
        $headerTotal = (float) (
            $purchaseRequest->total_nilai
            ?? $purchaseRequest->grand_total
            ?? $purchaseRequest->total_amount
            ?? 0
        );

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        /*
        |--------------------------------------------------------------------------
        | Gunakan subtotal item jika tersedia
        |--------------------------------------------------------------------------
        */
        $subtotal = (float) $purchaseRequest
            ->items
            ->sum(function ($item) {
                return (float) (
                    $item->subtotal
                    ?? $item->total
                    ?? 0
                );
            });

        if ($subtotal > 0) {
            return $subtotal;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback qty × harga
        |--------------------------------------------------------------------------
        */
        return (float) $purchaseRequest
            ->items
            ->sum(function ($item) {
                $qty = (float) (
                    $item->qty
                    ?? $item->quantity
                    ?? 0
                );

                $price = (float) (
                    $item->harga
                    ?? $item->harga_satuan
                    ?? $item->unit_price
                    ?? 0
                );

                return $qty * $price;
            });
    }

    private function findMatchingFlow(
        PurchaseRequest $purchaseRequest,
        float $totalAmount,
    ): ?ApprovalFlow {
        /*
    |--------------------------------------------------------------------------
    | Tentukan area berdasarkan cabang requester
    |--------------------------------------------------------------------------
    | cabang ID 1 = HO
    | selain ID 1 = CABANG
    |--------------------------------------------------------------------------
    */
        $areaType = $purchaseRequest->getApprovalAreaType();

        /*
    |--------------------------------------------------------------------------
    | Department pembuat PR
    |--------------------------------------------------------------------------
    */
        $departmentId = $purchaseRequest->id_department;

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Department Purchase Request tidak tersedia untuk mencari approval flow.',
                ],
            ]);
        }

        Log::info('[PR Approval Generator] Matching flow parameters', [
            'purchase_request_id' => $purchaseRequest->id,
            'document_type' => 'PR',
            'cabang_id' => $purchaseRequest->cabang,
            'area_type' => $areaType,
            'creator_department_id' => (int) $departmentId,
            'total_amount' => $totalAmount,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)

            /*
        |--------------------------------------------------------------------------
        | Jenis dokumen
        |--------------------------------------------------------------------------
        */
            ->whereRaw(
                'UPPER(TRIM(document_type)) = ?',
                ['PR'],
            )

            /*
        |--------------------------------------------------------------------------
        | Area
        |--------------------------------------------------------------------------
        | Tidak membandingkan approval_flows.cabang.
        |
        | HO     = Kantor Pusat
        | CABANG = seluruh cabang selain HO
        |--------------------------------------------------------------------------
        */
            ->whereRaw(
                'UPPER(TRIM(area_type)) = ?',
                [$areaType],
            )

            /*
        |--------------------------------------------------------------------------
        | Department pembuat PR
        |--------------------------------------------------------------------------
        */
            ->where(
                'creator_department_id',
                (int) $departmentId,
            )

            /*
        |--------------------------------------------------------------------------
        | Batas minimum nominal
        |--------------------------------------------------------------------------
        */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('min_amount')
                    ->orWhere(
                        'min_amount',
                        '<=',
                        $totalAmount,
                    );
            })

            /*
        |--------------------------------------------------------------------------
        | Batas maksimum nominal
        |--------------------------------------------------------------------------
        | null atau 0 berarti tidak memiliki batas maksimum.
        |--------------------------------------------------------------------------
        */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('max_amount')
                    ->orWhere('max_amount', 0)
                    ->orWhere(
                        'max_amount',
                        '>=',
                        $totalAmount,
                    );
            })

            /*
        |--------------------------------------------------------------------------
        | Jika ada beberapa flow yang cocok
        |--------------------------------------------------------------------------
        | Prioritaskan rentang yang paling spesifik.
        |--------------------------------------------------------------------------
        */
            ->orderByDesc('min_amount')
            ->orderBy('max_amount')
            ->orderByDesc('id')
            ->first();

        Log::info('[PR Approval Generator] Matching flow result', [
            'purchase_request_id' => $purchaseRequest->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'creator_department_id' => $flow?->creator_department_id,
            'min_amount' => $flow?->min_amount,
            'max_amount' => $flow?->max_amount,
        ]);

        return $flow;
    }

    private function resolveApproverName(
        ApprovalFlowStep $flowStep,
    ): ?string {
        $approverType = strtoupper(
            trim(
                (string) $flowStep->approver_type,
            ),
        );

        if ($approverType === 'USER') {
            return User::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        if ($approverType === 'ROLE') {
            return Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('nama');
        }

        return null;
    }
}
