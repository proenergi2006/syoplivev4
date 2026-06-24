<?php

namespace App\Mail;

use App\Models\MasterVendor;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MasterVendorApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MasterVendor $vendor,
        public User $recipient,
        public string $type,
        public ?User $actor = null,
        public ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->resolveSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.master_vendor_approval',
            with: [
                'vendor' => $this->vendor,
                'recipient' => $this->recipient,
                'type' => $this->type,
                'actor' => $this->actor,
                'notes' => $this->notes,
                'url' => url('/master/vendor'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function resolveSubject(): string
    {
        return match ($this->type) {
            'approval_request'
            => 'Approval Master Vendor - '
                . $this->vendor->nama_vendor,

            'submitted'
            => 'Master Vendor Berhasil Disubmit - '
                . $this->vendor->nama_vendor,

            'approved'
            => 'Master Vendor Telah Disetujui - '
                . $this->vendor->nama_vendor,

            'final_approved'
            => 'Master Vendor Selesai Disetujui - '
                . $this->vendor->nama_vendor,

            'rejected'
            => 'Master Vendor Ditolak - '
                . $this->vendor->nama_vendor,

            default
            => 'Informasi Master Vendor - '
                . $this->vendor->nama_vendor,
        };
    }
}
