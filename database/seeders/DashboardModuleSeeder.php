<?php

namespace Database\Seeders;

use App\Models\Dashboard\DashboardModule;
use App\Models\Dashboard\DashboardModuleGroup;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DashboardModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedPermissions();
            $this->seedDashboardModules();
        });
    }

    private function seedPermissions(): void
    {
        $permissions = [
            [
                'module' => 'dashboard',
                'action' => 'view',
                'code' => 'dashboard.view',
                'name' => 'Lihat Dashboard',
                'description' => 'Mengizinkan pengguna membuka halaman utama dashboard.',
                'is_active' => true,
            ],
            [
                'module' => 'dashboard',
                'action' => 'view',
                'code' => 'dashboard.pr.view',
                'name' => 'Lihat Dashboard Purchase Requisition',
                'description' => 'Mengizinkan pengguna melihat dashboard Purchase Requisition.',
                'is_active' => true,
            ],
            [
                'module' => 'dashboard',
                'action' => 'view',
                'code' => 'dashboard.po.view',
                'name' => 'Lihat Dashboard Purchase Order',
                'description' => 'Mengizinkan pengguna melihat dashboard Purchase Order.',
                'is_active' => true,
            ],
            [
                'module' => 'dashboard',
                'action' => 'view',
                'code' => 'dashboard.gr.view',
                'name' => 'Lihat Dashboard Goods Receipt',
                'description' => 'Mengizinkan pengguna melihat dashboard Goods Receipt.',
                'is_active' => true,
            ],
            [
                'module' => 'dashboard',
                'action' => 'view',
                'code' => 'dashboard.goods-return.view',
                'name' => 'Lihat Dashboard Goods Return',
                'description' => 'Mengizinkan pengguna melihat dashboard Goods Return.',
                'is_active' => true,
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                [
                    'code' => $permission['code'],
                ],
                [
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'is_active' => $permission['is_active'],
                ],
            );
        }
    }

    private function seedDashboardModules(): void
    {
        $nonTradeGroup = DashboardModuleGroup::query()->updateOrCreate(
            [
                'code' => 'NON_TRADE',
            ],
            [
                'name' => 'Non Trade',
                'icon' => 'mdi-cart-outline',
                'sort_order' => 10,
                'is_active' => true,
            ],
        );

        $modules = [
            [
                'code' => 'PURCHASE_REQUISITION',
                'title' => 'Purchase Requisition',
                'short_title' => 'PR',
                'description' => 'Pantau permintaan pembelian, proses persetujuan, dan kebutuhan setiap departemen.',
                'icon' => 'mdi-file-document-edit-outline',
                'color' => 'primary',
                'route_path' => '/dashboards/purchase-requisition',
                'permission_name' => 'dashboard.pr.view',
                'features' => [
                    'Jumlah PR',
                    'Status approval',
                    'Nilai permintaan',
                ],
                'is_active' => true,
                'is_available' => false,
                'sort_order' => 10,
            ],
            [
                'code' => 'PURCHASE_ORDER',
                'title' => 'Purchase Order',
                'short_title' => 'PO',
                'description' => 'Pantau Purchase Order, nilai pembelian, status persetujuan, dan performa vendor.',
                'icon' => 'mdi-file-sign',
                'color' => 'success',
                'route_path' => '/dashboards/purchase-order',
                'permission_name' => 'dashboard.po.view',
                'features' => [
                    'Jumlah PO',
                    'Nilai PO',
                    'Status approval',
                ],
                'is_active' => true,
                'is_available' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'GOODS_RECEIPT',
                'title' => 'Goods Receipt',
                'short_title' => 'GR',
                'description' => 'Pantau penerimaan barang, outstanding penerimaan, dan progres setiap Purchase Order.',
                'icon' => 'mdi-package-variant-closed-check',
                'color' => 'info',
                'route_path' => '/dashboards/goods-receipt',
                'permission_name' => 'dashboard.gr.view',
                'features' => [
                    'Barang diterima',
                    'Outstanding PO',
                    'Status receipt',
                ],
                'is_active' => true,
                'is_available' => false,
                'sort_order' => 30,
            ],
            [
                'code' => 'GOODS_RETURN',
                'title' => 'Goods Return',
                'short_title' => 'GR Return',
                'description' => 'Pantau pengembalian barang, refund, replacement, dan status penyelesaiannya.',
                'icon' => 'mdi-package-variant-closed-minus',
                'color' => 'warning',
                'route_path' => '/dashboards/goods-return',
                'permission_name' => 'dashboard.goods-return.view',
                'features' => [
                    'Jumlah return',
                    'Refund',
                    'Replacement',
                ],
                'is_active' => true,
                'is_available' => false,
                'sort_order' => 40,
            ],
        ];

        foreach ($modules as $module) {
            DashboardModule::query()->updateOrCreate(
                [
                    'code' => $module['code'],
                ],
                [
                    'dashboard_module_group_id' => $nonTradeGroup->id,
                    'title' => $module['title'],
                    'short_title' => $module['short_title'],
                    'description' => $module['description'],
                    'icon' => $module['icon'],
                    'color' => $module['color'],
                    'route_path' => $module['route_path'],
                    'permission_name' => $module['permission_name'],
                    'features' => $module['features'],
                    'is_active' => $module['is_active'],
                    'is_available' => $module['is_available'],
                    'sort_order' => $module['sort_order'],
                ],
            );
        }
    }
}
