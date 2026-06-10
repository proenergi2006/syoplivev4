<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderNotificationService
{
    public function notifyApprovalRequest(PurchaseOrder $po): void
    {
        $nextApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->first();

        if (!$nextApproval) {
            return;
        }

        $approverUsers = $this->resolveApproverUsers($nextApproval);

        if ($approverUsers->isEmpty()) {
            Log::warning('[Purchase Order Notification] Approver user tidak ditemukan', [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
                'approval_id' => $nextApproval->id,
                'approver_type' => $nextApproval->approver_type,
                'approver_id' => $nextApproval->approver_id,
                'label' => $nextApproval->label,
            ]);

            return;
        }

        foreach ($approverUsers as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'purchase_order_approval',
                'title' => 'Approval Purchase Order',
                'message' => 'Purchase Order ' . $po->nomor_po . ' menunggu approval Anda.',
                'module' => 'purchase_order',
                'reference_type' => PurchaseOrder::class,
                'reference_id' => $po->id,
                'reference_public_id' => $po->encrypted_id,
                'url' => '/non_trade/purchase_order',
            ]);
        }
    }

    public function notifyApprovalStep(
        PurchaseOrder $po,
        User $approver,
        PurchaseOrderApproval $approval,
        bool $hasPendingApproval
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        Notification::create([
            'user_id' => $po->requester_signed_by,
            'type' => $hasPendingApproval
                ? 'purchase_order_approval_step_approved'
                : 'purchase_order_approved',
            'title' => $hasPendingApproval
                ? 'Tahap Approval PO Disetujui'
                : 'Purchase Order Disetujui',
            'message' => $hasPendingApproval
                ? 'Purchase Order ' . $po->nomor_po . ' telah disetujui oleh ' . ($approver->name ?? '-') . ' dan masih menunggu approval berikutnya.'
                : 'Purchase Order ' . $po->nomor_po . ' telah final disetujui oleh ' . ($approver->name ?? '-') . '.',
            'module' => 'purchase_order',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
            'reference_public_id' => $po->encrypted_id,
            'url' => '/non_trade/purchase_order',
        ]);
    }

    public function notifyRejected(
        PurchaseOrder $po,
        User $rejecter
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        Notification::create([
            'user_id' => $po->requester_signed_by,
            'type' => 'purchase_order_rejected',
            'title' => 'Purchase Order Ditolak',
            'message' => 'Purchase Order ' . $po->nomor_po . ' telah ditolak oleh ' . ($rejecter->name ?? '-') . '.',
            'module' => 'purchase_order',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
            'reference_public_id' => $po->encrypted_id,
            'url' => '/non_trade/purchase_order',
        ]);
    }

    private function resolveApproverUsers(PurchaseOrderApproval $approval): Collection
    {
        $approverType = strtoupper((string) $approval->approver_type);

        if (!$approval->approver_id) {
            return collect();
        }

        if ($approverType === 'USER') {
            return User::query()
                ->where('id', $approval->approver_id)
                ->get();
        }

        if ($approverType === 'ROLE') {
            return $this->resolveUsersByRoleId((int) $approval->approver_id);
        }

        return collect();
    }

    private function resolveUsersByRoleId(int $roleId): Collection
    {
        /**
         * Opsi 1:
         * Struktur sederhana:
         * users.role_id = roles.id
         */
        if (Schema::hasColumn('users', 'role_id')) {
            return User::query()
                ->where('role_id', $roleId)
                ->get();
        }

        /**
         * Opsi 2:
         * Pivot table: role_user
         * role_user.user_id
         * role_user.role_id
         */
        if (Schema::hasTable('role_user')) {
            $userIds = DB::table('role_user')
                ->where('role_id', $roleId)
                ->pluck('user_id')
                ->filter()
                ->values();

            if ($userIds->isNotEmpty()) {
                return User::query()
                    ->whereIn('id', $userIds)
                    ->get();
            }
        }

        /**
         * Opsi 3:
         * Pivot table: user_roles
         * user_roles.user_id
         * user_roles.role_id
         */
        if (Schema::hasTable('user_roles')) {
            $userIds = DB::table('user_roles')
                ->where('role_id', $roleId)
                ->pluck('user_id')
                ->filter()
                ->values();

            if ($userIds->isNotEmpty()) {
                return User::query()
                    ->whereIn('id', $userIds)
                    ->get();
            }
        }

        Log::warning('[Purchase Order Notification] Struktur role user tidak ditemukan', [
            'role_id' => $roleId,
            'checked' => [
                'users.role_id',
                'role_user',
                'user_roles',
            ],
        ]);

        return collect();
    }
}
