<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseRequestApprovalService
{
    public function getCurrentWaitingApproval(PurchaseRequest $pr): ?PurchaseRequestApproval
    {
        return PurchaseRequestApproval::where('purchase_request_id', $pr->id)
            ->where('status', PurchaseRequestApproval::STATUS_WAITING)
            ->orderBy('step_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    public function userCanApprove(PurchaseRequestApproval $approval, User $user): bool
    {
        $approverType = strtoupper((string) $approval->approver_type);

        if ($approverType === PurchaseRequestApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === PurchaseRequestApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    public function approveCurrentStep(
        PurchaseRequestApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        DB::transaction(function () use ($approval, $user, $notes) {
            $approval = PurchaseRequestApproval::where('id', $approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->status !== PurchaseRequestApproval::STATUS_WAITING) {
                throw new Exception('Approval ini sudah tidak dalam status WAITING.');
            }

            if (!$this->userCanApprove($approval, $user)) {
                throw new Exception('Anda tidak memiliki akses untuk approve step ini.');
            }

            $cleanNotes = $this->cleanNotes($notes);

            $approval->update([
                'status' => PurchaseRequestApproval::STATUS_APPROVED,
                'approver_name_snapshot' => $this->getUserDisplayName($user),
                'signature_path' => $user->signature_path ?? null,
                'signed_at' => now(),
                'approved_at' => now(),
                'notes' => $cleanNotes,
            ]);

            $approvalMode = strtoupper((string) ($approval->approval_mode ?? PurchaseRequestApproval::APPROVAL_MODE_ANY));

            if ($approvalMode === PurchaseRequestApproval::APPROVAL_MODE_ANY) {
                $this->skipAlternativeApprovers($approval);
                $this->activateNextStep($approval, $user);

                return;
            }

            if ($approvalMode === PurchaseRequestApproval::APPROVAL_MODE_ALL) {
                $this->handleAllModeApproval($approval, $user);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Fallback default ANY
            |--------------------------------------------------------------------------
            */
            $this->skipAlternativeApprovers($approval);
            $this->activateNextStep($approval, $user);
        });
    }

    public function rejectCurrentStep(
        PurchaseRequestApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        DB::transaction(function () use ($approval, $user, $notes) {
            $approval = PurchaseRequestApproval::where('id', $approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->status !== PurchaseRequestApproval::STATUS_WAITING) {
                throw new Exception('Approval ini sudah tidak dalam status WAITING.');
            }

            if (!$this->userCanApprove($approval, $user)) {
                throw new Exception('Anda tidak memiliki akses untuk reject step ini.');
            }

            $cleanNotes = $this->cleanNotes($notes);

            $approval->update([
                'status' => PurchaseRequestApproval::STATUS_REJECTED,
                'approver_name_snapshot' => $this->getUserDisplayName($user),
                'signature_path' => $user->signature_path ?? null,
                'signed_at' => now(),
                'rejected_at' => now(),
                'notes' => $cleanNotes,
            ]);

            PurchaseRequestApproval::where('purchase_request_id', $approval->purchase_request_id)
                ->whereIn('status', [
                    PurchaseRequestApproval::STATUS_WAITING,
                    PurchaseRequestApproval::STATUS_PENDING,
                ])
                ->where('id', '!=', $approval->id)
                ->update([
                    'status' => PurchaseRequestApproval::STATUS_CANCELLED,
                    'notes' => 'Cancelled karena Purchase Request direject.',
                    'updated_at' => now(),
                ]);

            $pr = PurchaseRequest::where('id', $approval->purchase_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $pr->update([
                'status' => PurchaseRequest::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $user->id,
                'rejected_notes' => $cleanNotes,
            ]);
        });
    }

    private function skipAlternativeApprovers(PurchaseRequestApproval $approval): void
    {
        PurchaseRequestApproval::where('purchase_request_id', $approval->purchase_request_id)
            ->where('step_order', $approval->step_order)
            ->where('id', '!=', $approval->id)
            ->where('status', PurchaseRequestApproval::STATUS_WAITING)
            ->update([
                'status' => PurchaseRequestApproval::STATUS_SKIPPED,
                'notes' => 'Skipped karena step ini sudah disetujui oleh approver alternatif.',
                'updated_at' => now(),
            ]);
    }

    private function handleAllModeApproval(PurchaseRequestApproval $approval, User $user): void
    {
        /*
        |--------------------------------------------------------------------------
        | Mode ALL
        |--------------------------------------------------------------------------
        | Kalau masih ada approver WAITING di step yang sama,
        | berarti step belum selesai.
        |--------------------------------------------------------------------------
        */
        $remainingWaitingInSameStep = PurchaseRequestApproval::where('purchase_request_id', $approval->purchase_request_id)
            ->where('step_order', $approval->step_order)
            ->where('status', PurchaseRequestApproval::STATUS_WAITING)
            ->exists();

        if ($remainingWaitingInSameStep) {
            return;
        }

        $this->activateNextStep($approval, $user);
    }

    private function activateNextStep(PurchaseRequestApproval $approval, User $user): void
    {
        $nextStepOrder = PurchaseRequestApproval::where('purchase_request_id', $approval->purchase_request_id)
            ->where('step_order', '>', $approval->step_order)
            ->where('status', PurchaseRequestApproval::STATUS_PENDING)
            ->min('step_order');

        /*
        |--------------------------------------------------------------------------
        | Tidak ada step berikutnya
        |--------------------------------------------------------------------------
        | Berarti PR sudah final approved.
        |--------------------------------------------------------------------------
        */
        if (!$nextStepOrder) {
            $this->markPurchaseRequestApproved($approval, $user);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Aktifkan semua approver di step berikutnya
        |--------------------------------------------------------------------------
        | Jika step berikutnya punya beberapa approver alternatif,
        | semuanya menjadi WAITING.
        |--------------------------------------------------------------------------
        */
        PurchaseRequestApproval::where('purchase_request_id', $approval->purchase_request_id)
            ->where('step_order', $nextStepOrder)
            ->where('status', PurchaseRequestApproval::STATUS_PENDING)
            ->update([
                'status' => PurchaseRequestApproval::STATUS_WAITING,
                'updated_at' => now(),
            ]);
    }

    private function markPurchaseRequestApproved(PurchaseRequestApproval $approval, User $user): void
    {
        $pr = PurchaseRequest::where('id', $approval->purchase_request_id)
            ->lockForUpdate()
            ->firstOrFail();

        $pr->update([
            'status' => PurchaseRequest::STATUS_APPROVED,
            'final_approved_at' => now(),
            'final_approved_by' => $user->id,
        ]);
    }

    private function userHasRoleId(User $user, int $roleId): bool
    {
        if (!$roleId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Single role column
        |--------------------------------------------------------------------------
        | Jika user table punya role_id langsung.
        |--------------------------------------------------------------------------
        */
        if (isset($user->role_id) && (int) $user->role_id === $roleId) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Pivot role_user
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('role_user')) {
            $exists = DB::table('role_user')
                ->where('user_id', $user->id)
                ->where('role_id', $roleId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Pivot user_roles
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('user_roles')) {
            $exists = DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $roleId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    private function cleanNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $notes = trim($notes);

        if ($notes === '') {
            return null;
        }

        return htmlspecialchars(strip_tags($notes), ENT_QUOTES, 'UTF-8');
    }

    private function getUserDisplayName(User $user): string
    {
        return $user->fullname
            ?? $user->name
            ?? $user->email
            ?? 'Unknown User';
    }
}
