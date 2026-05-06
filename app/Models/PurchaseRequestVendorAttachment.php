<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequestVendorAttachment extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'pr_vendor_offer_attachments';
    protected $dates = ['deleted_at'];
    protected $appends = ['full_url'];
    protected $fillable = [
        'pr_vendor_offer_id',
        'filename',
        'filepath',
        'filesize',
        'filetype',
    ];

    public function offer()
    {
        return $this->belongsTo(PurchaseRequestVendor::class, 'id');
    }

    public function getFullUrlAttribute()
    {
        return asset('storage/' . $this->filepath);
    }
}
