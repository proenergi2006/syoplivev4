<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class MasterVendor extends Model
{
    use HasFactory;

    protected $table = 'master_vendor';

    protected $fillable = [
        'public_id',
        'kode_vendor',
        'nama_vendor',
        'is_active',
        'inisial_vendor',
        'telepon',
        'fax',
        'email',
        'jenis_perusahaan',
        'kategori_vendor',
        'nomor_ktp',
        'alamat',

        'nama_pic',
        'jabatan_pic',
        'telp_pic',
        'email_pic',

        'status_pkp',
        'no_npwp',
        'alamat_npwp',
        'no_sppkp',
        'tgl_sppkp',
        'alamat_sppkp',
        'same_as_npwp',

        'jenis_pembayaran',
        'top',
        'id_department',
    ];

    protected $casts = [
        'tgl_sppkp' => 'date',
        'same_as_npwp' => 'boolean',
    ];

    public function transaksi()
    {
        return $this->hasMany(VendorTransaksi::class, 'vendor_id');
    }

    public function banks()
    {
        return $this->hasMany(VendorBank::class, 'vendor_id');
    }

    public function dokumenPendukung()
    {
        return $this->hasMany(VendorDokumenPendukung::class, 'vendor_id');
    }

    public function getPublicIdAttribute(): string
    {
        return Crypt::encryptString((string) $this->id);
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'id_department');
    }
}
