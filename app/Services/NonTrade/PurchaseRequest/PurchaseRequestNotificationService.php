<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\Notification;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PurchaseRequestNotificationService
{
    /*
    |--------------------------------------------------------------------------
    | Notifikasi permintaan approval
    |--------------------------------------------------------------------------
    | Dipanggil setelah:
    | - PR berhasil disubmit; atau
    | - step berikutnya diaktifkan.
    |--------------------------------------------------------------------------
    */
    public function notifyApprovalRequest(
        PurchaseRequest $purchaseRequest,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Tentukan step aktif paling awal
        |--------------------------------------------------------------------------
        */
        $currentStepOrder = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_WAITING,
            )
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning(
                '[Purchase Requisition Notification] Approval WAITING tidak ditemukan',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil semua approver pada step aktif
        |--------------------------------------------------------------------------
        | Mode ANY dapat mempunyai lebih dari satu row pada step yang sama.
        |--------------------------------------------------------------------------
        */
        $currentApprovals = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->where(
                'step_order',
                (int) $currentStepOrder,
            )
            ->where(
                'status',
                PurchaseRequestApproval::STATUS_WAITING,
            )
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve seluruh row approver menjadi user
        |--------------------------------------------------------------------------
        | unique('id') mencegah notifikasi ganda jika satu user:
        | - memiliki role approver; dan
        | - dipilih langsung sebagai USER.
        |--------------------------------------------------------------------------
        */
        $approverUsers = $currentApprovals
            ->flatMap(
                fn(PurchaseRequestApproval $approval): Collection =>
                $this->resolveApproverUsers($approval),
            )
            ->filter(
                fn($user) => $user instanceof User,
            )
            ->unique('id')
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning(
                '[Purchase Requisition Notification] Approver user tidak ditemukan',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'step_order' => (int) $currentStepOrder,
                    'approvals' => $currentApprovals
                        ->map(fn(PurchaseRequestApproval $approval) => [
                            'approval_id' => $approval->id,
                            'approver_type' => $approval->approver_type,
                            'approver_id' => $approval->approver_id,
                            'label' => $approval->label,
                        ])
                        ->values()
                        ->all(),
                ],
            );

            return;
        }

        $stepLabel = $currentApprovals
            ->pluck('label')
            ->filter()
            ->first();

        foreach ($approverUsers as $user) {
            /*
            |--------------------------------------------------------------------------
            | Cegah duplikasi notifikasi aktif untuk PR dan step yang sama
            |--------------------------------------------------------------------------
            */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'purchase_request_approval')
                ->where('reference_type', PurchaseRequest::class)
                ->where('reference_id', $purchaseRequest->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'purchase_request_approval',
                'title' => 'Approval Purchase Requisition',
                'message' => $this->buildApprovalMessage(
                    $purchaseRequest,
                    (int) $currentStepOrder,
                    $stepLabel,
                ),
                'module' => 'purchase_request',
                'reference_type' => PurchaseRequest::class,
                'reference_id' => $purchaseRequest->id,
                'reference_public_id' => $purchaseRequest->encrypted_id,
                'url' => '/non_trade/purchase_request',
            ]);

            Log::info(
                '[Purchase Requisition Notification] Notifikasi approval dibuat',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'step_order' => (int) $currentStepOrder,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ],
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Notifikasi setelah satu tahap berhasil disetujui
    |--------------------------------------------------------------------------
    | Dikirim kepada requester.
    |--------------------------------------------------------------------------
    */
    public function notifyApprovalStep(
        PurchaseRequest $purchaseRequest,
        User $approver,
        PurchaseRequestApproval $approval,
        bool $hasPendingApproval,
    ): void {
        $requesterId = $this->getRequesterUserId(
            $purchaseRequest,
        );

        if (!$requesterId) {
            Log::warning(
                '[Purchase Requisition Notification] Requester PR tidak ditemukan',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                ],
            );

            return;
        }

        Notification::create([
            'user_id' => $requesterId,

            'type' => $hasPendingApproval
                ? 'purchase_request_approval_step_approved'
                : 'purchase_request_approved',

            'title' => $hasPendingApproval
                ? 'Tahap Approval PR Disetujui'
                : 'Purchase Requisition Disetujui',

            'message' => $hasPendingApproval
                ? 'Purchase Requisition '
                . $purchaseRequest->nomor_pr
                . ' telah disetujui oleh '
                . ($approver->name ?? '-')
                . ' pada tahap '
                . $approval->step_order
                . ' dan masih menunggu approval berikutnya.'
                : 'Purchase Requisition '
                . $purchaseRequest->nomor_pr
                . ' telah final disetujui oleh '
                . ($approver->name ?? '-')
                . '.',

            'module' => 'purchase_request',
            'reference_type' => PurchaseRequest::class,
            'reference_id' => $purchaseRequest->id,
            'reference_public_id' => $purchaseRequest->encrypted_id,
            'url' => '/non_trade/purchase_request',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifikasi penolakan
    |--------------------------------------------------------------------------
    | Dikirim kepada requester.
    |--------------------------------------------------------------------------
    */
    public function notifyRejected(
        PurchaseRequest $purchaseRequest,
        User $rejecter,
    ): void {
        $requesterId = $this->getRequesterUserId(
            $purchaseRequest,
        );

        if (!$requesterId) {
            return;
        }

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'purchase_request_rejected',
            'title' => 'Purchase Requisition Ditolak',
            'message' => 'Purchase Requisition '
                . $purchaseRequest->nomor_pr
                . ' telah ditolak oleh '
                . ($rejecter->name ?? '-')
                . '.',
            'module' => 'purchase_request',
            'reference_type' => PurchaseRequest::class,
            'reference_id' => $purchaseRequest->id,
            'reference_public_id' => $purchaseRequest->encrypted_id,
            'url' => '/non_trade/purchase_request',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve approver menjadi user
    |--------------------------------------------------------------------------
    */
    public function resolveApproverUsers(
        PurchaseRequestApproval $approval,
    ): Collection {
        $approverType = strtoupper(
            trim((string) $approval->approver_type),
        );

        if (!$approval->approver_id) {
            return collect();
        }

        if ($approverType === 'USER') {
            return User::query()
                ->whereKey($approval->approver_id)
                ->get();
        }

        if ($approverType === 'ROLE') {
            return $this->resolveUsersByRoleId(
                (int) $approval->approver_id,
            );
        }

        return collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve user berdasarkan role
    |--------------------------------------------------------------------------
    */
    private function resolveUsersByRoleId(int $roleId): Collection
    {
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
            ->filter(fn($id) => $id !== null && (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        Log::info('[PR Notification] Role user IDs ditemukan', [
            'role_id' => $roleId,
            'user_ids' => $userIds->all(),
        ]);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = User::query()
            ->whereIn('id', $userIds);

        /*
    |--------------------------------------------------------------------------
    | Aktifkan hanya jika nilai is_active kedua user memang benar
    |--------------------------------------------------------------------------
    */
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        $users = $query->get();

        Log::info('[PR Notification] Role users resolved', [
            'role_id' => $roleId,
            'users' => $users
                ->map(fn(User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active ?? null,
                ])
                ->values()
                ->all(),
        ]);

        return $users;
    }
    /*
    |--------------------------------------------------------------------------
    | Tentukan requester
    |--------------------------------------------------------------------------
    | Prioritaskan submitted_by karena merupakan user yang menekan Submit.
    | Fallback ke created_by.
    |--------------------------------------------------------------------------
    */
    private function getRequesterUserId(
        PurchaseRequest $purchaseRequest,
    ): ?int {
        $requesterId = $purchaseRequest->submitted_by
            ?? $purchaseRequest->created_by
            ?? null;

        return $requesterId
            ? (int) $requesterId
            : null;
    }

    private function buildApprovalMessage(
        PurchaseRequest $purchaseRequest,
        int $stepOrder,
        ?string $stepLabel,
    ): string {
        $message = 'Purchase Requisition '
            . $purchaseRequest->nomor_pr
            . ' menunggu approval Anda pada tahap '
            . $stepOrder;

        if ($stepLabel) {
            $message .= ' (' . $stepLabel . ')';
        }

        return $message . '.';
    }
}
