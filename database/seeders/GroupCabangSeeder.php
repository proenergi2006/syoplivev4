<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GroupCabangSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('group_cabang')->insert([
            [
                'group_wilayah'   => 'Group Pusat',
                'is_active'       => true,
                'created_time'    => now(),
                'created_ip'      => null,
                'created_by'      => null,
                'lastupdate_time' => null,
                'lastupdate_ip'   => null,
                'lastupdate_by'   => null,
            ],
            [
                'group_wilayah'   => 'Wilayah 1',
                'is_active'       => true,
                'created_time'    => now(),
                'created_ip'      => null,
                'created_by'      => null,
                'lastupdate_time' => null,
                'lastupdate_ip'   => null,
                'lastupdate_by'   => null,
            ],
            [
                'group_wilayah'   => 'Wilayah 2',
                'is_active'       => true,
                'created_time'    => now(),
                'created_ip'      => null,
                'created_by'      => null,
                'lastupdate_time' => null,
                'lastupdate_ip'   => null,
                'lastupdate_by'   => null,
            ],
        ]);
    }
}
