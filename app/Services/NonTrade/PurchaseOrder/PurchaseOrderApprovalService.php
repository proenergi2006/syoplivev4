<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderApprovalService
{
    public function getCurrentPendingApproval(PurchaseOrder $po): ?PurchaseOrderApproval
    {
        return PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();
    }

    public function userCanApprove(PurchaseOrderApproval $approval, User $user): bool
    {
        $approverType = strtoupper((string) $approval->approver_type);

        if ($approverType === 'USER') {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === 'ROLE') {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    public function approveCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        $approval->update([
            'status' => 'APPROVED',
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'notes' => $clean($notes),
        ]);

        /**
         * Setelah step saat ini APPROVED,
         * aktifkan step berikutnya yang masih PENDING menjadi WAITING.
         *
         * Contoh PO 40 juta:
         * GM Procurement WAITING -> APPROVED
         * CFO PENDING -> WAITING
         */
        $nextApproval = PurchaseOrderApproval::where('purchase_order_id', $approval->purchase_order_id)
            ->where('status', 'PENDING')
            ->where('step_order', '>', $approval->step_order)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();

        if ($nextApproval) {
            $nextApproval->update([
                'status' => 'WAITING',
            ]);
        }
    }

    public function rejectCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        $approval->update([
            'status' => 'REJECTED',
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $clean($notes),
        ]);
    }

    public function cancelRemainingPendingApprovals(PurchaseOrder $po): void
    {
        PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->whereIn('status', ['WAITING', 'PENDING'])
            ->update([
                'status' => 'CANCELLED',
                'notes' => 'Cancelled karena Purchase Order direject.',
            ]);
    }

    public function hasPendingApproval(PurchaseOrder $po): bool
    {
        return PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->whereIn('status', ['WAITING', 'PENDING'])
            ->exists();
    }

    public function markPurchaseOrderApproved(PurchaseOrder $po, User $user): void
    {
        $po->update([
            'status' => 'APPROVED',
            'status_receive' => 'OPEN',
            'approved_at' => now(),
            'approved_by' => $user->name,
        ]);

        DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)
            ->whereNull('deleted_at')
            ->update([
                'qty_received' => 0,
                'qty_outstanding_receive' => DB::raw('qty'),
                'updated_at' => now(),
            ]);
    }

    public function markPurchaseOrderRejected(PurchaseOrder $po): void
    {
        $po->status = 'REJECTED';
        $po->save();
    }

    private function userHasRoleId(User $user, int $roleId): bool
    {
        if (!$roleId) {
            return false;
        }

        /**
         * Struktur utama kamu:
         * users.role_id = roles.id
         */
        if (isset($user->role_id) && (int) $user->role_id === $roleId) {
            return true;
        }

        /**
         * Optional support kalau nanti ada pivot role_user.
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

        /**
         * Optional support kalau nanti ada pivot user_roles.
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
}
