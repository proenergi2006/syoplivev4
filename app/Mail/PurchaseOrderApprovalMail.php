<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class PurchaseOrderApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PurchaseOrder $po;
    public User $recipient;
    public ?User $actor;
    public string $mode;
    public bool $isFinalApproved;
    public ?string $notes;

    public function __construct(
        PurchaseOrder $po,
        User $recipient,
        string $mode = 'approval_request',
        ?User $actor = null,
        bool $isFinalApproved = false,
        ?string $notes = null
    ) {
        $this->po = $po;
        $this->recipient = $recipient;
        $this->actor = $actor;
        $this->mode = $mode;
        $this->isFinalApproved = $isFinalApproved;
        $this->notes = $notes;
    }

    public function build()
    {
        $subject = match ($this->mode) {
            'final_approved' => 'Purchase Order Disetujui - ' . $this->po->nomor_po,
            'step_approved' => 'Update Approval Purchase Order - ' . $this->po->nomor_po,
            'rejected' => 'Purchase Order Ditolak - ' . $this->po->nomor_po,
            default => 'Approval Purchase Order - ' . $this->po->nomor_po,
        };

        return $this->subject($subject)
            ->view('emails.purchase_order_approval');
    }
}
