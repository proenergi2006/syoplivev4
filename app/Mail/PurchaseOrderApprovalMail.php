<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseOrder $po;
    public User $approver;

    public function __construct(PurchaseOrder $po, User $approver)
    {
        $this->po = $po;
        $this->approver = $approver;
    }

    public function build()
    {
        return $this->subject('Approval Purchase Order - ' . $this->po->nomor_po)
            ->view('emails.purchase_order_approval');
    }
}
