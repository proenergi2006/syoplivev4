<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

class PurchaseRequestApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $approvalUrl;

    public float $totalAmount;

    public function __construct(
        public PurchaseRequest $pr,
        public User $recipient,
        public string $mode = 'approval_request',
        public ?User $actor = null,
        public ?string $notes = null,
        public bool $isFinalApproved = false,
        public ?int $stepOrder = null,
        public ?string $stepLabel = null,
    ) {
        /*
        |--------------------------------------------------------------------------
        | Pastikan data baru diproses setelah transaction berhasil commit
        |--------------------------------------------------------------------------
        */
        $this->afterCommit();

        $this->pr->loadMissing('items');

        $this->totalAmount = $this->calculateTotalAmount();

        $encryptedId = $this->pr->encrypted_id
            ?? Crypt::encryptString((string) $this->pr->id);

        /*
        |--------------------------------------------------------------------------
        | Sementara menuju halaman daftar PR
        |--------------------------------------------------------------------------
        | Nanti dapat diarahkan ke halaman detail/inbox approval PR.
        |--------------------------------------------------------------------------
        */
        $this->approvalUrl = url(
            '/non_trade/purchase_request'
                . '?reference='
                . urlencode($encryptedId),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->getSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_request_approval',
            with: [
                'pr' => $this->pr,
                'recipient' => $this->recipient,
                'mode' => $this->mode,
                'actor' => $this->actor,
                'notes' => $this->notes,
                'isFinalApproved' => $this->isFinalApproved,
                'stepOrder' => $this->stepOrder,
                'stepLabel' => $this->stepLabel,
                'totalAmount' => $this->totalAmount,
                'approvalUrl' => $this->approvalUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function getSubject(): string
    {
        $nomorPr = $this->pr->nomor_pr ?: '-';

        return match ($this->mode) {
            'step_approved' =>
            'Tahap Approval Purchase Requisition Disetujui - ' . $nomorPr,

            'final_approved' =>
            'Purchase Requisition Disetujui - ' . $nomorPr,

            'rejected' =>
            'Purchase Requisition Ditolak - ' . $nomorPr,

            default =>
            'Permintaan Approval Purchase Requisition - ' . $nomorPr,
        };
    }

    private function calculateTotalAmount(): float
    {
        $headerTotal = (float) (
            $this->pr->total_nilai
            ?? $this->pr->grand_total
            ?? $this->pr->total_amount
            ?? 0
        );

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        return (float) $this->pr->items->sum(function ($item) {
            $subtotal = (float) (
                $item->subtotal
                ?? $item->total
                ?? 0
            );

            if ($subtotal > 0) {
                return $subtotal;
            }

            $qty = (float) (
                $item->qty
                ?? $item->quantity
                ?? 0
            );

            $price = (float) (
                $item->harga
                ?? $item->harga_satuan
                ?? $item->unit_price
                ?? 0
            );

            return $qty * $price;
        });
    }
}
