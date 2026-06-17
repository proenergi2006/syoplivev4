<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Mail\PurchaseRequestApprovalMail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class PurchaseRequestMailService
{
    /*
    |--------------------------------------------------------------------------
    | Email approval request
    |--------------------------------------------------------------------------
    | Mengirim email kepada semua approver pada step aktif.
    |--------------------------------------------------------------------------
    */
    public function sendApprovalRequest(
        PurchaseRequest $purchaseRequest,
    ): void {
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
                '[Purchase Request Mail] Approval WAITING tidak ditemukan',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil semua approval WAITING pada step yang sama
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
        | Resolve approver dari seluruh row
        |--------------------------------------------------------------------------
        | Pertama unique berdasarkan user ID.
        |--------------------------------------------------------------------------
        */
        $approvers = $currentApprovals
            ->flatMap(
                fn(PurchaseRequestApproval $approval): Collection =>
                $this->resolveApprovalUsers($approval),
            )
            ->filter(
                fn($user) =>
                $user instanceof User
                    && filled($user->email),
            )
            ->unique('id')
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Deduplikasi email
        |--------------------------------------------------------------------------
        | Dua akun bisa memiliki email yang sama.
        | Email hanya dikirim satu kali ke alamat tersebut.
        |--------------------------------------------------------------------------
        */
        $approvers = $approvers
            ->unique(
                fn(User $user): string =>
                strtolower(trim((string) $user->email)),
            )
            ->values();

        if ($approvers->isEmpty()) {
            Log::warning(
                '[Purchase Request Mail] User approver tidak ditemukan',
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

        $purchaseRequest->loadMissing('items');

        foreach ($approvers as $approver) {
            try {
                Log::info(
                    '[Purchase Request Mail] Queue approval request email',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'nomor_pr' => $purchaseRequest->nomor_pr,
                        'step_order' => (int) $currentStepOrder,
                        'user_id' => $approver->id,
                        'to' => $approver->email,
                        'queue_connection' => config('queue.default'),
                    ],
                );

                Mail::to($approver->email)
                    ->queue(
                        new PurchaseRequestApprovalMail(
                            pr: $purchaseRequest,
                            recipient: $approver,
                            mode: 'approval_request',
                            stepOrder: (int) $currentStepOrder,
                            stepLabel: $stepLabel,
                        ),
                    );
            } catch (\Throwable $e) {
                Log::error(
                    '[Purchase Request Mail] Gagal queue approval email',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'nomor_pr' => $purchaseRequest->nomor_pr,
                        'user_id' => $approver->id,
                        'to' => $approver->email,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Email tahap approval disetujui
    |--------------------------------------------------------------------------
    | Dikirim kepada requester.
    |--------------------------------------------------------------------------
    */
    public function sendApprovalStep(
        PurchaseRequest $purchaseRequest,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        $requester = $this->resolveRequester(
            $purchaseRequest,
        );

        if (!$requester || !$requester->email) {
            Log::warning(
                '[Purchase Request Mail] Requester tidak memiliki email',
                [
                    'purchase_request_id' => $purchaseRequest->id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'requester_id' => $purchaseRequest->submitted_by
                        ?? $purchaseRequest->created_by
                        ?? null,
                ],
            );

            return;
        }

        Mail::to($requester->email)
            ->queue(
                new PurchaseRequestApprovalMail(
                    pr: $purchaseRequest,
                    recipient: $requester,
                    mode: $hasPendingApproval
                        ? 'step_approved'
                        : 'final_approved',
                    actor: $approver,
                    isFinalApproved: !$hasPendingApproval,
                ),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Email PR ditolak
    |--------------------------------------------------------------------------
    */
    public function sendRejected(
        PurchaseRequest $purchaseRequest,
        User $rejecter,
        ?string $notes = null,
    ): void {
        $requester = $this->resolveRequester(
            $purchaseRequest,
        );

        if (!$requester || !$requester->email) {
            return;
        }

        Mail::to($requester->email)
            ->queue(
                new PurchaseRequestApprovalMail(
                    pr: $purchaseRequest,
                    recipient: $requester,
                    mode: 'rejected',
                    actor: $rejecter,
                    notes: $notes,
                ),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve satu row approval menjadi user
    |--------------------------------------------------------------------------
    */
    private function resolveApprovalUsers(
        PurchaseRequestApproval $approval,
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
            === PurchaseRequestApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($approverId)
                ->whereNotNull('email')
                ->get();
        }

        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_ROLE
        ) {
            return $this->resolveUsersByRoleId(
                $approverId,
            );
        }

        return collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve seluruh user berdasarkan role
    |--------------------------------------------------------------------------
    | Tidak menggunakan return terlalu cepat dari users.role_id.
    |--------------------------------------------------------------------------
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
                fn($id) =>
                $id !== null
                    && (int) $id > 0,
            )
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email');

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    private function resolveRequester(
        PurchaseRequest $purchaseRequest,
    ): ?User {
        $requesterId = $purchaseRequest->submitted_by
            ?? $purchaseRequest->created_by
            ?? null;

        if (!$requesterId) {
            return null;
        }

        return User::query()
            ->whereKey($requesterId)
            ->first();
    }
}
