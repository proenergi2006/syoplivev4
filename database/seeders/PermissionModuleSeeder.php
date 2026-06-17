<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionModuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $modules = [
            /*
            |--------------------------------------------------------------------------
            | Master Vendor
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'vendor',
                'name' => 'Master Vendor',
                'description' => 'Module pengelolaan data master vendor.',
                'route_prefix' => '/master/vendor',
                'sort_order' => 10,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Requisition
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'purchase_request',
                'name' => 'Purchase Requisition',
                'description' => 'Module pengajuan dan pengelolaan Purchase Requisition.',
                'route_prefix' => '/non_trade/purchase_request',
                'sort_order' => 20,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Order
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'purchase_order',
                'name' => 'Purchase Order',
                'description' => 'Module pembuatan dan pengelolaan Purchase Order.',
                'route_prefix' => '/non_trade/purchase_order',
                'sort_order' => 30,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Goods Receipt
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'goods_receive',
                'name' => 'Goods Receipt',
                'description' => 'Module penerimaan barang berdasarkan Purchase Order.',
                'route_prefix' => '/non_trade/goods_receive',
                'sort_order' => 40,
                'is_active' => true,
            ],
        ];

        foreach ($modules as $module) {
            $existingModule = DB::table('permission_modules')
                ->where('code', $module['code'])
                ->first();

            DB::table('permission_modules')->updateOrInsert(
                [
                    'code' => $module['code'],
                ],
                [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'route_prefix' => $module['route_prefix'],
                    'sort_order' => $module['sort_order'],
                    'is_active' => $module['is_active'],

                    /*
                    |--------------------------------------------------------------------------
                    | Created at tidak berubah ketika seeder dijalankan ulang
                    |--------------------------------------------------------------------------
                    */
                    'created_at' => $existingModule?->created_at
                        ?? $now,

                    'updated_at' => $now,
                ],
            );
        }
    }
}
