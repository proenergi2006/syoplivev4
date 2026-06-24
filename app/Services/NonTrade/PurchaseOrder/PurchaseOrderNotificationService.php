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
    /**
     * Mengirim notifikasi kepada seluruh kandidat pada step aktif.
     *
     * Satu step dapat mempunyai beberapa row approval:
     *
     * Step 1 - ROLE GM Procurement
     * Step 1 - USER Chris
     *
     * Semua kandidat yang sesuai akan menerima notifikasi,
     * tetapi setiap user hanya menerima satu notifikasi.
     */
    public function notifyApprovalRequest(
        PurchaseOrder $po,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cari step aktif terkecil
        |--------------------------------------------------------------------------
        */
        $currentStepOrder = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $po->id,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_WAITING,
            )
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning(
                '[Purchase Order Notification] Approval WAITING tidak ditemukan',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil seluruh kandidat pada step aktif
        |--------------------------------------------------------------------------
        */
        $currentApprovals = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $po->id,
            )
            ->where(
                'step_order',
                (int) $currentStepOrder,
            )
            ->where(
                'status',
                PurchaseOrderApproval::STATUS_WAITING,
            )
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve semua user kandidat
        |--------------------------------------------------------------------------
        */
        $approverUsers = $currentApprovals
            ->flatMap(function (
                PurchaseOrderApproval $approval,
            ): Collection {
                $users = $this->resolveApproverUsers(
                    $approval,
                );

                if ($users->isEmpty()) {
                    Log::warning(
                        '[Purchase Order Notification] Approver user tidak ditemukan',
                        [
                            'po_id'
                            => $approval->purchase_order_id,

                            'approval_id'
                            => $approval->id,

                            'step_order'
                            => $approval->step_order,

                            'approver_type'
                            => $approval->approver_type,

                            'approver_id'
                            => $approval->approver_id,

                            'label'
                            => $approval->label,
                        ],
                    );
                }

                return $users;
            })

            /*
            |--------------------------------------------------------------------------
            | Hindari user menerima notifikasi ganda
            |--------------------------------------------------------------------------
            |
            | Contoh:
            | Chris cocok dari ROLE dan juga terdaftar sebagai USER langsung.
            |--------------------------------------------------------------------------
            */
            ->filter(
                fn($user) => !empty($user?->id),
            )
            ->unique(
                fn($user) => (int) $user->id,
            )
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning(
                '[Purchase Order Notification] Tidak ada user penerima notifikasi',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'step_order' => (int) $currentStepOrder,
                    'approval_ids'
                    => $currentApprovals->pluck('id')->all(),
                ],
            );

            return;
        }

        foreach ($approverUsers as $user) {
            Notification::create([
                'user_id' => $user->id,

                'type' => 'purchase_order_approval',

                'title' => 'Approval Purchase Order',

                'message'
                => 'Purchase Order '
                    . $po->nomor_po
                    . ' menunggu approval Anda.',

                'module' => 'purchase_order',

                'reference_type' => PurchaseOrder::class,

                'reference_id' => $po->id,

                'reference_public_id' => $po->encrypted_id,

                'url' => '/non_trade/purchase_order',
            ]);
        }

        Log::info(
            '[Purchase Order Notification] Approval request berhasil dibuat',
            [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
                'step_order' => (int) $currentStepOrder,
                'approval_ids'
                => $currentApprovals->pluck('id')->all(),
                'recipient_user_ids'
                => $approverUsers->pluck('id')->all(),
            ],
        );
    }

    /**
     * Notifikasi kepada requester setelah satu approver memproses PO.
     *
     * $hasPendingApproval:
     * true  = PO masih dalam proses approval.
     * false = PO sudah final approved.
     */
    public function notifyApprovalStep(
        PurchaseOrder $po,
        User $approver,
        PurchaseOrderApproval $approval,
        bool $hasPendingApproval,
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Hindari requester menerima notifikasi terhadap dirinya sendiri
        |--------------------------------------------------------------------------
        |
        | Bagian ini opsional, tetapi biasanya lebih rapi.
        | Hapus kondisi ini bila requester tetap harus menerima notif.
        |--------------------------------------------------------------------------
        */
        // if (
        //     (int) $po->requester_signed_by
        //     === (int) $approver->id
        // ) {
        //     return;
        // }

        Notification::create([
            'user_id' => $po->requester_signed_by,

            'type' => $hasPendingApproval
                ? 'purchase_order_approval_step_approved'
                : 'purchase_order_approved',

            'title' => $hasPendingApproval
                ? 'Tahap Approval PO Disetujui'
                : 'Purchase Order Disetujui',

            'message' => $hasPendingApproval
                ? 'Purchase Order '
                . $po->nomor_po
                . ' telah disetujui oleh '
                . ($approver->name ?? '-')
                . ' dan masih menunggu approval berikutnya.'
                : 'Purchase Order '
                . $po->nomor_po
                . ' telah final disetujui oleh '
                . ($approver->name ?? '-')
                . '.',

            'module' => 'purchase_order',

            'reference_type' => PurchaseOrder::class,

            'reference_id' => $po->id,

            'reference_public_id' => $po->encrypted_id,

            'url' => '/non_trade/purchase_order',
        ]);
    }

    /**
     * Notifikasi reject kepada requester.
     */
    public function notifyRejected(
        PurchaseOrder $po,
        User $rejecter,
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        Notification::create([
            'user_id' => $po->requester_signed_by,

            'type' => 'purchase_order_rejected',

            'title' => 'Purchase Order Ditolak',

            'message'
            => 'Purchase Order '
                . $po->nomor_po
                . ' telah ditolak oleh '
                . ($rejecter->name ?? '-')
                . '.',

            'module' => 'purchase_order',

            'reference_type' => PurchaseOrder::class,

            'reference_id' => $po->id,

            'reference_public_id' => $po->encrypted_id,

            'url' => '/non_trade/purchase_order',
        ]);
    }

    /**
     * Resolve satu row approval menjadi collection user.
     */
    private function resolveApproverUsers(
        PurchaseOrderApproval $approval,
    ): Collection {
        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        $approverId = (int) $approval->approver_id;

        if ($approverId <= 0) {
            return collect();
        }

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($approverId)
                ->get();
        }

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_ROLE
        ) {
            return $this->resolveUsersByRoleId(
                $approverId,
            );
        }

        return collect();
    }

    /**
     * Resolve seluruh user dari role.
     *
     * Semua sumber digabung, lalu dideduplikasi.
     * Tidak berhenti ketika users.role_id tersedia tetapi hasilnya kosong.
     */
    private function resolveUsersByRoleId(
        int $roleId,
    ): Collection {
        if ($roleId <= 0) {
            return collect();
        }

        $userIds = collect();

        /*
        |--------------------------------------------------------------------------
        | Struktur utama project: user_roles
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('user_roles')) {
            $userIds = $userIds->merge(
                DB::table('user_roles')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility: role_user
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('role_user')) {
            $userIds = $userIds->merge(
                DB::table('role_user')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility: users.role_id
        |--------------------------------------------------------------------------
        */
        if (Schema::hasColumn('users', 'role_id')) {
            $userIds = $userIds->merge(
                User::query()
                    ->where('role_id', $roleId)
                    ->pluck('id'),
            );
        }

        $userIds = $userIds
            ->filter(
                fn($userId) => (int) $userId > 0,
            )
            ->map(
                fn($userId) => (int) $userId,
            )
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            Log::warning(
                '[Purchase Order Notification] User untuk role tidak ditemukan',
                [
                    'role_id' => $roleId,
                    'checked' => [
                        'user_roles',
                        'role_user',
                        'users.role_id',
                    ],
                ],
            );

            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get();
    }
}
