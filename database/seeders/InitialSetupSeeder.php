<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            /*
            |--------------------------------------------------------------------------
            | 1) Wilayah
            |--------------------------------------------------------------------------
            */
            $wilayahId = $this->upsertAndGetId(
                'wilayah',
                ['kode' => 'WIL-01'],
                [
                    'nama' => 'JABODETABEK',
                    'is_active' => true,
                    'updated_at' => $now,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | 2) Cabang
            |--------------------------------------------------------------------------
            */
            // $cabangId = $this->upsertAndGetId(
            //     'cabang',
            //     ['kode' => 'CBG-01'],
            //     [
            //         'wilayah_id' => $wilayahId,
            //         'nama' => 'JAKARTA',
            //         'alamat' => 'Head Office',
            //         'is_active' => true,
            //         'updated_at' => $now,
            //     ]
            // );

            /*
            |--------------------------------------------------------------------------
            | 3) Departemen
            |--------------------------------------------------------------------------
            */
            // $deptIT = $this->upsertAndGetId(
            //     'departemen',
            //     ['kode' => 'DEP-IT'],
            //     [
            //         'nama' => 'IT',
            //         'is_active' => true,
            //         'updated_at' => $now,
            //     ]
            // );

            /*
            |--------------------------------------------------------------------------
            | 4) Roles
            |--------------------------------------------------------------------------
            */
            $roles = [
                ['kode' => 'ADMIN', 'nama' => 'Administrator'],
                ['kode' => 'BM', 'nama' => 'Branch Manager'],
                ['kode' => 'OM', 'nama' => 'Operation Manager'],
                ['kode' => 'PROC', 'nama' => 'Procurement'],
            ];

            $roleIds = [];
            foreach ($roles as $role) {
                $roleIds[$role['kode']] = $this->upsertAndGetId(
                    'roles',
                    ['kode' => $role['kode']],
                    [
                        'nama' => $role['nama'],
                        'is_active' => true,
                        'updated_at' => $now,
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 5) Menus
            |--------------------------------------------------------------------------
            */

            // Top level
            $dashboardMenuId = $this->upsertMenu(
                ['name' => 'Dashboard', 'parent_id' => null],
                [
                    'path' => '/dashboard',
                    'route_name' => 'dashboard',
                    'icon' => 'tabler-smart-home',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $masterMenuId = $this->upsertMenu(
                ['name' => 'Master', 'parent_id' => null],
                [
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-settings',
                    'order_no' => 2,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $purchaseMenuId = $this->upsertMenu(
                ['name' => 'Non Trade', 'parent_id' => null],
                [
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-shopping-cart',
                    'order_no' => 3,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $authMenuId = $this->upsertMenu(
                ['name' => 'Auth', 'parent_id' => null],
                [
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-lock',
                    'order_no' => 4,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            // Purchase children
            $purchaseRequestMenuId = $this->upsertMenu(
                ['name' => 'Purchase Request', 'parent_id' => $purchaseMenuId],
                [
                    'path' => '/purchase_non_trading/purchase_request',
                    'route_name' => 'purchase-request',
                    'icon' => 'tabler-file-invoice',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $purchaseOrderMenuId = $this->upsertMenu(
                ['name' => 'Purchase Order', 'parent_id' => $purchaseMenuId],
                [
                    'path' => '/purchase_non_trading/purchase_order',
                    'route_name' => 'purchase-order',
                    'icon' => 'tabler-file-invoice',
                    'order_no' => 2,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            // Master children
            $regionalMenuId = $this->upsertMenu(
                ['name' => 'Regional', 'parent_id' => $masterMenuId],
                [
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-map-2',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $produkId = $this->upsertMenu(
                ['name' => 'Produk', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/produk',
                    'route_name' => 'master-produk',
                    'icon' => 'tabler-archive',
                    'order_no' => 4,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $pbbkbId = $this->upsertMenu(
                ['name' => 'PBBKB', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/pbbkb',
                    'route_name' => 'master-pbbkb',
                    'icon' => 'tabler-article',
                    'order_no' => 5,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $fleetMenuId = $this->upsertMenu(
                ['name' => 'Fleet Management', 'parent_id' => $masterMenuId],
                [
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-truck',
                    'order_no' => 6,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $cabangMenuId = $this->upsertMenu(
                ['name' => 'Cabang', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/cabang',
                    'route_name' => 'master-cabang',
                    'icon' => 'tabler-building',
                    'order_no' => 8,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $deptMenuId = $this->upsertMenu(
                ['name' => 'Departemen', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/departemen',
                    'route_name' => 'master-departemen',
                    'icon' => 'tabler-users',
                    'order_no' => 9,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $vendorMenuId = $this->upsertMenu(
                ['name' => 'Vendor', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/vendor',
                    'route_name' => 'master-vendor',
                    'icon' => 'tabler-building-store',
                    'order_no' => 10,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $terminalMenuId = $this->upsertMenu(
                ['name' => 'Terminal', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/terminal',
                    'route_name' => 'master-terminal',
                    'icon' => 'tabler-gas-station',
                    'order_no' => 11,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $customerMenuId = $this->upsertMenu(
                ['name' => 'Customer', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/customer',
                    'route_name' => 'master-customer',
                    'icon' => 'tabler-users',
                    'order_no' => 12,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $volumeId = $this->upsertMenu(
                ['name' => 'Volume', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/volume',
                    'route_name' => 'master-volume',
                    'icon' => 'tabler-cylinder',
                    'order_no' => 16,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $wilAngkutId = $this->upsertMenu(
                ['name' => 'Wilayah Angkut', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/wilayah-angkut',
                    'route_name' => 'master-wilayah-angkut',
                    'icon' => 'tabler-location-pin',
                    'order_no' => 17,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $hargaJualId = $this->upsertMenu(
                ['name' => 'Harga Jual', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/harga-jual',
                    'route_name' => 'master-harga-jual',
                    'icon' => 'tabler-receipt-dollar',
                    'order_no' => 18,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $hargaPertaminaId = $this->upsertMenu(
                ['name' => 'Harga Dasar Pertamina', 'parent_id' => $masterMenuId],
                [
                    'path' => '/master/harga-pertamina',
                    'route_name' => 'master-harga-pertamina',
                    'icon' => 'tabler-file-dollar',
                    'order_no' => 19,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            // Fleet children
            $transportirMenuId = $this->upsertMenu(
                ['name' => 'Transportir', 'parent_id' => $fleetMenuId],
                [
                    'path' => '/master/transportir',
                    'route_name' => 'master-transportir',
                    'icon' => 'tabler-building-store',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $sopirMenuId = $this->upsertMenu(
                ['name' => 'Sopir', 'parent_id' => $fleetMenuId],
                [
                    'path' => '/master/sopir',
                    'route_name' => 'master-sopir',
                    'icon' => 'tabler-user-circle',
                    'order_no' => 2,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $mobilMenuId = $this->upsertMenu(
                ['name' => 'Mobil', 'parent_id' => $fleetMenuId],
                [
                    'path' => '/master/mobil',
                    'route_name' => 'master-mobil',
                    'icon' => 'tabler-car',
                    'order_no' => 3,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $ongkosAngkutMenuId = $this->upsertMenu(
                ['name' => 'Ongkos Angkut', 'parent_id' => $fleetMenuId],
                [
                    'path' => '/master/ongkos-angkut',
                    'route_name' => 'master-ongkos-angkut',
                    'icon' => 'tabler-currency-rupiah',
                    'order_no' => 4,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            // Regional children
            $provinsiMenuId = $this->upsertMenu(
                ['name' => 'Provinsi', 'parent_id' => $regionalMenuId],
                [
                    'path' => '/master/provinsi',
                    'route_name' => 'master-provinsi',
                    'icon' => 'tabler-map-pin',
                    'order_no' => 2,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $kabMenuId = $this->upsertMenu(
                ['name' => 'Kabupaten', 'parent_id' => $regionalMenuId],
                [
                    'path' => '/master/kabupaten',
                    'route_name' => 'master-kabupaten',
                    'icon' => 'tabler-map-pin',
                    'order_no' => 3,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $wilayahMenuId = $this->upsertMenu(
                ['name' => 'Wilayah', 'parent_id' => $regionalMenuId],
                [
                    'path' => '/master/wilayah',
                    'route_name' => 'master-wilayah',
                    'icon' => 'tabler-map',
                    'order_no' => 4,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $areaMenuId = $this->upsertMenu(
                ['name' => 'Area', 'parent_id' => $regionalMenuId],
                [
                    'path' => '/master/area',
                    'route_name' => 'master-area',
                    'icon' => 'tabler-map-pin',
                    'order_no' => 5,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            // Auth children
            $userMenuId = $this->upsertMenu(
                ['name' => 'Users', 'parent_id' => $authMenuId],
                [
                    'path' => '/master/users',
                    'route_name' => 'master-users',
                    'icon' => 'tabler-user',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $roleMenuId = $this->upsertMenu(
                ['name' => 'Roles', 'parent_id' => $authMenuId],
                [
                    'path' => '/master/roles',
                    'route_name' => 'master-roles',
                    'icon' => 'tabler-shield',
                    'order_no' => 2,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            $roleMenuSettingId = $this->upsertMenu(
                ['name' => 'Role Menu', 'parent_id' => $authMenuId],
                [
                    'path' => '/master/role-menus',
                    'route_name' => 'master-role-menus',
                    'icon' => 'tabler-lock',
                    'order_no' => 3,
                    'permission_key' => null,
                    'is_active' => true,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | 6) Admin User
            |--------------------------------------------------------------------------
            */
            $adminUserId = $this->upsertAndGetId(
                'users',
                ['email' => 'admin@syop.local'],
                [
                    'name' => 'Admin SYOP',
                    'password' => Hash::make('admin123'),
                    'cabang_id' => 2,
                    'departemen_id' => 1,
                    'is_active' => true,
                    'updated_at' => $now,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | 7) Attach Admin Role
            |--------------------------------------------------------------------------
            */
            $this->upsertPivot('user_roles', [
                'user_id' => $adminUserId,
                'role_id' => $roleIds['ADMIN'],
            ]);

            /*
            |--------------------------------------------------------------------------
            | 8) Attach menus to ADMIN role
            |--------------------------------------------------------------------------
            */
            $menuIds = [
                $dashboardMenuId,
                $masterMenuId,
                $purchaseMenuId,
                $purchaseRequestMenuId,
                $purchaseOrderMenuId,
                $regionalMenuId,
                $provinsiMenuId,
                $kabMenuId,
                $wilayahMenuId,
                $areaMenuId,
                $fleetMenuId,
                $transportirMenuId,
                $sopirMenuId,
                $mobilMenuId,
                $ongkosAngkutMenuId,
                $cabangMenuId,
                $deptMenuId,
                $vendorMenuId,
                $terminalMenuId,
                $customerMenuId,
                $authMenuId,
                $userMenuId,
                $roleMenuId,
                $roleMenuSettingId,
                $produkId,
                $pbbkbId,
                $volumeId,
                $wilAngkutId,
                $hargaJualId,
                $hargaPertaminaId,
            ];

            foreach ($menuIds as $menuId) {
                $this->upsertPivot('role_menus', [
                    'role_id' => $roleIds['ADMIN'],
                    'menu_id' => $menuId,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 9) Multi-cabang user
            |--------------------------------------------------------------------------
            */
            $this->upsertPivot('user_cabang', [
                'user_id' => $adminUserId,
                'cabang_id' => 2,
            ]);
        });
    }

    private function upsertAndGetId(string $table, array $uniqueBy, array $values): int
    {
        $existing = DB::table($table)->where($uniqueBy)->first();

        if ($existing) {
            DB::table($table)
                ->where($uniqueBy)
                ->update(array_merge($values, [
                    'updated_at' => now(),
                ]));

            return (int) $existing->id;
        }

        return (int) DB::table($table)->insertGetId(array_merge(
            $uniqueBy,
            $values,
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));
    }

    private function upsertMenu(array $uniqueBy, array $values): int
    {
        return $this->upsertAndGetId('menus', $uniqueBy, $values);
    }

    private function upsertPivot(string $table, array $uniqueBy): void
    {
        if (!DB::table($table)->where($uniqueBy)->exists()) {
            DB::table($table)->insert($uniqueBy);
        }
    }
}
