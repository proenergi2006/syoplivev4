<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReturnReason extends Model
{
    protected $table = 'goods_return_reasons';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Detail retur yang menggunakan alasan ini
    |--------------------------------------------------------------------------
    */
    public function returnItems(): HasMany
    {
        return $this->hasMany(
            GoodsReturnItem::class,
            'reason_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scope alasan aktif
    |--------------------------------------------------------------------------
    */
    public function scopeActive($query)
    {
        return $query->where(
            'is_active',
            true,
        );
    }
}
