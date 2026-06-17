<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 9999);
            $perPage = $perPage > 0 ? $perPage : 9999;

            $query = Permission::query()
                ->with([
                    'permissionModule:id,code,name,description,route_prefix,sort_order,is_active',
                ]);

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        | Mencari dari data permission maupun master module.
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('permissions.module', 'ILIKE', "%{$search}%")
                        ->orWhere('permissions.action', 'ILIKE', "%{$search}%")
                        ->orWhere('permissions.code', 'ILIKE', "%{$search}%")
                        ->orWhere('permissions.name', 'ILIKE', "%{$search}%")
                        ->orWhere('permissions.description', 'ILIKE', "%{$search}%")
                        ->orWhereHas('permissionModule', function ($moduleQuery) use ($search) {
                            $moduleQuery
                                ->where('permission_modules.code', 'ILIKE', "%{$search}%")
                                ->orWhere('permission_modules.name', 'ILIKE', "%{$search}%")
                                ->orWhere('permission_modules.description', 'ILIKE', "%{$search}%")
                                ->orWhere('permission_modules.route_prefix', 'ILIKE', "%{$search}%");
                        });
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Filter module
        |--------------------------------------------------------------------------
        | Value yang dikirim adalah permission_modules.code.
        | Contoh: purchase_request
        |--------------------------------------------------------------------------
        */
            if ($request->filled('module')) {
                $module = trim((string) $request->input('module'));

                $query->where('permissions.module', $module);
            }

            /*
        |--------------------------------------------------------------------------
        | Filter action
        |--------------------------------------------------------------------------
        */
            if ($request->filled('action')) {
                $action = strtolower(
                    trim((string) $request->input('action')),
                );

                $query->whereRaw('LOWER(permissions.action) = ?', [$action]);
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status permission
        |--------------------------------------------------------------------------
        */
            if ($request->filled('is_active')) {
                $isActive = filter_var(
                    $request->input('is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );

                if ($isActive !== null) {
                    $query->where('permissions.is_active', $isActive);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status master module
        |--------------------------------------------------------------------------
        | Optional:
        | module_is_active=true
        |--------------------------------------------------------------------------
        */
            if ($request->filled('module_is_active')) {
                $moduleIsActive = filter_var(
                    $request->input('module_is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );

                if ($moduleIsActive !== null) {
                    $query->whereHas('permissionModule', function ($moduleQuery) use ($moduleIsActive) {
                        $moduleQuery->where(
                            'permission_modules.is_active',
                            $moduleIsActive,
                        );
                    });
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        | Permission diurutkan berdasarkan:
        | 1. sort_order master module
        | 2. nama module
        | 3. urutan action
        | 4. nama permission
        |--------------------------------------------------------------------------
        */
            $query
                ->leftJoin(
                    'permission_modules',
                    'permission_modules.code',
                    '=',
                    'permissions.module',
                )
                ->select('permissions.*')
                ->orderByRaw('COALESCE(permission_modules.sort_order, 999999) ASC')
                ->orderByRaw('COALESCE(permission_modules.name, permissions.module) ASC')
                ->orderByRaw("
                CASE LOWER(permissions.action)
                    WHEN 'view' THEN 1
                    WHEN 'create' THEN 2
                    WHEN 'update' THEN 3
                    WHEN 'delete' THEN 4
                    WHEN 'approve' THEN 5
                    ELSE 99
                END
            ")
                ->orderBy('permissions.name');

            /*
        |--------------------------------------------------------------------------
        | Paginated response
        |--------------------------------------------------------------------------
        */
            /** @var LengthAwarePaginator $paginator */
            $paginator = $query->paginate($perPage);

            $paginator->setCollection(
                $paginator->getCollection()->map(
                    fn(Permission $permission): array => $this->transformPermission($permission),
                ),
            );

            /*
        |--------------------------------------------------------------------------
        | Non-paginated response
        |--------------------------------------------------------------------------
        */
            $data = $query
                ->limit($perPage)
                ->get()
                ->map(
                    fn(Permission $permission) => $this->transformPermission($permission),
                )
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data permission berhasil dimuat.',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Permission] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data permission.',
                'data' => [],
            ], 500);
        }
    }

    public function show(Permission $permission): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail permission berhasil dimuat.',
            'data' => $permission,
        ]);
    }

    private function transformPermission(Permission $permission): array
    {
        $module = $permission->permissionModule;

        return [
            'id' => $permission->id,

            /*
        |--------------------------------------------------------------------------
        | Identitas permission
        |--------------------------------------------------------------------------
        */
            'module' => $permission->module,
            'action' => $permission->action,
            'code' => $permission->code,
            'name' => $permission->name,
            'description' => $permission->description,
            'is_active' => (bool) $permission->is_active,

            /*
        |--------------------------------------------------------------------------
        | Master module
        |--------------------------------------------------------------------------
        */
            'module_data' => $module
                ? [
                    'id' => $module->id,
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'route_prefix' => $module->route_prefix,
                    'sort_order' => (int) $module->sort_order,
                    'is_active' => (bool) $module->is_active,
                ]
                : null,

            /*
        |--------------------------------------------------------------------------
        | Field praktis untuk frontend
        |--------------------------------------------------------------------------
        */
            'module_name' => $module?->name
                ?? $this->formatModuleName($permission->module),

            'module_route_prefix' => $module?->route_prefix,

            'created_at' => $permission->created_at,
            'updated_at' => $permission->updated_at,
        ];
    }

    private function formatModuleName(?string $module): string
    {
        $module = trim((string) $module);

        if ($module === '') {
            return '-';
        }

        return collect(explode('_', $module))
            ->filter()
            ->map(
                fn(string $word) => ucfirst(strtolower($word)),
            )
            ->implode(' ');
    }
}
