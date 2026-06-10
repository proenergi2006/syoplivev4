<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Mail\PurchaseOrderApprovalMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderMailService
{
    public function sendApprovalRequest(PurchaseOrder $po): void
    {
        $currentApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'WAITING')
            ->orderBy('step_order')
            ->first();

        if (!$currentApproval) {
            Log::warning('[Purchase Order Mail] Approval WAITING tidak ditemukan', [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
            ]);

            return;
        }

        $approvers = $this->resolveApprovalUsers($currentApproval);

        if ($approvers->isEmpty()) {
            Log::warning('[Purchase Order Mail] User approver tidak ditemukan', [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
                'approval_id' => $currentApproval->id,
                'approver_type' => $currentApproval->approver_type,
                'approver_id' => $currentApproval->approver_id,
                'label' => $currentApproval->label,
            ]);

            return;
        }

        foreach ($approvers as $approver) {
            if (!$approver->email) {
                Log::warning('[Purchase Order Mail] User approver tidak punya email', [
                    'po_id' => $po->id,
                    'approval_id' => $currentApproval->id,
                    'user_id' => $approver->id,
                    'name' => $approver->name,
                ]);

                continue;
            }

            Log::info('[Purchase Order Mail] Queue approval request email', [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
                'approval_id' => $currentApproval->id,
                'approver_type' => $currentApproval->approver_type,
                'approver_id' => $currentApproval->approver_id,
                'to' => $approver->email,
                'queue_connection' => config('queue.default'),
            ]);

            Mail::to($approver->email)
                ->queue(new PurchaseOrderApprovalMail(
                    po: $po,
                    recipient: $approver,
                    mode: 'approval_request',
                ));
        }
    }

    public function sendApprovalStep(
        PurchaseOrder $po,
        User $approver,
        bool $hasPendingApproval
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        $requester = User::find($po->requester_signed_by);

        if (!$requester || !$requester->email) {
            return;
        }

        Mail::to($requester->email)
            ->queue(new PurchaseOrderApprovalMail(
                po: $po,
                recipient: $requester,
                mode: $hasPendingApproval
                    ? 'step_approved'
                    : 'final_approved',
                actor: $approver,
                isFinalApproved: !$hasPendingApproval,
            ));
    }

    public function sendRejected(
        PurchaseOrder $po,
        User $rejecter,
        ?string $notes = null
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        $requester = User::find($po->requester_signed_by);

        if (!$requester || !$requester->email) {
            return;
        }

        Mail::to($requester->email)
            ->queue(new PurchaseOrderApprovalMail(
                po: $po,
                recipient: $requester,
                mode: 'rejected',
                actor: $rejecter,
                notes: $notes,
            ));
    }

    private function resolveApprovalUsers(PurchaseOrderApproval $approval): Collection
    {
        $approverType = strtoupper((string) $approval->approver_type);
        $approverId = (int) $approval->approver_id;

        if (!$approverId) {
            return collect();
        }

        if ($approverType === 'USER') {
            return User::query()
                ->where('id', $approverId)
                ->whereNotNull('email')
                ->get();
        }

        if ($approverType === 'ROLE') {
            $users = collect();

            if (Schema::hasColumn('users', 'role_id')) {
                $users = User::query()
                    ->where('role_id', $approverId)
                    ->whereNotNull('email')
                    ->get();
            }

            if ($users->isEmpty() && Schema::hasTable('role_user')) {
                $userIds = DB::table('role_user')
                    ->where('role_id', $approverId)
                    ->pluck('user_id')
                    ->toArray();

                if (!empty($userIds)) {
                    $users = User::query()
                        ->whereIn('id', $userIds)
                        ->whereNotNull('email')
                        ->get();
                }
            }

            if ($users->isEmpty() && Schema::hasTable('user_roles')) {
                $userIds = DB::table('user_roles')
                    ->where('role_id', $approverId)
                    ->pluck('user_id')
                    ->toArray();

                if (!empty($userIds)) {
                    $users = User::query()
                        ->whereIn('id', $userIds)
                        ->whereNotNull('email')
                        ->get();
                }
            }

            return $users;
        }

        return collect();
    }
}
