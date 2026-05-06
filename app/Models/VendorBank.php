<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorBank extends Model
{
    use HasFactory;

    protected $table = 'vendor_banks';

    protected $fillable = [
        'vendor_id',
        'atas_nama',
        'nomor_rekening',
        'cabang',
        'alamat_bank',
        'is_active',
        'bank_id',
        'swift_code_snapshot',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function masterBank()
    {
        return $this->belongsTo(MasterBank::class, 'bank_id');
    }
}
