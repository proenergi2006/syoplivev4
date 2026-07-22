<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class InventoryVendorPo extends Model
{
    use HasFactory;
    protected $table = 'inventory_vendor_po';
    protected $primaryKey = 'id_master';
    public $timestamps = false;

    protected $fillable = [
        'id_master',
        'id_accurate',
        'id_vendor',
        'id_produk',
        'id_terminal',
        'nomor_po',
        'tanggal_inven',
        'volume_po',
        'harga_po',
        'harga_tebus',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
        'kd_tax',
        'terms',
        'terms_day',
        'kategori_oa',
        'is_biaya',
        'kategori_plat',
        'ongkos_angkut',
        'subtotal',
        'ppn_11',
        'ppn_12',
        'dpp_11_12',
        'pph_22',
        'pbbkb_po',
        'iuran_migas',
        'nilai_pbbkb',
        'nominal_migas',
        'pbbkb',
        'total_order',
        'keterangan',
        'is_close',
        'is_cancel',
        'keterangan_cancel',
        'tanggal_close',
        'volume_close',
        'disposisi_po',
        'cfo_result',
        'cfo_pic',
        'cfo_tanggal',
        'cfo_summary',
        'ceo_result',
        'ceo_pic',
        'ceo_tanggal',
        'ceo_summary',
        'revert_cfo',
        'revert_cfo_summary',
        'revert_ceo',
        'revert_ceo_summary',
        'volume_ri',
        'resubmission_date',
        'is_resubmission',
        'resubmission_count',
        'jenis_kirim',
        'jenis_harga',
        'is_price_changed',
        'internal_notes',
    ];

    // FK Vendor
    public function vendor()
    {
        return $this->belongsTo(
            MasterVendor::class,
            'id_vendor',
            'id'
        );
    }

    // FK -> pro_master_produk.id_master
    public function produk()
    {
        return $this->belongsTo(
            Produk::class,
            'id_produk',
            'id'
        );
    }

    // FK -> pro_master_terminal.id_master
    public function terminal()
    {
        return $this->belongsTo(
            Terminal::class,
            'id_terminal',
            'id'
        );
    }

    public function goodReceipt()
    {
        return $this->hasMany(
            InventoryVendorReceive::class,
            'id_po_supplier',
            'id_master'
        );
    }
}
