<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // Hapus semua data lama
        DB::table('departments')->truncate();

        // =========================
        // INSERT DATA DEPARTMENT
        // =========================

        DB::table('departments')->insert([
            [
                'kode' => 'IT',
                'nama' => 'Information Technology',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'PROC',
                'nama' => 'Procurement',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'GA',
                'nama' => 'General Affair',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'LOG',
                'nama' => 'Logistic',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'HRD',
                'nama' => 'Human Resource Development',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'FIN',
                'nama' => 'Finance',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'COM',
                'nama' => 'Commercial',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'kode' => 'BOD',
                'nama' => 'Board of Director',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ]);
    }
}
