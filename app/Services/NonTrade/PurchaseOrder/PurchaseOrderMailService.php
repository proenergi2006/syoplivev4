<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Mail\PurchaseOrderApprovalMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderMailService
{
    /**
     * Kirim email ke seluruh kandidat pada step approval aktif.
     *
     * Deduplikasi dilakukan berdasarkan alamat email.
     */
    public function sendApprovalRequest(
        PurchaseOrder $po,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cari step aktif
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
                '[Purchase Order Mail] Approval WAITING tidak ditemukan',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil semua kandidat WAITING pada step tersebut
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
        | Resolve seluruh user
        |--------------------------------------------------------------------------
        */
        $approvers = $currentApprovals
            ->flatMap(function (
                PurchaseOrderApproval $approval,
            ): Collection {
                $users = $this->resolveApprovalUsers(
                    $approval,
                );

                if ($users->isEmpty()) {
                    Log::warning(
                        '[Purchase Order Mail] User approver tidak ditemukan',
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
            | Pastikan email tersedia
            |--------------------------------------------------------------------------
            */
            ->filter(function ($user) {
                return !empty(trim((string) $user?->email));
            })

            /*
            |--------------------------------------------------------------------------
            | Deduplikasi berdasarkan alamat email
            |--------------------------------------------------------------------------
            |
            | Bila dua account memakai alamat email yang sama,
            | hanya satu email yang dikirim.
            |--------------------------------------------------------------------------
            */
            ->unique(function ($user) {
                return strtolower(
                    trim((string) $user->email),
                );
            })
            ->values();

        if ($approvers->isEmpty()) {
            Log::warning(
                '[Purchase Order Mail] Tidak ada email approver yang valid',
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

        foreach ($approvers as $approver) {
            Log::info(
                '[Purchase Order Mail] Queue approval request email',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'step_order' => (int) $currentStepOrder,
                    'approval_ids'
                    => $currentApprovals->pluck('id')->all(),
                    'recipient_user_id' => $approver->id,
                    'to' => $approver->email,
                    'queue_connection'
                    => config('queue.default'),
                ],
            );

            Mail::to($approver->email)
                ->queue(
                    new PurchaseOrderApprovalMail(
                        po: $po,
                        recipient: $approver,
                        mode: 'approval_request',
                    ),
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Email PO final approved
    |--------------------------------------------------------------------------
    | Email kepada creator hanya dikirim ketika seluruh proses approval selesai.
    |--------------------------------------------------------------------------
    */
    public function sendApprovalStep(
        PurchaseOrder $po,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Jangan kirim email jika approval belum final
        |--------------------------------------------------------------------------
        */
        if ($hasPendingApproval) {
            return;
        }

        $creatorId = (int) ($po->created_by ?? 0);

        if ($creatorId <= 0) {
            Log::warning(
                '[Purchase Order Mail] Creator PO tidak ditemukan',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'created_by' => $po->created_by,
                ],
            );

            return;
        }

        $creator = User::query()
            ->find($creatorId);

        if (
            !$creator
            || empty(trim((string) $creator->email))
        ) {
            Log::warning(
                '[Purchase Order Mail] Email creator tidak ditemukan',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'creator_id' => $creatorId,
                ],
            );

            return;
        }

        try {
            Mail::to($creator->email)
                ->queue(
                    new PurchaseOrderApprovalMail(
                        po: $po,
                        recipient: $creator,
                        mode: 'final_approved',
                        actor: $approver,
                        isFinalApproved: true,
                    ),
                );
        } catch (\Throwable $e) {
            Log::error(
                '[Purchase Order Mail] Gagal queue final approved email',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'creator_id' => $creator->id,
                    'to' => $creator->email,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
        }
    }

    /**
     * Kirim email reject kepada requester.
     */
    public function sendRejected(
        PurchaseOrder $po,
        User $rejecter,
        ?string $notes = null,
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        $requester = User::query()
            ->find($po->requester_signed_by);

        if (
            !$requester
            || empty(trim((string) $requester->email))
        ) {
            Log::warning(
                '[Purchase Order Mail] Email requester reject tidak ditemukan',
                [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'requester_signed_by'
                    => $po->requester_signed_by,
                ],
            );

            return;
        }

        Mail::to($requester->email)
            ->queue(
                new PurchaseOrderApprovalMail(
                    po: $po,
                    recipient: $requester,
                    mode: 'rejected',
                    actor: $rejecter,
                    notes: $notes,
                ),
            );
    }

    /**
     * Resolve satu row approval menjadi user.
     */
    private function resolveApprovalUsers(
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
                ->whereNotNull('email')
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
     * Resolve user berdasarkan role dari seluruh struktur yang tersedia.
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
        | Struktur utama: user_roles
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
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email')
            ->get();
    }
}
