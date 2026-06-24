<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CabangSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('cabang')->truncate();

        $default = [
            'catatan_cabang' => null,
            'kode_barcode' => 0,
            'urut_ba' => 0,
            'urut_spj' => 0,
            'urut_dn' => 0,
            'urut_dn_kpl' => 0,
            'urut_pr' => 0,
            'urut_po' => 0,
            'urut_po_kpl' => 0,
            'urut_ds' => 0,
            'urut_penawaran' => 0,
            'urut_oslog' => 0,
            'urut_lo' => 0,
            'urut_segel' => 0,
            'urut_pr_ti' => 0,
            'urut_lo_ti' => 0,
            'urut_ds_ti' => 0,
            'urut_po_ti' => 0,
            'urut_spj_ti' => 0,
            'urut_dn_ti' => 0,
            'stok_segel' => 0,
            'is_active' => true,
            'created_time' => now(),
            'created_ip' => null,
            'created_by' => 'Super Admin',
            'lastupdate_time' => null,
            'lastupdate_ip' => null,
            'lastupdate_by' => null,
        ];

        $rows = [
            ['id' => 1,  'group_cabang_id' => 1, 'nama_cabang' => 'Kantor Pusat', 'inisial_cabang' => 'HO',  'inisial_segel' => 'HO'],
            ['id' => 2,  'group_cabang_id' => 2, 'nama_cabang' => 'Jakarta',      'inisial_cabang' => 'JKT', 'inisial_segel' => 'E'],
            ['id' => 3,  'group_cabang_id' => 2, 'nama_cabang' => 'Palembang',    'inisial_cabang' => 'PLB', 'inisial_segel' => 'P'],
            ['id' => 4,  'group_cabang_id' => 3, 'nama_cabang' => 'Samarinda',    'inisial_cabang' => 'SMD', 'inisial_segel' => 'SMD'],
            ['id' => 5,  'group_cabang_id' => 3, 'nama_cabang' => 'Pontianak',    'inisial_cabang' => 'PTK', 'inisial_segel' => 'P'],
            ['id' => 6,  'group_cabang_id' => 2, 'nama_cabang' => 'Surabaya',     'inisial_cabang' => 'SBY', 'inisial_segel' => 'Y'],
            ['id' => 7,  'group_cabang_id' => 3, 'nama_cabang' => 'Banjarmasin',  'inisial_cabang' => 'BJM', 'inisial_segel' => 'B'],
            ['id' => 8,  'group_cabang_id' => 3, 'nama_cabang' => 'Palangkaraya', 'inisial_cabang' => 'PLK', 'inisial_segel' => 'PLK'],
            ['id' => 10, 'group_cabang_id' => 3, 'nama_cabang' => 'Bali',         'inisial_cabang' => 'DPS', 'inisial_segel' => 'DPS'],
            ['id' => 11, 'group_cabang_id' => 2, 'nama_cabang' => 'Sulawesi',     'inisial_cabang' => 'SLW', 'inisial_segel' => 'SLW'],
        ];

        $insertData = array_map(fn($row) => array_merge($default, $row), $rows);

        DB::table('cabang')->insert($insertData);

        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('cabang', 'id'),
                (SELECT MAX(id) FROM cabang)
            )
        ");
    }
}
