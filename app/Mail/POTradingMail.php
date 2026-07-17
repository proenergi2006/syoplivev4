<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class POTradingMail extends Mailable
{
    use Queueable, SerializesModels;
    public $po;
    public $type;
    public $nomorPO;
   
    public function __construct($nomorPO, $form, $user, $type)
    {
        $this->po = [
            'nomor_po' => $nomorPO,
            'vendor' => $form['vendor'],
            'produk' => $form['produk'],
            'volume_po' => $form['volume_po'],
            'total_order' => $form['volume_po'] * $form['harga_tebus'],
            'created_by' => $user['name'],
            'id_master' => $form['id_master'] ?? null,
        ];

        $this->type = $type;
        $this->nomorPO = $nomorPO;
    }

    public function build()
    {
        $config = match ($this->type) {

            'need_cfo' => [
                'title' => 'Permintaan Approval PO['.$this->nomorPO.']',
                'message' => 'Purchase Order telah dibuat oleh Procurement dan memerlukan persetujuan dari CFO. Mohon melakukan review terhadap detail Purchase Order berikut sebelum dilakukan proses selanjutnya.',
            ],

            'auto_cfo' => [
                'title' => 'Permintaan Approval PO['.$this->nomorPO.']',
                'message' => 'Persetujuan Purchase Order selanjutnya. PO ini telah melewati batas waktu approval CFO selama 20 menit, sehingga sistem melakukan approval CFO secara otomatis.',
            ],

            'need_ceo' => [
                'title' => 'PO['.$this->nomorPO.']  Menunggu Approval CEO',
                'message' => 'CFO sudah melakukan approval PO dengan keterangan berikut.',
            ],

            'approved' => [
                'title' => 'PO['.$this->nomorPO.'] Approved',
                'message' => 'Purchase Order telah disetujui oleh seluruh approver (CFO dan CEO) dan siap diproses lebih lanjut.',
            ],

            'rejected' => [
                'title' => 'PO['.$this->nomorPO.'] Rejected',
                'message' => 'Purchase Order ditolak oleh approver.',
            ],

            'approved_gain' => [
                'title' => 'PO['.$this->nomorPO.'] Approved',
                'message' => 'Purchase Order (Gain / Loss) telah disetujui dan siap diproses lebih lanjut.',
            ],

            'rejected_gain' => [
                'title' => 'PO['.$this->nomorPO.'] Rejected',
                'message' => 'Purchase Order (Gain / Loss) ditolak oleh CEO.',
            ],

            'resubmit' => [
                'title' => 'Perubahan dan Pengajuan Ulang PO [' . $this->nomorPO . ']',
                'message' => 'Purchase Order telah diperbarui dan memerlukan persetujuan ulang.',
            ],

            default => [
                'title' => 'Purchase Order Notification',
                'message' => 'Notifikasi Purchase Order.',
            ],
        };

        return $this->subject($config['title'])
            ->view('email.trading.approvalPO')
            ->with([
                'title' => $config['title'],
                'messageBody' => $config['message'],
                'po' => $this->po,
                'actionUrl' => url('/login'),
            ]);
    }
}