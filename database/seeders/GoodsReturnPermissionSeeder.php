<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GoodsReturnPermissionSeeder extends Seeder
{
    /**
     * Jalankan seeder permission Goods Return.
     */
    public function run(): void
    {
        $now = now();

        $permissions = [
            [
                'module' => 'goods_return',
                'action' => 'view',
                'code' => 'goods_return.view',
                'name' => 'View Goods Return',
                'description' => 'Melihat daftar dan detail retur barang sesuai scope akses.',
                'is_active' => true,
            ],
            [
                'module' => 'goods_return',
                'action' => 'create',
                'code' => 'goods_return.create',
                'name' => 'Create Goods Return',
                'description' => 'Membuat dokumen retur barang dari Goods Receipt yang sudah diposting.',
                'is_active' => true,
            ],
            [
                'module' => 'goods_return',
                'action' => 'update',
                'code' => 'goods_return.update',
                'name' => 'Update Goods Return',
                'description' => 'Mengubah dokumen retur barang yang masih berstatus DRAFT.',
                'is_active' => true,
            ],
            [
                'module' => 'goods_return',
                'action' => 'delete',
                'code' => 'goods_return.delete',
                'name' => 'Delete Goods Return',
                'description' => 'Menghapus dokumen retur barang yang masih berstatus DRAFT.',
                'is_active' => true,
            ],
            [
                'module' => 'goods_return',
                'action' => 'post',
                'code' => 'goods_return.post',
                'name' => 'Post Goods Return',
                'description' => 'Memposting retur barang dan mengembalikan qty outstanding pada Purchase Order.',
                'is_active' => true,
            ],
            [
                'module' => 'goods_return',
                'action' => 'cancel',
                'code' => 'goods_return.cancel',
                'name' => 'Cancel Goods Return',
                'description' => 'Membatalkan dokumen retur barang yang sudah diposting sesuai ketentuan sistem.',
                'is_active' => true,
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                [
                    'code' => $permission['code'],
                ],
                [
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'is_active' => $permission['is_active'],
                    'updated_at' => $now,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Isi created_at hanya untuk permission yang baru dibuat
            |--------------------------------------------------------------------------
            */
            DB::table('permissions')
                ->where('code', $permission['code'])
                ->whereNull('created_at')
                ->update([
                    'created_at' => $now,
                ]);
        }
    }
}
