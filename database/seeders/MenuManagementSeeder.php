<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuManagementSeeder extends Seeder
{
    public function run(): void
    {
        if (
            !Schema::hasTable('permission_modules')
            || !Schema::hasTable('permissions')
            || !Schema::hasTable('menus')
            || !Schema::hasTable('roles')
            || !Schema::hasTable('role_permissions')
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Permission Module
        |--------------------------------------------------------------------------
        */
        $permissionModule = [
            'code' => 'menu_management',
            'name' => 'Menu Management',
            'description' => 'Pengelolaan menu aplikasi.',
            'route_prefix' => 'management.menu',
            'sort_order' => 1,
            'is_active' => true,
        ];

        $existingPermissionModule = DB::table('permission_modules')
            ->where('code', $permissionModule['code'])
            ->first();

        if ($existingPermissionModule) {
            DB::table('permission_modules')
                ->where('id', $existingPermissionModule->id)
                ->update([
                    'name' => $permissionModule['name'],
                    'description' => $permissionModule['description'],
                    'route_prefix' => $permissionModule['route_prefix'],
                    'sort_order' => $permissionModule['sort_order'],
                    'is_active' => $permissionModule['is_active'],
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('permission_modules')->insert([
                'code' => $permissionModule['code'],
                'name' => $permissionModule['name'],
                'description' => $permissionModule['description'],
                'route_prefix' => $permissionModule['route_prefix'],
                'sort_order' => $permissionModule['sort_order'],
                'is_active' => $permissionModule['is_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Permissions
        |--------------------------------------------------------------------------
        */
        $permissions = [
            [
                'module' => 'auth_menu',
                'action' => 'view',
                'code' => 'auth_menu.view',
                'name' => 'Lihat list menu',
                'description' => 'Melihat daftar menu.',
            ],
            [
                'module' => 'auth_menu',
                'action' => 'create',
                'code' => 'auth_menu.create',
                'name' => 'Create menu baru',
                'description' => 'Membuat menu baru.',
            ],
            [
                'module' => 'auth_menu',
                'action' => 'update',
                'code' => 'auth_menu.update',
                'name' => 'Update menu',
                'description' => 'Mengubah menu.',
            ],
            [
                'module' => 'auth_menu',
                'action' => 'delete',
                'code' => 'auth_menu.delete',
                'name' => 'Delete menu',
                'description' => 'Menghapus menu.',
            ],
        ];

        foreach ($permissions as $permission) {
            $existingPermission = DB::table('permissions')
                ->where('code', $permission['code'])
                ->first();

            if ($existingPermission) {
                DB::table('permissions')
                    ->where('id', $existingPermission->id)
                    ->update([
                        'module' => $permission['module'],
                        'action' => $permission['action'],
                        'name' => $permission['name'],
                        'description' => $permission['description'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('permissions')->insert([
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                    'code' => $permission['code'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Parent Menu: Management
        |--------------------------------------------------------------------------
        */
        $managementMenu = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Management')
            ->first();

        if ($managementMenu) {
            DB::table('menus')
                ->where('id', $managementMenu->id)
                ->update([
                    'path' => null,
                    'route_name' => null,
                    'icon' => 'tabler-settings',
                    'order_no' => 1,
                    'permission_key' => null,
                    'is_active' => true,
                    'show_in_sidebar' => true,
                    'updated_at' => now(),
                ]);

            $managementMenuId = $managementMenu->id;
        } else {
            $managementMenuId = DB::table('menus')->insertGetId([
                'parent_id' => null,
                'name' => 'Management',
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-settings',
                'order_no' => 1,
                'permission_key' => null,
                'is_active' => true,
                'show_in_sidebar' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Child Menu: Menu Management
        |--------------------------------------------------------------------------
        */
        $menuManagement = DB::table('menus')
            ->where('parent_id', $managementMenuId)
            ->where('name', 'Menu Management')
            ->first();

        if ($menuManagement) {
            DB::table('menus')
                ->where('id', $menuManagement->id)
                ->update([
                    'path' => '/master/menus',
                    'route_name' => 'master-menus',
                    'icon' => 'tabler-menu-2',
                    'order_no' => 1,
                    'permission_key' => 'menu_management.view',
                    'is_active' => true,
                    'show_in_sidebar' => true,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('menus')->insert([
                'parent_id' => $managementMenuId,
                'name' => 'Menu Management',
                'path' => '/master/menus',
                'route_name' => 'master-menus',
                'icon' => 'tabler-menu-2',
                'order_no' => 1,
                'permission_key' => 'menu_management.view',
                'is_active' => true,
                'show_in_sidebar' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Assign Permissions to Super Admin
        |--------------------------------------------------------------------------
        */
        $superAdminRole = DB::table('roles')
            ->where(function ($query) {
                $query
                    ->whereRaw('LOWER(TRIM(nama)) = ?', ['super admin'])
                    ->orWhereRaw('LOWER(TRIM(kode)) = ?', ['super_admin'])
                    ->orWhereRaw('LOWER(TRIM(kode)) = ?', ['super-admin'])
                    ->orWhereRaw('LOWER(TRIM(kode)) = ?', ['superadmin']);
            })
            ->first();

        if (!$superAdminRole) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn(
                'code',
                collect($permissions)
                    ->pluck('code')
                    ->values()
                    ->all(),
            )
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            $existingRolePermission = DB::table('role_permissions')
                ->where('role_id', $superAdminRole->id)
                ->where('permission_id', $permissionId)
                ->first();

            if ($existingRolePermission) {
                DB::table('role_permissions')
                    ->where('id', $existingRolePermission->id)
                    ->update([
                        'scope' => 'ALL',
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('role_permissions')->insert([
                    'role_id' => $superAdminRole->id,
                    'permission_id' => $permissionId,
                    'scope' => 'ALL',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
