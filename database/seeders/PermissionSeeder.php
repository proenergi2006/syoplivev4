<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $permissions = [
            /*
            |--------------------------------------------------------------------------
            | Master Vendor
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'vendor',
                'action' => 'view',
                'code' => 'vendor.view',
                'name' => 'View Master Vendor',
                'description' => 'Melihat data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'create',
                'code' => 'vendor.create',
                'name' => 'Create Master Vendor',
                'description' => 'Membuat data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'update',
                'code' => 'vendor.update',
                'name' => 'Update Master Vendor',
                'description' => 'Mengubah data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'submit',
                'code' => 'vendor.submit',
                'name' => 'Submit Master Vendor',
                'description' => 'Submit Master Vendor ke proses approval',
            ],
            [
                'module' => 'vendor',
                'action' => 'delete',
                'code' => 'vendor.delete',
                'name' => 'Delete Master Vendor',
                'description' => 'Menghapus atau menonaktifkan data master vendor.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Requisition
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'purchase_request',
                'action' => 'view',
                'code' => 'purchase_request.view',
                'name' => 'View Purchase Requisition',
                'description' => 'Melihat data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'create',
                'code' => 'purchase_request.create',
                'name' => 'Create Purchase Requisition',
                'description' => 'Membuat data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'update',
                'code' => 'purchase_request.update',
                'name' => 'Update Purchase Requisition',
                'description' => 'Mengubah data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'delete',
                'code' => 'purchase_request.delete',
                'name' => 'Delete Purchase Requisition',
                'description' => 'Menghapus atau membatalkan Purchase Requisition.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Order
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'purchase_order',
                'action' => 'view',
                'code' => 'purchase_order.view',
                'name' => 'View Purchase Order',
                'description' => 'Melihat data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'create',
                'code' => 'purchase_order.create',
                'name' => 'Create Purchase Order',
                'description' => 'Membuat data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'update',
                'code' => 'purchase_order.update',
                'name' => 'Update Purchase Order',
                'description' => 'Mengubah data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'delete',
                'code' => 'purchase_order.delete',
                'name' => 'Delete Purchase Order',
                'description' => 'Menghapus atau membatalkan Purchase Order.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Goods Receipt
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'goods_receive',
                'action' => 'view',
                'code' => 'goods_receive.view',
                'name' => 'View Goods Receipt',
                'description' => 'Melihat data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'create',
                'code' => 'goods_receive.create',
                'name' => 'Create Goods Receipt',
                'description' => 'Membuat data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'update',
                'code' => 'goods_receive.update',
                'name' => 'Update Goods Receipt',
                'description' => 'Mengubah data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'delete',
                'code' => 'goods_receive.delete',
                'name' => 'Delete Goods Receipt',
                'description' => 'Menghapus atau membatalkan Goods Receipt.',
            ],
        ];

        foreach ($permissions as $permission) {
            $existingPermission = DB::table('permissions')
                ->where('code', $permission['code'])
                ->first();

            DB::table('permissions')->updateOrInsert(
                [
                    'code' => $permission['code'],
                ],
                [
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'is_active' => true,

                    /*
                    |--------------------------------------------------------------------------
                    | Created at tidak berubah ketika seeder dijalankan ulang
                    |--------------------------------------------------------------------------
                    */
                    'created_at' => $existingPermission?->created_at
                        ?? $now,

                    'updated_at' => $now,
                ],
            );
        }
    }
}
