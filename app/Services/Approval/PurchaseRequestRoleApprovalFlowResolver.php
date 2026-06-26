<?php

namespace App\Services\Approval;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowRule;
use Illuminate\Validation\ValidationException;

class PurchaseRequestRoleApprovalFlowResolver
{
    /**
     * Mencari rule khusus berdasarkan role requester dan
     * konteks Purchase Request.
     *
     * Method ini hanya mencari rule khusus.
     * Tidak menjalankan fallback ke flow lama.
     */
    public function resolveRule(
        string $moduleCode,
        int $requesterRoleId,
        ?int $cabangId,
        ?int $departmentId,
        int|float|string $amount,
    ): ?ApprovalFlowRule {
        $normalizedAmount = $this->normalizeAmount(
            $amount,
        );

        return ApprovalFlowRule::query()
            ->with([
                'approvalFlow.steps' => function ($query) {
                    $query->orderBy('step_order');
                },
            ])

            /*
             * Role pembuat PR wajib sama.
             */
            ->where(
                'requester_role_id',
                $requesterRoleId,
            )

            ->where(
                'approval_flow_rules.is_active',
                true,
            )

            /*
             * Flow tujuan harus aktif dan sesuai modul PR.
             */
            ->whereHas(
                'approvalFlow',
                function ($query) use ($moduleCode) {
                    $query
                        ->where('approval_flows.is_active', true)
                        ->whereHas(
                            'permissionModule',
                            function ($moduleQuery) use ($moduleCode) {
                                $moduleQuery
                                    ->where(
                                        'permission_modules.code',
                                        $moduleCode,
                                    )
                                    ->where(
                                        'permission_modules.is_active',
                                        true,
                                    );
                            },
                        );
                },
            )

            /*
             * Rule cabang:
             *
             * null      = berlaku untuk semua cabang
             * memiliki ID = hanya cabang tersebut
             */
            ->where(
                function ($query) use ($cabangId) {
                    $query->whereNull('cabang_id');

                    if ($cabangId !== null) {
                        $query->orWhere(
                            'cabang_id',
                            $cabangId,
                        );
                    }
                },
            )

            /*
             * Rule departemen:
             *
             * null      = berlaku untuk semua departemen
             * memiliki ID = hanya departemen tersebut
             */
            ->where(
                function ($query) use ($departmentId) {
                    $query->whereNull(
                        'department_id',
                    );

                    if ($departmentId !== null) {
                        $query->orWhere(
                            'department_id',
                            $departmentId,
                        );
                    }
                },
            )

            /*
             * Nominal minimum.
             */
            ->where(
                function ($query) use (
                    $normalizedAmount,
                ) {
                    $query
                        ->whereNull('min_amount')
                        ->orWhere(
                            'min_amount',
                            '<=',
                            $normalizedAmount,
                        );
                },
            )

            /*
             * Nominal maksimum.
             */
            ->where(
                function ($query) use (
                    $normalizedAmount,
                ) {
                    $query
                        ->whereNull('max_amount')
                        ->orWhere(
                            'max_amount',
                            '>=',
                            $normalizedAmount,
                        );
                },
            )

            /*
             * Priority paling besar dipilih lebih dahulu.
             */
            ->orderByDesc('priority')

            /*
             * Jika priority sama, rule paling spesifik
             * didahulukan.
             *
             * Contoh:
             * role + cabang + department
             * lebih diprioritaskan daripada role saja.
             */
            ->orderByRaw(
                '
                CASE
                    WHEN cabang_id IS NULL THEN 0
                    ELSE 1
                END DESC
                ',
            )

            ->orderByRaw(
                '
                CASE
                    WHEN department_id IS NULL THEN 0
                    ELSE 1
                END DESC
                ',
            )

            /*
             * Rule dengan batas nominal didahulukan
             * daripada rule nominal umum.
             */
            ->orderByRaw(
                '
                (
                    CASE
                        WHEN min_amount IS NULL THEN 0
                        ELSE 1
                    END
                    +
                    CASE
                        WHEN max_amount IS NULL THEN 0
                        ELSE 1
                    END
                ) DESC
                ',
            )

            /*
             * Agar hasil tetap deterministik jika semua
             * kondisi di atas sama.
             */
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Langsung mengembalikan ApprovalFlow yang cocok.
     */
    public function resolveFlow(
        string $moduleCode,
        int $requesterRoleId,
        ?int $cabangId,
        ?int $departmentId,
        int|float|string $amount,
    ): ?ApprovalFlow {
        $rule = $this->resolveRule(
            moduleCode: $moduleCode,
            requesterRoleId: $requesterRoleId,
            cabangId: $cabangId,
            departmentId: $departmentId,
            amount: $amount,
        );

        if (!$rule) {
            return null;
        }

        $approvalFlow = $rule->approvalFlow;

        if (!$approvalFlow) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow pada rule tidak ditemukan.',
                ],
            ]);
        }

        /*
         * Jangan izinkan rule aktif menunjuk flow yang
         * tidak mempunyai step approver.
         */
        if ($approvalFlow->steps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow "%s" belum memiliki step approver.',
                        $approvalFlow->name,
                    ),
                ],
            ]);
        }

        return $approvalFlow;
    }

    /**
     * Memastikan nominal valid sebelum dipakai pada query.
     */
    private function normalizeAmount(
        int|float|string $amount,
    ): string {
        $normalizedAmount = trim(
            (string) $amount,
        );

        if (
            $normalizedAmount === ''
            || !is_numeric($normalizedAmount)
        ) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Nilai Purchase Request tidak valid.',
                ],
            ]);
        }

        if ((float) $normalizedAmount < 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Nilai Purchase Request tidak boleh negatif.',
                ],
            ]);
        }

        return $normalizedAmount;
    }
}
