<?php

namespace App\Services\MasterVendor;

use App\Models\MasterVendor;
use App\Models\MasterVendorApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MasterVendorApprovalService
{
    /**
     * Mengambil seluruh user yang menjadi kandidat sebuah approval.
     */
    public function resolveApprovers(
        MasterVendorApproval $approval,
    ): Collection {
        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        if ($approverType === 'USER') {
            return User::query()
                ->whereKey($approval->approver_id)
                ->where('is_active', true)
                ->get();
        }

        if ($approverType === 'ROLE') {
            return User::query()
                ->whereHas('roles', function ($query) use ($approval) {
                    $query->where(
                        'roles.id',
                        $approval->approver_id,
                    );
                })
                ->where('is_active', true)
                ->get();
        }

        return collect();
    }

    /**
     * Mengecek apakah user cocok dengan kandidat approval USER/ROLE.
     */
    public function userMatchesApproval(
        User $user,
        MasterVendorApproval $approval,
    ): bool {
        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        if ($approverType === 'USER') {
            return (int) $approval->approver_id
                === (int) $user->id;
        }

        if ($approverType === 'ROLE') {
            return $user->roles()
                ->where(
                    'roles.id',
                    $approval->approver_id,
                )
                ->exists();
        }

        return false;
    }

    /**
     * Mengambil approval aktif yang boleh diproses user.
     */
    public function getUserCurrentApproval(
        MasterVendor $vendor,
        User $user,
    ): ?MasterVendorApproval {
        $firstWaitingApproval = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (!$firstWaitingApproval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Tidak ada approval aktif untuk Master Vendor ini.',
                ],
            ]);
        }

        $currentStepOrder
            = (int) $firstWaitingApproval->step_order;

        if ($currentStepOrder === null) {
            return null;
        }

        $currentStepApprovals = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', 'WAITING')
            ->orderBy('id')
            ->get();

        return $currentStepApprovals
            ->first(function (
                MasterVendorApproval $approval,
            ) use (
                $user,
            ) {
                return $this->userMatchesApproval(
                    $user,
                    $approval,
                );
            });
    }

    /**
     * Memproses approval Master Vendor.
     *
     * Return:
     * [
     *     'approval' => MasterVendorApproval,
     *     'step_completed' => bool,
     *     'has_next_step' => bool,
     *     'is_final_approved' => bool,
     *     'current_step_order' => int|null,
     *     'next_step_order' => int|null,
     * ]
     */

    public function approve(
        MasterVendor $vendor,
        User $user,
        ?string $notes = null,
        ?string $kodeVendor = null,
    ): array {
        /*
    |--------------------------------------------------------------------------
    | Validasi status header Vendor
    |--------------------------------------------------------------------------
    */
        $vendorStatus = strtoupper(
            trim((string) $vendor->status_approval),
        );

        if ($vendorStatus !== 'PENDING REVIEW') {
            throw ValidationException::withMessages([
                'status_approval' => [
                    'Vendor hanya dapat diapprove jika status masih PENDING REVIEW.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Cari dan lock approval WAITING paling awal
    |--------------------------------------------------------------------------
    |
    | Tidak menggunakan MIN() bersama FOR UPDATE karena PostgreSQL tidak
    | mengizinkan locking pada aggregate function.
    |--------------------------------------------------------------------------
    */
        $firstWaitingApproval = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (!$firstWaitingApproval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Tidak ada approval aktif untuk Master Vendor ini.',
                ],
            ]);
        }

        $currentStepOrder = (int) $firstWaitingApproval->step_order;

        /*
    |--------------------------------------------------------------------------
    | Lock seluruh approval pada step aktif
    |--------------------------------------------------------------------------
    |
    | APPROVED tetap diambil untuk kebutuhan perhitungan mode ALL.
    |--------------------------------------------------------------------------
    */
        $currentStepApprovals = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('step_order', $currentStepOrder)
            ->whereIn('status', [
                'WAITING',
                'APPROVED',
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Cari row WAITING yang sesuai dengan user login
    |--------------------------------------------------------------------------
    */
        $currentApproval = $currentStepApprovals
            ->filter(function (
                MasterVendorApproval $approval,
            ) {
                return strtoupper(
                    trim((string) $approval->status),
                ) === 'WAITING';
            })
            ->first(function (
                MasterVendorApproval $approval,
            ) use (
                $user,
            ) {
                return $this->userMatchesApproval(
                    $user,
                    $approval,
                );
            });

        if (!$currentApproval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Master Vendor ini.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Approval mode
    |--------------------------------------------------------------------------
    */
        $approvalMode = strtoupper(
            trim(
                (string) (
                    $currentApproval->approval_mode
                    ?? 'ANY'
                ),
            ),
        );

        if (!in_array(
            $approvalMode,
            [
                'ANY',
                'ALL',
            ],
            true,
        )) {
            throw ValidationException::withMessages([
                'approval_mode' => [
                    'Approval mode Master Vendor tidak valid.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Tentukan apakah aksi ini akan menyelesaikan current step
    |--------------------------------------------------------------------------
    |
    | ANY:
    | - satu kandidat approve langsung menyelesaikan step.
    |
    | ALL:
    | - step selesai jika user ini merupakan kandidat WAITING terakhir.
    |--------------------------------------------------------------------------
    */
        $waitingCountBeforeApprove = $currentStepApprovals
            ->filter(function (
                MasterVendorApproval $approval,
            ) {
                return strtoupper(
                    trim((string) $approval->status),
                ) === 'WAITING';
            })
            ->count();

        $willCompleteCurrentStep = match ($approvalMode) {
            'ANY' => true,

            'ALL' => $waitingCountBeforeApprove === 1,

            default => false,
        };

        /*
    |--------------------------------------------------------------------------
    | Cari apakah terdapat step setelah current step
    |--------------------------------------------------------------------------
    |
    | Pada tahap sebelum final, kode Vendor belum diwajibkan.
    |--------------------------------------------------------------------------
    */
        $hasLaterStep = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where(
                'step_order',
                '>',
                $currentStepOrder,
            )
            ->whereIn('status', [
                'PENDING',
                'WAITING',
            ])
            ->exists();

        $willBeFinalApproved = (
            $willCompleteCurrentStep
            && !$hasLaterStep
        );

        /*
    |--------------------------------------------------------------------------
    | Validasi Kode Vendor pada final approval
    |--------------------------------------------------------------------------
    */
        $normalizedVendorCode = null;

        if ($willBeFinalApproved) {
            $normalizedVendorCode = strtoupper(
                trim(
                    strip_tags(
                        (string) $kodeVendor,
                    ),
                ),
            );

            if ($normalizedVendorCode === '') {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor wajib diisi pada approval final.',
                    ],
                ]);
            }

            if (str_starts_with(
                $normalizedVendorCode,
                'TEMP-',
            )) {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor final tidak boleh menggunakan kode TEMP.',
                    ],
                ]);
            }

            if (mb_strlen($normalizedVendorCode) > 100) {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor maksimal 100 karakter.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi kode unik tanpa membedakan kapital
        |--------------------------------------------------------------------------
        */
            $vendorCodeExists = MasterVendor::query()
                ->where('id', '!=', $vendor->id)
                ->whereRaw(
                    'UPPER(TRIM(kode_vendor)) = ?',
                    [$normalizedVendorCode],
                )
                ->exists();

            if ($vendorCodeExists) {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor tersebut sudah digunakan oleh Vendor lain.',
                    ],
                ]);
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Bersihkan notes approval
    |--------------------------------------------------------------------------
    */
        $cleanNotes = $notes !== null
            ? trim((string) $notes)
            : null;

        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        /*
    |--------------------------------------------------------------------------
    | Approve row milik actor
    |--------------------------------------------------------------------------
    */
        $currentApproval->status = 'APPROVED';

        $currentApproval->approver_name_snapshot
            = $this->userDisplayName($user);

        $currentApproval->notes = $cleanNotes;
        $currentApproval->approved_at = now();
        $currentApproval->rejected_at = null;
        $currentApproval->cancelled_at = null;
        $currentApproval->save();

        $stepCompleted = false;

        /*
    |--------------------------------------------------------------------------
    | Mode ANY
    |--------------------------------------------------------------------------
    |
    | Satu kandidat approve:
    | - actor menjadi APPROVED;
    | - kandidat WAITING lain menjadi SKIPPED;
    | - step langsung selesai.
    |--------------------------------------------------------------------------
    */
        if ($approvalMode === 'ANY') {
            MasterVendorApproval::query()
                ->where('vendor_id', $vendor->id)
                ->where('step_order', $currentStepOrder)
                ->where('status', 'WAITING')
                ->where('id', '!=', $currentApproval->id)
                ->update([
                    'status' => 'SKIPPED',
                    'cancelled_at' => now(),
                    'notes'
                    => 'Skipped karena approval mode ANY telah terpenuhi.',
                    'updated_at' => now(),
                ]);

            $stepCompleted = true;
        }

        /*
    |--------------------------------------------------------------------------
    | Mode ALL
    |--------------------------------------------------------------------------
    |
    | Setelah actor disetujui, cek apakah masih ada kandidat WAITING.
    |--------------------------------------------------------------------------
    */
        if ($approvalMode === 'ALL') {
            $hasWaitingCurrentStep = MasterVendorApproval::query()
                ->where('vendor_id', $vendor->id)
                ->where('step_order', $currentStepOrder)
                ->where('status', 'WAITING')
                ->exists();

            $stepCompleted = !$hasWaitingCurrentStep;
        }

        /*
    |--------------------------------------------------------------------------
    | Mode ALL masih menunggu approver lain
    |--------------------------------------------------------------------------
    */
        if (!$stepCompleted) {
            $currentApproval->refresh();

            return [
                'approval' => $currentApproval,

                'step_completed' => false,

                'has_next_step' => false,

                'is_final_approved' => false,

                'requires_vendor_code' => false,

                'current_step_order'
                => $currentStepOrder,

                'next_step_order'
                => null,

                'kode_vendor'
                => $vendor->kode_vendor,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Cari step berikutnya
    |--------------------------------------------------------------------------
    |
    | Mengambil row pertama supaya pola query lebih aman dan konsisten.
    |--------------------------------------------------------------------------
    */
        $nextPendingApproval = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'PENDING')
            ->where(
                'step_order',
                '>',
                $currentStepOrder,
            )
            ->orderBy('step_order')
            ->orderBy('id')
            ->first();

        $nextStepOrder = $nextPendingApproval
            ? (int) $nextPendingApproval->step_order
            : null;

        /*
    |--------------------------------------------------------------------------
    | Aktifkan seluruh kandidat pada step berikutnya
    |--------------------------------------------------------------------------
    */
        if ($nextStepOrder !== null) {
            MasterVendorApproval::query()
                ->where('vendor_id', $vendor->id)
                ->where('step_order', $nextStepOrder)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'WAITING',
                    'updated_at' => now(),
                ]);

            /*
        | Selama masih ada step berikutnya, status header tetap
        | PENDING REVIEW dan kode Vendor masih tetap TEMP.
        */
            $vendor->status_approval = 'PENDING REVIEW';
            $vendor->save();

            $currentApproval->refresh();
            $vendor->refresh();

            return [
                'approval' => $currentApproval,

                'step_completed' => true,

                'has_next_step' => true,

                'is_final_approved' => false,

                'requires_vendor_code' => false,

                'current_step_order'
                => $currentStepOrder,

                'next_step_order'
                => $nextStepOrder,

                'kode_vendor'
                => $vendor->kode_vendor,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Safety check approval yang masih tersisa
    |--------------------------------------------------------------------------
    */
        $hasRemainingApproval = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [
                'PENDING',
                'WAITING',
            ])
            ->exists();

        /*
    |--------------------------------------------------------------------------
    | Final approval
    |--------------------------------------------------------------------------
    |
    | Kode Vendor final dan status APPROVED disimpan dalam transaksi yang sama.
    |--------------------------------------------------------------------------
    */
        if (!$hasRemainingApproval) {
            /*
        | Safety validation. Secara normal kode sudah divalidasi sebelum
        | current approval diubah.
        */
            if ($normalizedVendorCode === null) {
                $normalizedVendorCode = strtoupper(
                    trim(
                        strip_tags(
                            (string) $kodeVendor,
                        ),
                    ),
                );
            }

            if ($normalizedVendorCode === '') {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor wajib diisi pada approval final.',
                    ],
                ]);
            }

            if (str_starts_with(
                $normalizedVendorCode,
                'TEMP-',
            )) {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor final tidak boleh menggunakan kode TEMP.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Cek ulang uniqueness sebelum simpan final
        |--------------------------------------------------------------------------
        |
        | Ini menjaga apabila kondisi data berubah selama proses approval.
        |--------------------------------------------------------------------------
        */
            $vendorCodeExists = MasterVendor::query()
                ->where('id', '!=', $vendor->id)
                ->whereRaw(
                    'UPPER(TRIM(kode_vendor)) = ?',
                    [$normalizedVendorCode],
                )
                ->exists();

            if ($vendorCodeExists) {
                throw ValidationException::withMessages([
                    'kode_vendor' => [
                        'Kode Vendor tersebut sudah digunakan oleh Vendor lain.',
                    ],
                ]);
            }

            $vendor->kode_vendor = $normalizedVendorCode;
            $vendor->status_approval = 'APPROVED';
            $vendor->save();

            $currentApproval->refresh();
            $vendor->refresh();

            return [
                'approval' => $currentApproval,

                'step_completed' => true,

                'has_next_step' => false,

                'is_final_approved' => true,

                'requires_vendor_code' => true,

                'current_step_order'
                => $currentStepOrder,

                'next_step_order'
                => null,

                'kode_vendor'
                => $vendor->kode_vendor,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Safety fallback
    |--------------------------------------------------------------------------
    |
    | Kondisi ini seharusnya tidak terjadi apabila konfigurasi flow valid.
    |--------------------------------------------------------------------------
    */
        $currentApproval->refresh();
        $vendor->refresh();

        return [
            'approval' => $currentApproval,

            'step_completed' => true,

            'has_next_step' => false,

            'is_final_approved' => false,

            'requires_vendor_code' => false,

            'current_step_order'
            => $currentStepOrder,

            'next_step_order'
            => null,

            'kode_vendor'
            => $vendor->kode_vendor,
        ];
    }

    /**
     * Memproses reject Master Vendor.
     */
    public function reject(
        MasterVendor $vendor,
        User $user,
        string $notes,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Validasi status header vendor
        |--------------------------------------------------------------------------
        */
        if (
            strtoupper(
                trim((string) $vendor->status_approval),
            ) !== 'PENDING REVIEW'
        ) {
            throw ValidationException::withMessages([
                'status_approval' => [
                    'Vendor hanya dapat direject jika status masih PENDING REVIEW.',
                ],
            ]);
        }

        if (trim($notes) === '') {
            throw ValidationException::withMessages([
                'notes' => [
                    'Catatan penolakan wajib diisi.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cari step aktif
        |--------------------------------------------------------------------------
        */
        $firstWaitingApproval = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (!$firstWaitingApproval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Tidak ada approval aktif untuk Master Vendor ini.',
                ],
            ]);
        }

        $currentStepOrder = (int) $firstWaitingApproval->step_order;

        $currentStepApprovals = MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', 'WAITING')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Cari row approval yang sesuai user login
        |--------------------------------------------------------------------------
        */
        $currentApproval = $currentStepApprovals
            ->first(function (
                MasterVendorApproval $approval,
            ) use (
                $user,
            ) {
                return $this->userMatchesApproval(
                    $user,
                    $approval,
                );
            });

        if (!$currentApproval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Master Vendor ini.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Reject row actor
        |--------------------------------------------------------------------------
        */
        $currentApproval->status = 'REJECTED';

        $currentApproval->approver_name_snapshot
            = $this->userDisplayName($user);

        $currentApproval->notes = $notes;
        $currentApproval->rejected_at = now();
        $currentApproval->approved_at = null;
        $currentApproval->cancelled_at = null;
        $currentApproval->save();

        /*
        |--------------------------------------------------------------------------
        | Cancel seluruh approval lain yang belum selesai
        |--------------------------------------------------------------------------
        */
        MasterVendorApproval::query()
            ->where('vendor_id', $vendor->id)
            ->where('id', '!=', $currentApproval->id)
            ->whereIn('status', [
                'PENDING',
                'WAITING',
            ])
            ->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'notes'
                => 'Cancelled karena Master Vendor direject.',
                'updated_at' => now(),
            ]);

        /*
        |--------------------------------------------------------------------------
        | Update header vendor
        |--------------------------------------------------------------------------
        */
        $vendor->status_approval = 'REJECTED';
        $vendor->save();

        $currentApproval->refresh();

        return [
            'approval' => $currentApproval,
            'step_completed' => true,
            'has_next_step' => false,
            'is_final_approved' => false,
            'current_step_order' => (int) $currentStepOrder,
            'next_step_order' => null,
        ];
    }

    private function userDisplayName(
        User $user,
    ): string {
        return (string) (
            $user->name
            ?? $user->fullname
            ?? $user->email
            ?? 'Approver'
        );
    }
}
