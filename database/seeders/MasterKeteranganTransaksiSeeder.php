<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterKeteranganTransaksiSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('master_keterangan_transaksi')->truncate();

        DB::table('master_keterangan_transaksi')->insert([
            [
                'id' => 1,
                'kategori' => 'Penjualan Barang / Spareparts / Lain-lain',
                'pasal_pajak' => null,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'kategori' => 'Jasa Konstruksi dengan SIUJK / SBUJK',
                'pasal_pajak' => null,
                'is_active' => true,
            ],
            [
                'id' => 3,
                'kategori' => 'Jasa Konstruksi Non SIUJK / SBUJK',
                'pasal_pajak' => null,
                'is_active' => true,
            ],
            [
                'id' => 4,
                'kategori' => 'Sewa Tanah/Bangunan',
                'pasal_pajak' => '(PPh Pasal 4 Ayat 2)',
                'is_active' => true,
            ],
            [
                'id' => 5,
                'kategori' => 'Sewa Aktiva Tetap Selain Tanah & Bangunan (Mobil, Tangki, dll)',
                'pasal_pajak' => '(PPh Pasal 23)',
                'is_active' => true,
            ],
            [
                'id' => 6,
                'kategori' => 'Jasa Perorangan / Yang dilakukan Orang Pribadi',
                'pasal_pajak' => '(PPh 21: NPWP 2.5%, Non NPWP lebih tinggi)',
                'is_active' => true,
            ],
            [
                'id' => 7,
                'kategori' => 'Jasa Angkut / Transporter / Forwarder',
                'pasal_pajak' => '(PPh Pasal 23)',
                'is_active' => true,
            ],
            [
                'id' => 8,
                'kategori' => 'Jasa Pelayaran dengan SIUPAL',
                'pasal_pajak' => '(PPh Pasal 15)',
                'is_active' => true,
            ],
            [
                'id' => 9,
                'kategori' => 'Jasa Pelayaran tanpa SIUPAL',
                'pasal_pajak' => '(PPh Pasal 23)',
                'is_active' => true,
            ],
            [
                'id' => 10,
                'kategori' => 'Lainnya (Teknologi Informasi)',
                'pasal_pajak' => null,
                'is_active' => true,
            ],
        ]);
    }
}