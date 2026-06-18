<?php

namespace App\Services\MasterVendor;

use App\Mail\MasterVendorApprovalMail;
use App\Models\MasterVendor;
use App\Models\MasterVendorApproval;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MasterVendorApprovalNotificationService
{
    public function __construct(
        private readonly MasterVendorApprovalService $approvalService,
    ) {}

    /**
     * Kirim notification dan email ke seluruh approver
     * pada step aktif vendor.
     */
    public function notifyCurrentApprovers(
        MasterVendor $vendor,
    ): void {
        try {
            $currentStepOrder = MasterVendorApproval::query()
                ->where('vendor_id', $vendor->id)
                ->where('status', MasterVendorApproval::STATUS_WAITING)
                ->min('step_order');

            if ($currentStepOrder === null) {
                return;
            }

            $currentApprovals = MasterVendorApproval::query()
                ->where('vendor_id', $vendor->id)
                ->where('step_order', (int) $currentStepOrder)
                ->where('status', MasterVendorApproval::STATUS_WAITING)
                ->orderBy('id')
                ->get();

            if ($currentApprovals->isEmpty()) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve seluruh approver USER/ROLE
            |--------------------------------------------------------------------------
            |
            | unique berdasarkan user ID untuk mencegah:
            | - user menerima dua notifikasi karena memiliki beberapa role;
            | - dua candidate approval mengarah kepada user yang sama.
            |--------------------------------------------------------------------------
            */
            $approvers = $currentApprovals
                ->flatMap(function (
                    MasterVendorApproval $approval,
                ): Collection {
                    return $this->approvalService
                        ->resolveApprovers($approval);
                })
                ->filter(function ($approver) {
                    return $approver instanceof User
                        && !empty($approver->id);
                })
                ->unique(function (User $approver) {
                    return (int) $approver->id;
                })
                ->values();

            foreach ($approvers as $approver) {
                $this->createNotification(
                    userId: (int) $approver->id,
                    vendor: $vendor,
                    type: 'master_vendor_approval_request',
                    title: 'Approval Master Vendor',
                    message: 'Vendor '
                        . $vendor->nama_vendor
                        . ' menunggu approval Anda.',
                );

                $this->sendEmail(
                    vendor: $vendor,
                    recipient: $approver,
                    mailType: 'approval_request',
                );
            }

            Log::info(
                '[Master Vendor Notification] Approver berhasil diberitahu',
                [
                    'vendor_id' => $vendor->id,
                    'step_order' => (int) $currentStepOrder,
                    'approver_count' => $approvers->count(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor Notification] Gagal notify approver',
                [
                    'vendor_id' => $vendor->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
        }
    }

    /**
     * Memberitahu pembuat vendor bahwa approval pada suatu step berhasil.
     */
    public function notifyCreatorApproved(
        MasterVendor $vendor,
        User $approvedBy,
        bool $isFinalApproved = false,
    ): void {
        try {
            $creator = $this->resolveCreator($vendor);

            if (!$creator) {
                return;
            }

            if ($isFinalApproved) {
                $title = 'Master Vendor Disetujui';

                $message =
                    'Vendor '
                    . $vendor->nama_vendor
                    . ' telah disetujui dan proses approval telah selesai.';

                $mailType = 'final_approved';
            } else {
                $title = 'Approval Master Vendor';

                $message =
                    'Vendor '
                    . $vendor->nama_vendor
                    . ' telah disetujui oleh '
                    . $this->userDisplayName($approvedBy)
                    . '.';

                $mailType = 'approved';
            }

            $this->createNotification(
                userId: (int) $creator->id,
                vendor: $vendor,
                type: $isFinalApproved
                    ? 'master_vendor_final_approved'
                    : 'master_vendor_approved',
                title: $title,
                message: $message,
            );

            $this->sendEmail(
                vendor: $vendor,
                recipient: $creator,
                mailType: $mailType,
                actor: $approvedBy,
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor Notification] Gagal notify creator approved',
                [
                    'vendor_id' => $vendor->id,
                    'approved_by' => $approvedBy->id,
                    'is_final_approved' => $isFinalApproved,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Memberitahu pembuat vendor bahwa vendor ditolak.
     */
    public function notifyCreatorRejected(
        MasterVendor $vendor,
        User $rejectedBy,
        ?string $notes = null,
    ): void {
        try {
            $creator = $this->resolveCreator($vendor);

            if (!$creator) {
                return;
            }

            $message =
                'Vendor '
                . $vendor->nama_vendor
                . ' ditolak oleh '
                . $this->userDisplayName($rejectedBy)
                . '.';

            if (!empty(trim((string) $notes))) {
                $message .= ' Catatan: ' . trim((string) $notes);
            }

            $this->createNotification(
                userId: (int) $creator->id,
                vendor: $vendor,
                type: 'master_vendor_rejected',
                title: 'Master Vendor Ditolak',
                message: $message,
            );

            $this->sendEmail(
                vendor: $vendor,
                recipient: $creator,
                mailType: 'rejected',
                actor: $rejectedBy,
                notes: $notes,
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor Notification] Gagal notify creator rejected',
                [
                    'vendor_id' => $vendor->id,
                    'rejected_by' => $rejectedBy->id,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Opsional: memberi tahu creator setelah vendor berhasil disubmit.
     */
    public function notifyCreatorSubmitted(
        MasterVendor $vendor,
    ): void {
        try {
            $creator = $this->resolveCreator($vendor);

            if (!$creator) {
                return;
            }

            $this->createNotification(
                userId: (int) $creator->id,
                vendor: $vendor,
                type: 'master_vendor_submitted',
                title: 'Master Vendor Disubmit',
                message: 'Vendor '
                    . $vendor->nama_vendor
                    . ' berhasil disubmit dan masuk proses approval.',
            );

            $this->sendEmail(
                vendor: $vendor,
                recipient: $creator,
                mailType: 'submitted',
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor Notification] Gagal notify creator submitted',
                [
                    'vendor_id' => $vendor->id,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    private function createNotification(
        int $userId,
        MasterVendor $vendor,
        string $type,
        string $title,
        string $message,
    ): void {
        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'module' => 'master_vendor',
            'reference_type' => MasterVendor::class,
            'reference_id' => $vendor->id,
            'reference_public_id' => $vendor->encrypted_id,
            'url' => '/master/vendor',
        ]);
    }

    private function sendEmail(
        MasterVendor $vendor,
        User $recipient,
        string $mailType,
        ?User $actor = null,
        ?string $notes = null,
    ): void {
        if (empty($recipient->email)) {
            Log::warning(
                '[Master Vendor Email] Recipient tidak memiliki email',
                [
                    'vendor_id' => $vendor->id,
                    'recipient_id' => $recipient->id,
                    'mail_type' => $mailType,
                ],
            );

            return;
        }

        try {
            Mail::to($recipient->email)->queue(
                new MasterVendorApprovalMail(
                    vendor: $vendor,
                    recipient: $recipient,
                    type: $mailType,
                    actor: $actor,
                    notes: $notes,
                ),
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor Email] Gagal memasukkan email ke queue',
                [
                    'vendor_id' => $vendor->id,
                    'recipient_id' => $recipient->id,
                    'recipient_email' => $recipient->email,
                    'mail_type' => $mailType,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    private function resolveCreator(
        MasterVendor $vendor,
    ): ?User {
        /*
        |--------------------------------------------------------------------------
        | Prioritas submitted_by
        |--------------------------------------------------------------------------
        |
        | Karena submit dilakukan oleh user yang bertanggung jawab terhadap
        | proses approval. Fallback ke created_by untuk data lama.
        |--------------------------------------------------------------------------
        */
        $creatorId = $vendor->submitted_by
            ?: $vendor->created_by;

        if (!$creatorId) {
            return null;
        }

        return User::query()
            ->whereKey($creatorId)
            ->where('is_active', true)
            ->first();
    }

    private function userDisplayName(
        User $user,
    ): string {
        return (string) (
            $user->name
            ?? $user->fullname
            ?? $user->email
            ?? 'Approver'
        );
    }
}
