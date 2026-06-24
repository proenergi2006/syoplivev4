<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseOrderApprovalGeneratorService
{
    /**
     * Generate snapshot approval Purchase Order.
     *
     * Semua approver pada step pertama akan menjadi WAITING.
     * Semua approver pada step berikutnya akan menjadi PENDING.
     */
    public function generate(
        PurchaseOrder $purchaseOrder,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cegah approval tergenerate dua kali
        |--------------------------------------------------------------------------
        */
        $alreadyExists = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Order sudah pernah dibuat.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hitung nominal Purchase Order
        |--------------------------------------------------------------------------
        */
        $totalAmount = $this->calculateTotalAmount(
            $purchaseOrder,
        );

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai Purchase Order harus lebih besar dari 0.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cari approval flow yang sesuai
        |--------------------------------------------------------------------------
        */
        $approvalFlow = $this->findMatchingFlow(
            $purchaseOrder,
            $totalAmount,
        );

        if (!$approvalFlow) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Purchase Order tidak ditemukan untuk nominal Rp %s.',
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
        | Ambil seluruh step dari master approval flow
        |--------------------------------------------------------------------------
        |
        | Jangan memakai unique(step_order) atau first().
        | Satu step boleh memiliki beberapa kandidat approver.
        |
        | Contoh:
        | step 1 - ROLE Supervisor GA - ANY
        | step 1 - USER Chris        - ANY
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
        | Snapshot seluruh approver ke transaksi Purchase Order
        |--------------------------------------------------------------------------
        */
        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            $approverType = strtoupper(
                trim(
                    (string) $flowStep->approver_type,
                ),
            );

            $approvalMode = strtoupper(
                trim(
                    (string) (
                        $flowStep->approval_mode
                        ?: PurchaseOrderApproval::MODE_ANY
                    ),
                ),
            );

            /*
            |--------------------------------------------------------------------------
            | Validasi approver type
            |--------------------------------------------------------------------------
            */
            if (!in_array(
                $approverType,
                [
                    PurchaseOrderApproval::APPROVER_TYPE_USER,
                    PurchaseOrderApproval::APPROVER_TYPE_ROLE,
                ],
                true,
            )) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Tipe approver "%s" pada step %s tidak valid.',
                            $approverType,
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validasi approval mode
            |--------------------------------------------------------------------------
            */
            if (!in_array(
                $approvalMode,
                [
                    PurchaseOrderApproval::MODE_ANY,
                    PurchaseOrderApproval::MODE_ALL,
                ],
                true,
            )) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approval mode "%s" pada step %s tidak valid.',
                            $approvalMode,
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validasi approver ID
            |--------------------------------------------------------------------------
            */
            if (empty($flowStep->approver_id)) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approver pada step %s belum ditentukan.',
                            $stepOrder,
                        ),
                    ],
                ]);
            }

            PurchaseOrderApproval::create([
                'purchase_order_id' => $purchaseOrder->id,

                /*
                |--------------------------------------------------------------------------
                | Referensi snapshot master approval flow
                |--------------------------------------------------------------------------
                */
                'approval_flow_id' => $approvalFlow->id,

                'approval_flow_step_id' => $flowStep->id,

                /*
                |--------------------------------------------------------------------------
                | Informasi step
                |--------------------------------------------------------------------------
                */
                'step_order' => $stepOrder,

                'label' => $flowStep->label,

                /*
                |--------------------------------------------------------------------------
                | Kandidat approver
                |--------------------------------------------------------------------------
                */
                'approver_type' => $approverType,

                'approver_id' => $flowStep->approver_id,

                'approver_name_snapshot'
                => $this->resolveApproverName(
                    $flowStep,
                ),

                /*
                |--------------------------------------------------------------------------
                | ANY atau ALL
                |--------------------------------------------------------------------------
                */
                'approval_mode' => $approvalMode,

                /*
                |--------------------------------------------------------------------------
                | Status awal
                |--------------------------------------------------------------------------
                |
                | Seluruh kandidat pada step pertama = WAITING.
                | Seluruh kandidat pada step berikutnya = PENDING.
                |--------------------------------------------------------------------------
                */
                'status' => $stepOrder === $firstStepOrder
                    ? PurchaseOrderApproval::STATUS_WAITING
                    : PurchaseOrderApproval::STATUS_PENDING,
            ]);
        }

        Log::info(
            '[PO Approval Generator] Approval berhasil dibuat',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'nomor_po' => $purchaseOrder->nomor_po,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_name' => $approvalFlow->name,
                'total_amount' => $totalAmount,
                'first_step_order' => $firstStepOrder,
                'approval_rows' => $flowSteps->count(),
            ],
        );
    }

    /**
     * Hitung total Purchase Order.
     */
    private function calculateTotalAmount(
        PurchaseOrder $purchaseOrder,
    ): float {
        $purchaseOrder->loadMissing('items');

        /*
        |--------------------------------------------------------------------------
        | Prioritaskan total pada header Purchase Order
        |--------------------------------------------------------------------------
        */
        $headerTotal = (float) (
            $purchaseOrder->total_nilai
            ?? $purchaseOrder->grand_total
            ?? $purchaseOrder->total_amount
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
        $subtotal = (float) $purchaseOrder
            ->items
            ->sum(function ($item) {
                return (float) (
                    $item->subtotal
                    ?? $item->total
                    ?? $item->total_nilai
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
        return (float) $purchaseOrder
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

    /**
     * Cari approval flow yang sesuai dengan PO.
     */
    private function findMatchingFlow(
        PurchaseOrder $purchaseOrder,
        float $totalAmount,
    ): ?ApprovalFlow {
        Log::info(
            '[PO Approval Generator] Matching flow parameters',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'nomor_po' => $purchaseOrder->nomor_po,
                'document_type' => 'PO',

                /*
            |--------------------------------------------------------------------------
            | Hanya sebagai informasi transaksi
            |--------------------------------------------------------------------------
            | Tidak digunakan untuk menentukan approval flow PO.
            |--------------------------------------------------------------------------
            */
                'purchase_order_department_id'
                => $purchaseOrder->id_department,

                'purchase_order_cabang_id'
                => $purchaseOrder->cabang,

                'total_amount' => $totalAmount,
            ],
        );

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
                ['PO'],
            )

            /*
        |--------------------------------------------------------------------------
        | Batas minimum nominal
        |--------------------------------------------------------------------------
        | NULL berarti berlaku mulai dari nilai terendah.
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
        | NULL atau 0 berarti tidak memiliki batas maksimum.
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
        | Prioritaskan flow nominal paling spesifik
        |--------------------------------------------------------------------------
        |
        | Contoh nominal Rp20 juta:
        | - Flow Semua Nilai ikut cocok
        | - Flow Rp10.000.001–Rp50.000.000 ikut cocok
        |
        | Karena min_amount flow Rp10 juta lebih besar,
        | flow tersebut dipilih.
        |--------------------------------------------------------------------------
        */
            ->orderByDesc(
                DB::raw('COALESCE(min_amount, 0)'),
            )

            /*
        |--------------------------------------------------------------------------
        | Flow dengan max tertentu lebih spesifik daripada tanpa batas
        |--------------------------------------------------------------------------
        */
            ->orderByRaw(
                '
            CASE
                WHEN max_amount IS NULL OR max_amount = 0
                THEN 1
                ELSE 0
            END
            ',
            )
            ->orderBy('max_amount')
            ->orderByDesc('id')
            ->first();

        Log::info(
            '[PO Approval Generator] Matching flow result',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'approval_flow_id' => $flow?->id,
                'approval_flow_name' => $flow?->name,
                'min_amount' => $flow?->min_amount,
                'max_amount' => $flow?->max_amount,
            ],
        );

        return $flow;
    }

    /**
     * Tentukan area approval PO.
     */
    private function resolveApprovalAreaType(
        PurchaseOrder $purchaseOrder,
    ): string {
        if (
            method_exists(
                $purchaseOrder,
                'getApprovalAreaType',
            )
        ) {
            $areaType = strtoupper(
                trim(
                    (string) $purchaseOrder
                        ->getApprovalAreaType(),
                ),
            );

            if (in_array(
                $areaType,
                ['HO', 'CABANG'],
                true,
            )) {
                return $areaType;
            }
        }

        $cabangId = $this->resolveCabangId(
            $purchaseOrder,
        );

        return (int) $cabangId === 1
            ? 'HO'
            : 'CABANG';
    }

    /**
     * Ambil ID cabang dengan beberapa kemungkinan nama kolom.
     */
    private function resolveCabangId(
        PurchaseOrder $purchaseOrder,
    ): ?int {
        $cabangId = $purchaseOrder->cabang
            ?? $purchaseOrder->id_cabang
            ?? $purchaseOrder->cabang_id
            ?? null;

        return $cabangId !== null
            ? (int) $cabangId
            : null;
    }

    /**
     * Ambil ID department dengan beberapa kemungkinan nama kolom.
     */
    private function resolveDepartmentId(
        PurchaseOrder $purchaseOrder,
    ): ?int {
        $departmentId = $purchaseOrder->id_department
            ?? $purchaseOrder->department_id
            ?? $purchaseOrder->id_departemen
            ?? null;

        return $departmentId !== null
            ? (int) $departmentId
            : null;
    }

    /**
     * Snapshot nama approver.
     */
    private function resolveApproverName(
        ApprovalFlowStep $flowStep,
    ): ?string {
        $approverType = strtoupper(
            trim(
                (string) $flowStep->approver_type,
            ),
        );

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_ROLE
        ) {
            return Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('nama');
        }

        return null;
    }
}
