<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterBank extends Model
{
    protected $table = 'master_banks';

    protected $fillable = [
        'kode_bank',
        'nama_bank',
        'nama_bank_pendek',
        'swift_code',
        'tipe_bank',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
