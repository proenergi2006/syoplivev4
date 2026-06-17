<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseRequestApprovalService
{
    /*
    |--------------------------------------------------------------------------
    | Ambil step aktif paling awal
    |--------------------------------------------------------------------------
    */
    public function getCurrentStepOrder(
        PurchaseRequest $purchaseRequest,
    ): ?int {
        $stepOrder = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_WAITING,
            )
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Ambil seluruh approval pada step aktif
    |--------------------------------------------------------------------------
    */
    public function getCurrentWaitingApprovals(
        PurchaseRequest $purchaseRequest,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder(
            $purchaseRequest,
        );

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'step_order',
                $currentStepOrder,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_WAITING,
            )
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Cari row approval aktif yang sesuai dengan user login
    |--------------------------------------------------------------------------
    */
    public function getUserCurrentApproval(
        PurchaseRequest $purchaseRequest,
        User $user,
        bool $lockForUpdate = false,
    ): ?PurchaseRequestApproval {
        return $this
            ->getCurrentWaitingApprovals(
                $purchaseRequest,
                $lockForUpdate,
            )
            ->first(
                fn(PurchaseRequestApproval $approval): bool =>
                $this->userCanApprove($approval, $user),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Cek apakah user boleh approve row tertentu
    |--------------------------------------------------------------------------
    */
    public function userCanApprove(
        PurchaseRequestApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper((string) $approval->status)
            !== PurchaseRequestApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_USER
        ) {
            return (int) $approval->approver_id
                === (int) $user->id;
        }

        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_ROLE
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
    | Approve step aktif
    |--------------------------------------------------------------------------
    | Return:
    | - approval
    | - has_pending_approval
    | - is_final_approved
    | - next_step_order
    |--------------------------------------------------------------------------
    */
    public function approveCurrentStep(
        PurchaseRequest $purchaseRequest,
        User $user,
        ?string $notes = null,
    ): array {
        $approval = $this->getUserCurrentApproval(
            $purchaseRequest,
            $user,
            true,
        );

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Purchase Requisition ini.',
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

        $notes = $this->sanitizeNotes($notes);

        $approvalMode = strtoupper(
            trim(
                (string) (
                    $approval->approval_mode
                    ?: PurchaseRequestApproval::APPROVAL_MODE_ANY
                ),
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Approve row milik actor
        |--------------------------------------------------------------------------
        */
        $approval->update([
            'status' => PurchaseRequestApproval::STATUS_APPROVED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'rejected_at' => null,
            'notes' => $notes,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Mode ANY
        |--------------------------------------------------------------------------
        | Satu approver cukup.
        | Approver lain pada step yang sama menjadi SKIPPED.
        |--------------------------------------------------------------------------
        */
        if (
            $approvalMode
            === PurchaseRequestApproval::APPROVAL_MODE_ANY
        ) {
            PurchaseRequestApproval::query()
                ->where(
                    'purchase_request_id',
                    $purchaseRequest->id,
                )
                ->where(
                    'step_order',
                    $approval->step_order,
                )
                ->whereKeyNot($approval->id)
                ->where(
                    'status',
                    PurchaseRequestApproval::STATUS_WAITING,
                )
                ->update([
                    'status' => PurchaseRequestApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver')
                        . '.',
                    'updated_at' => now(),
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cek apakah step sekarang sudah selesai
        |--------------------------------------------------------------------------
        | ANY: selesai karena row lain sudah SKIPPED.
        | ALL: selesai setelah seluruh row step APPROVED/SKIPPED.
        |--------------------------------------------------------------------------
        */
        $stepStillWaiting = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'step_order',
                $approval->step_order,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_WAITING,
            )
            ->exists();

        /*
        |--------------------------------------------------------------------------
        | Untuk mode ALL, masih ada approver lain yang belum approve
        |--------------------------------------------------------------------------
        */
        if ($stepStillWaiting) {
            return [
                'approval' => $approval->fresh(),
                'step_completed' => false,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Aktifkan step berikutnya
        |--------------------------------------------------------------------------
        */
        $nextStepOrder = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_PENDING,
            )
            ->where(
                'step_order',
                '>',
                $approval->step_order,
            )
            ->min('step_order');

        if ($nextStepOrder !== null) {
            PurchaseRequestApproval::query()
                ->where(
                    'purchase_request_id',
                    $purchaseRequest->id,
                )
                ->where(
                    'step_order',
                    (int) $nextStepOrder,
                )
                ->where(
                    'status',
                    PurchaseRequestApproval::STATUS_PENDING,
                )
                ->update([
                    'status' => PurchaseRequestApproval::STATUS_WAITING,
                    'updated_at' => now(),
                ]);

            return [
                'approval' => $approval->fresh(),
                'step_completed' => true,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => (int) $nextStepOrder,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Tidak ada step berikutnya: final approved
        |--------------------------------------------------------------------------
        */
        $this->markPurchaseRequestApproved(
            $purchaseRequest,
            $user,
        );

        return [
            'approval' => $approval->fresh(),
            'step_completed' => true,
            'has_pending_approval' => false,
            'is_final_approved' => true,
            'next_step_order' => null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reject step aktif
    |--------------------------------------------------------------------------
    | Reject oleh satu approver langsung menghentikan seluruh flow.
    |--------------------------------------------------------------------------
    */
    public function rejectCurrentStep(
        PurchaseRequest $purchaseRequest,
        User $user,
        ?string $notes = null,
    ): PurchaseRequestApproval {
        $approval = $this->getUserCurrentApproval(
            $purchaseRequest,
            $user,
            true,
        );

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Purchase Requisition ini.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => PurchaseRequestApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path)
                ? now()
                : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Cancel semua approval lain yang belum selesai
        |--------------------------------------------------------------------------
        */
        PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                PurchaseRequestApproval::STATUS_WAITING,
                PurchaseRequestApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => PurchaseRequestApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena Purchase Requisition direject.',
                'updated_at' => now(),
            ]);

        $this->markPurchaseRequestRejected(
            $purchaseRequest,
            $user,
        );

        return $approval->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Apakah masih ada approval berjalan
    |--------------------------------------------------------------------------
    */
    public function hasPendingApproval(
        PurchaseRequest $purchaseRequest,
    ): bool {
        return PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->whereIn('status', [
                PurchaseRequestApproval::STATUS_WAITING,
                PurchaseRequestApproval::STATUS_PENDING,
            ])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Final approved
    |--------------------------------------------------------------------------
    | Sesuaikan field approved_by jika tipe kolomnya bukan integer.
    |--------------------------------------------------------------------------
    */
    public function markPurchaseRequestApproved(
        PurchaseRequest $purchaseRequest,
        User $user,
    ): void {
        $data = [
            'status' => PurchaseRequest::STATUS_APPROVED,
            'status_po' => PurchaseRequest::STATUS_PO_OPEN,
        ];

        if (Schema::hasColumn('purchase_requests', 'approved_at')) {
            $data['approved_at'] = now();
        }

        if (Schema::hasColumn('purchase_requests', 'approved_by')) {
            /*
            | Jika approved_by FK user, gunakan ID.
            | Jika varchar nama, ganti menjadi $user->name.
            */
            $data['approved_by'] = $user->id;
        }

        $purchaseRequest->update($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejected
    |--------------------------------------------------------------------------
    */
    public function markPurchaseRequestRejected(
        PurchaseRequest $purchaseRequest,
        User $user,
    ): void {
        $data = [
            'status' => PurchaseRequest::STATUS_REJECTED,
        ];

        if (Schema::hasColumn('purchase_requests', 'rejected_at')) {
            $data['rejected_at'] = now();
        }

        if (Schema::hasColumn('purchase_requests', 'rejected_by')) {
            $data['rejected_by'] = $user->id;
        }

        $purchaseRequest->update($data);
    }

    public function sanitizeNotes(
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

    private function userHasRoleId(
        User $user,
        int $roleId,
    ): bool {
        if ($roleId <= 0) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Struktur utama project: user_roles
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasTable('user_roles')
            && DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists()
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility role_user
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasTable('role_user')
            && DB::table('role_user')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists()
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility users.role_id
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasColumn('users', 'role_id')
            && $user->getAttribute('role_id') !== null
            && (int) $user->getAttribute('role_id') === $roleId
        ) {
            return true;
        }

        return false;
    }
}
