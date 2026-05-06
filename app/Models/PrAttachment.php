<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrAttachment extends Model
{
    use HasFactory;

    protected $table = 'pr_attachments';

    protected $fillable = [
        'purchase_request_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath'
    ];

    /**
     * Relasi ke Purchase Request
     */
    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
