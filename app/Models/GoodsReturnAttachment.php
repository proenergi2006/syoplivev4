<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GoodsReturnAttachment extends Model
{
    protected $table = 'goods_return_attachments';

    protected $fillable = [
        'goods_return_id',
        'document_type',
        'file_name',
        'file_original_name',
        'file_path',
        'file_mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $appends = [
        'encrypted_id',
    ];

    protected function casts(): array
    {
        return [
            'goods_return_id' => 'integer',
            'file_size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Public ID terenkripsi
    |--------------------------------------------------------------------------
    */
    protected function encryptedId(): Attribute
    {
        return Attribute::get(
            fn(): string => Crypt::encryptString(
                (string) $this->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Header retur
    |--------------------------------------------------------------------------
    */
    public function goodsReturn(): BelongsTo
    {
        return $this->belongsTo(
            GoodsReturn::class,
            'goods_return_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | User pengunggah
    |--------------------------------------------------------------------------
    */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by',
        );
    }
}
