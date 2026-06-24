<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiveAttachment extends Model
{
    protected $fillable = [
        'goods_receive_id',
        'document_type',
        'file_name',
        'file_original_name',
        'file_path',
        'file_mime_type',
        'file_size',
        'uploaded_by',
    ];

    public function goodsReceive()
    {
        return $this->belongsTo(GoodsReceive::class, 'goods_receive_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
