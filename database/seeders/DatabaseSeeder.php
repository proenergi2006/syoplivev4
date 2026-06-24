<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run(): void
    {
        $this->call([
            MasterBankSeeder::class,
            MasterDokumenPendukungSeeder::class,
            UnitsSeeder::class,
            InitialSetupSeeder::class,
            MasterKeteranganTransaksiSeeder::class,
            GroupCabangSeeder::class,
            CabangSeeder::class,
            DepartmentSeeder::class,
            ApprovalFlowSeeder::class,
            PermissionSeeder::class,
            PermissionModuleSeeder::class,
            GoodsReturnReasonSeeder::class,
        ]);
    }
}
