<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseOrderApprovalService
{
    /*
    |--------------------------------------------------------------------------
    | Mengambil step aktif saat ini
    |--------------------------------------------------------------------------
    */
    public function getCurrentStepOrder(
        PurchaseOrder $purchaseOrder,
    ): ?int {
        $stepOrder = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_WAITING,
            )
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mengambil seluruh kandidat WAITING pada step aktif
    |--------------------------------------------------------------------------
    */
    public function getCurrentWaitingApprovals(
        PurchaseOrder $purchaseOrder,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder(
            $purchaseOrder,
        );

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->where(
                'step_order',
                $currentStepOrder,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_WAITING,
            )
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Compatibility method lama
    |--------------------------------------------------------------------------
    |
    | Jangan gunakan method ini untuk controller approve/reject baru karena
    | hanya mengambil row pertama.
    |--------------------------------------------------------------------------
    */
    public function getCurrentPendingApproval(
        PurchaseOrder $purchaseOrder,
    ): ?PurchaseOrderApproval {
        return $this
            ->getCurrentWaitingApprovals(
                $purchaseOrder,
                true,
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Cari approval aktif yang cocok dengan user login
    |--------------------------------------------------------------------------
    */
    public function getUserCurrentApproval(
        PurchaseOrder $purchaseOrder,
        User $user,
        bool $lockForUpdate = false,
    ): ?PurchaseOrderApproval {
        return $this
            ->getCurrentWaitingApprovals(
                $purchaseOrder,
                $lockForUpdate,
            )
            ->first(
                fn(PurchaseOrderApproval $approval): bool
                => $this->userCanApprove(
                    $approval,
                    $user,
                ),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Cek user berhak approve
    |--------------------------------------------------------------------------
    */
    public function userCanApprove(
        PurchaseOrderApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper(trim((string) $approval->status))
            !== PurchaseOrderApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_USER
        ) {
            return (int) $approval->approver_id
                === (int) $user->id;
        }

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_ROLE
        ) {
            return $this->userHasRoleId(
                $user,
                (int) $approval->approver_id,
            );
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Approve current step
    |--------------------------------------------------------------------------
    */
    public function approveCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Lock ulang approval
        |--------------------------------------------------------------------------
        */
        $approval = PurchaseOrderApproval::query()
            ->whereKey($approval->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            strtoupper(trim((string) $approval->status))
            !== PurchaseOrderApproval::STATUS_WAITING
        ) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Order ini sudah diproses.',
                ],
            ]);
        }

        if (!$this->userCanApprove($approval, $user)) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Purchase Order ini.',
                ],
            ]);
        }

        if (empty($user->signature_path)) {
            throw ValidationException::withMessages([
                'signature' => [
                    'Anda belum memiliki tanda tangan digital.',
                ],
            ]);
        }

        $approvalMode = strtoupper(
            trim(
                (string) (
                    $approval->approval_mode
                    ?: PurchaseOrderApproval::MODE_ANY
                ),
            ),
        );

        if (!in_array(
            $approvalMode,
            [
                PurchaseOrderApproval::MODE_ANY,
                PurchaseOrderApproval::MODE_ALL,
            ],
            true,
        )) {
            $approvalMode = PurchaseOrderApproval::MODE_ANY;
        }

        $approval->update([
            'status'
            => PurchaseOrderApproval::STATUS_APPROVED,

            'approver_name_snapshot'
            => $user->name,

            'signature_path'
            => $user->signature_path,

            'signed_at'
            => now(),

            'approved_at'
            => now(),

            'rejected_at'
            => null,

            'notes'
            => $this->sanitizeNotes($notes),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Mode ANY
        |--------------------------------------------------------------------------
        |
        | Satu kandidat approve sudah menyelesaikan seluruh step.
        | Kandidat WAITING lainnya pada step yang sama menjadi SKIPPED.
        |--------------------------------------------------------------------------
        */
        if (
            $approvalMode
            === PurchaseOrderApproval::MODE_ANY
        ) {
            PurchaseOrderApproval::query()
                ->where(
                    'purchase_order_id',
                    $approval->purchase_order_id,
                )
                ->where(
                    'step_order',
                    $approval->step_order,
                )
                ->where(
                    'id',
                    '!=',
                    $approval->id,
                )
                ->where(
                    'status',
                    PurchaseOrderApproval::STATUS_WAITING,
                )
                ->update([
                    'status'
                    => PurchaseOrderApproval::STATUS_SKIPPED,

                    'notes'
                    => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . $user->name
                        . '.',

                    'updated_at'
                    => now(),
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cek apakah step sekarang masih WAITING
        |--------------------------------------------------------------------------
        |
        | Mode ALL:
        | Bila masih ada WAITING, berarti belum semua approver menyetujui.
        |--------------------------------------------------------------------------
        */
        $stepStillWaiting = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $approval->purchase_order_id,
            )
            ->where(
                'step_order',
                $approval->step_order,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_WAITING,
            )
            ->exists();

        if ($stepStillWaiting) {
            return [
                'approval' => $approval->fresh(),
                'step_completed' => false,
                'has_next_step' => false,
                'next_step_order' => null,
                'is_final_approved' => false,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Cari nomor step berikutnya
        |--------------------------------------------------------------------------
        */
        $nextStepOrder = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $approval->purchase_order_id,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_PENDING,
            )
            ->where(
                'step_order',
                '>',
                $approval->step_order,
            )
            ->min('step_order');

        /*
        |--------------------------------------------------------------------------
        | Aktifkan semua kandidat step berikutnya
        |--------------------------------------------------------------------------
        */
        if ($nextStepOrder !== null) {
            PurchaseOrderApproval::query()
                ->where(
                    'purchase_order_id',
                    $approval->purchase_order_id,
                )
                ->where(
                    'step_order',
                    (int) $nextStepOrder,
                )
                ->where(
                    'status',
                    PurchaseOrderApproval::STATUS_PENDING,
                )
                ->update([
                    'status'
                    => PurchaseOrderApproval::STATUS_WAITING,

                    'updated_at'
                    => now(),
                ]);

            return [
                'approval' => $approval->fresh(),
                'step_completed' => true,
                'has_next_step' => true,
                'next_step_order' => (int) $nextStepOrder,
                'is_final_approved' => false,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Tidak ada step berikutnya
        |--------------------------------------------------------------------------
        */
        return [
            'approval' => $approval->fresh(),
            'step_completed' => true,
            'has_next_step' => false,
            'next_step_order' => null,
            'is_final_approved' => true,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reject current step
    |--------------------------------------------------------------------------
    */
    public function rejectCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null,
    ): PurchaseOrderApproval {
        $approval = PurchaseOrderApproval::query()
            ->whereKey($approval->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            strtoupper(trim((string) $approval->status))
            !== PurchaseOrderApproval::STATUS_WAITING
        ) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Order ini sudah diproses.',
                ],
            ]);
        }

        if (!$this->userCanApprove($approval, $user)) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Purchase Order ini.',
                ],
            ]);
        }

        $approval->update([
            'status'
            => PurchaseOrderApproval::STATUS_REJECTED,

            'approver_name_snapshot'
            => $user->name,

            'signature_path'
            => $user->signature_path,

            'signed_at'
            => !empty($user->signature_path)
                ? now()
                : null,

            'approved_at'
            => null,

            'rejected_at'
            => now(),

            'notes'
            => $this->sanitizeNotes($notes),
        ]);

        return $approval->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel approval tersisa setelah reject
    |--------------------------------------------------------------------------
    */
    public function cancelRemainingPendingApprovals(
        PurchaseOrder $purchaseOrder,
    ): void {
        PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->whereIn(
                'status',
                [
                    PurchaseOrderApproval::STATUS_WAITING,
                    PurchaseOrderApproval::STATUS_PENDING,
                ],
            )
            ->update([
                'status'
                => PurchaseOrderApproval::STATUS_CANCELLED,

                'notes'
                => 'Cancelled karena Purchase Order direject.',

                'updated_at'
                => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Cek apakah approval masih berjalan
    |--------------------------------------------------------------------------
    */
    public function hasPendingApproval(
        PurchaseOrder $purchaseOrder,
    ): bool {
        return PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->whereIn(
                'status',
                [
                    PurchaseOrderApproval::STATUS_WAITING,
                    PurchaseOrderApproval::STATUS_PENDING,
                ],
            )
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Final approve Purchase Order
    |--------------------------------------------------------------------------
    */
    public function markPurchaseOrderApproved(
        PurchaseOrder $purchaseOrder,
        User $user,
    ): void {
        $purchaseOrder->update([
            'status' => 'APPROVED',
            'status_receive' => 'OPEN',
            'approved_at' => now(),
            'approved_by' => $user->name,
        ]);

        DB::table('purchase_order_items')
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->whereNull('deleted_at')
            ->update([
                'qty_received' => 0,

                'qty_outstanding_receive'
                => DB::raw('qty'),

                'updated_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Final reject Purchase Order
    |--------------------------------------------------------------------------
    */
    public function markPurchaseOrderRejected(
        PurchaseOrder $purchaseOrder,
    ): void {
        $purchaseOrder->update([
            'status' => 'REJECTED',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Bersihkan notes
    |--------------------------------------------------------------------------
    */
    private function sanitizeNotes(
        ?string $notes,
    ): ?string {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return null;
        }

        return htmlspecialchars(
            strip_tags($notes),
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cek role user
    |--------------------------------------------------------------------------
    */
    private function userHasRoleId(
        User $user,
        int $roleId,
    ): bool {
        if ($roleId <= 0) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Struktur utama: user_roles
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasTable('user_roles')
            && DB::table('user_roles')
            ->where(
                'user_id',
                $user->id,
            )
            ->where(
                'role_id',
                $roleId,
            )
            ->exists()
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility: role_user
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasTable('role_user')
            && DB::table('role_user')
            ->where(
                'user_id',
                $user->id,
            )
            ->where(
                'role_id',
                $roleId,
            )
            ->exists()
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility: users.role_id
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasColumn('users', 'role_id')
            && $user->getAttribute('role_id') !== null
            && (int) $user->getAttribute('role_id')
            === $roleId
        ) {
            return true;
        }

        return false;
    }
}
