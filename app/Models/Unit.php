<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory;
    protected $fillable = ['kode', 'nama', 'kategori'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'satuan', 'id');
    }
}
