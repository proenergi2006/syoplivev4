<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cabang extends Model
{
    protected $table = 'cabang';

    public $timestamps = false;

    protected $fillable = [
        'group_cabang_id',
        'nama_cabang',
        'inisial_cabang',
        'inisial_segel',
        'catatan_cabang',
        'kode_barcode',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function groupCabang()
    {
        return $this->belongsTo(GroupCabang::class);
    }
}
