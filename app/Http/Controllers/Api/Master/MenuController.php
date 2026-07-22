<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

class MenuController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Sidebar Menu User Login
    |--------------------------------------------------------------------------
    */
    public function navigation(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([]);
        }

        $roleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return response()->json([]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil menu yang:
        | - dimiliki role user
        | - aktif
        | - tampil di sidebar
        |--------------------------------------------------------------------------
        */
        $rows = DB::table('menus as m')
            ->join('role_menus as rm', 'rm.menu_id', '=', 'm.id')
            ->whereIn('rm.role_id', $roleIds)
            ->where('m.is_active', true)
            ->where(function ($query) {
                $query
                    ->where('m.show_in_sidebar', true)
                    ->orWhereNull('m.show_in_sidebar');
            })
            ->select(
                'm.id',
                'm.parent_id',
                'm.name',
                'm.path',
                'm.route_name',
                'm.icon',
                'm.order_no'
            )
            ->orderBy('m.parent_id')
            ->orderBy('m.order_no')
            ->orderBy('m.name')
            ->get()
            ->unique('id')
            ->values();

        if ($rows->isEmpty()) {
            return response()->json([]);
        }

        /*
        |--------------------------------------------------------------------------
        | Pastikan parent dari menu yang tampil ikut terbawa
        |--------------------------------------------------------------------------
        | Ini jaga-jaga kalau role punya child menu tapi parent-nya belum ikut
        | tercatat di role_menus.
        |--------------------------------------------------------------------------
        */
        $rows = $this->appendMissingActiveParents($rows);

        $byId = [];

        foreach ($rows as $row) {
            $to = null;

            if (!empty($row->path)) {
                $to = [
                    'path' => $row->path,
                ];
            } elseif (!empty($row->route_name)) {
                $to = [
                    'name' => $row->route_name,
                ];
            }

            $byId[(int) $row->id] = [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id !== null
                    ? (int) $row->parent_id
                    : null,
                'title' => $row->name,
                'icon' => !empty($row->icon)
                    ? ['icon' => $row->icon]
                    : null,
                'to' => $to,
                'order_no' => (int) ($row->order_no ?? 0),
                'children' => [],
            ];
        }

        $tree = [];

        foreach ($byId as $id => &$node) {
            $parentId = $node['parent_id'];

            if (
                $parentId !== null
                && isset($byId[$parentId])
            ) {
                $byId[$parentId]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }

        unset($node);

        $result = $this->cleanMaterioMenu($tree);

        /*
        |--------------------------------------------------------------------------
        | Badge sidebar
        |--------------------------------------------------------------------------
        | Badge dihitung setelah menu selesai dibentuk.
        | Jadi tidak akan error undefined variable.
        |--------------------------------------------------------------------------
        */
        $badges = $this->buildApprovalBadges($user);

        $result = $this->injectNavigationBadges($result, $badges);

        return response()->json($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Management Index
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.view',
            message: 'Anda tidak memiliki akses melihat Menu Management.',
        );

        $menus = Menu::query()
            ->orderByRaw('parent_id NULLS FIRST')
            ->orderBy('order_no')
            ->orderBy('name')
            ->get([
                'id',
                'parent_id',
                'name',
                'path',
                'route_name',
                'icon',
                'order_no',
                'permission_key',
                'show_in_sidebar',
                'is_active',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Data menu berhasil dimuat.',
            'data' => [
                'flat' => $menus->values(),
                'tree' => $this->buildTree($menus),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.create',
            message: 'Anda tidak memiliki akses membuat menu.',
        );

        $validated = $this->validatePayload($request);

        $nextIsActive = (bool) ($validated['is_active'] ?? true);

        if ($nextIsActive) {
            $this->validateParentChainIsActive($validated['parent_id'] ?? null);
        }

        $this->validateOrderNoIsUnique(
            null,
            $validated['parent_id'] ?? null,
            (int) ($validated['order_no'] ?? 0),
        );

        $menu = DB::transaction(function () use ($validated) {
            $menu = Menu::create([
                'parent_id' => $validated['parent_id'] ?? null,
                'name' => $validated['name'],
                'path' => $validated['path'] ?? null,
                'route_name' => $validated['route_name'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'order_no' => (int) ($validated['order_no'] ?? 0),
                'permission_key' => null,
                'show_in_sidebar' => (bool) ($validated['show_in_sidebar'] ?? true),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Auto assign ke role SA
            |--------------------------------------------------------------------------
            */
            $superAdminRoleId = DB::table('roles')
                ->where('kode', 'SA')
                ->value('id');

            if ($superAdminRoleId) {
                DB::table('role_menus')->updateOrInsert([
                    'role_id' => $superAdminRoleId,
                    'menu_id' => $menu->id,
                ], []);
            }

            return $menu;
        });

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil dibuat.',
            'data' => $menu,
        ], 201);
    }

    public function show(Request $request, Menu $menu): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.view',
            message: 'Anda tidak memiliki akses melihat detail menu.',
        );

        return response()->json([
            'success' => true,
            'message' => 'Detail menu berhasil dimuat.',
            'data' => $menu,
        ]);
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.update',
            message: 'Anda tidak memiliki akses memperbarui menu.',
        );

        $validated = $this->validatePayload($request, $menu);

        $this->validateOrderNoIsUnique(
            (int) $menu->id,
            $validated['parent_id'] ?? null,
            (int) ($validated['order_no'] ?? 0),
        );

        $this->validateParentIsNotDescendant(
            (int) $menu->id,
            $validated['parent_id'] ?? null,
        );

        $nextIsActive = (bool) ($validated['is_active'] ?? true);
        $nextParentId = $validated['parent_id'] ?? null;

        if (!$nextIsActive && $menu->is_active) {
            $this->validateCanDeactivateMenu($menu);
        }

        if ($nextIsActive) {
            $this->validateParentChainIsActive($nextParentId);
        }

        $menu->update([
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'path' => $validated['path'] ?? null,
            'route_name' => $validated['route_name'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'order_no' => (int) ($validated['order_no'] ?? 0),
            'permission_key' => null,
            'show_in_sidebar' => (bool) ($validated['show_in_sidebar'] ?? true),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil diperbarui.',
            'data' => $menu->fresh(),
        ]);
    }

    public function toggleActive(Request $request, Menu $menu): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.update',
            message: 'Anda tidak memiliki akses mengubah status menu.',
        );

        $nextStatus = !$menu->is_active;

        /*
        |--------------------------------------------------------------------------
        | Jika mau dinonaktifkan, pastikan tidak punya child aktif.
        |--------------------------------------------------------------------------
        */
        if (!$nextStatus) {
            $this->validateCanDeactivateMenu($menu);
        }

        /*
        |--------------------------------------------------------------------------
        | Jika mau diaktifkan, pastikan parent/ancestor aktif.
        |--------------------------------------------------------------------------
        */
        if ($nextStatus) {
            $this->validateParentChainIsActive($menu->parent_id);
        }

        $menu->update([
            'is_active' => $nextStatus,
        ]);

        return response()->json([
            'success' => true,
            'message' => $menu->is_active
                ? 'Menu berhasil diaktifkan.'
                : 'Menu berhasil dinonaktifkan.',
            'data' => $menu->fresh(),
        ]);
    }

    public function destroy(Request $request, Menu $menu): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.delete',
            message: 'Anda tidak memiliki akses menghapus menu.',
        );

        $hasChildren = Menu::query()
            ->where('parent_id', $menu->id)
            ->exists();

        if ($hasChildren) {
            throw ValidationException::withMessages([
                'menu' => [
                    'Menu masih memiliki child. Pindahkan atau nonaktifkan child terlebih dahulu.',
                ],
            ]);
        }

        $usedByRoles = DB::table('role_menus')
            ->where('menu_id', $menu->id)
            ->exists();

        if ($usedByRoles) {
            $menu->update([
                'is_active' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Menu sudah digunakan role, sehingga menu dinonaktifkan bukan dihapus.',
                'data' => $menu->fresh(),
            ]);
        }

        $menu->delete();

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil dihapus.',
        ]);
    }

    private function ensurePermission(
        Request $request,
        string $permission,
        string $message,
    ): void {
        $user = $request->user();

        if (
            !$user
            || !$user->hasPermission($permission)
        ) {
            abort(403, $message);
        }
    }

    private function validatePayload(
        Request $request,
        ?Menu $menu = null,
    ): array {
        $validated = $request->validate([
            'parent_id' => [
                'nullable',
                'integer',
                'exists:menus,id',
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'path' => [
                'nullable',
                'string',
                'max:255',
            ],

            'route_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'icon' => [
                'nullable',
                'string',
                'max:150',
            ],

            'order_no' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'permission_key' => [
                'nullable',
                'string',
                'max:150',
            ],

            'show_in_sidebar' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $path = trim((string) ($validated['path'] ?? ''));
        $routeName = trim((string) ($validated['route_name'] ?? ''));
        $showInSidebar = (bool) ($validated['show_in_sidebar'] ?? true);

        if ($path === '') {
            $validated['path'] = null;
        }

        if ($routeName === '') {
            $validated['route_name'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Hidden page wajib punya path
        |--------------------------------------------------------------------------
        */
        if (!$showInSidebar && empty($validated['path'])) {
            throw ValidationException::withMessages([
                'path' => [
                    'Hidden page wajib memiliki path.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Unique path jika path diisi
        |--------------------------------------------------------------------------
        */
        if (!empty($validated['path'])) {
            $exists = Menu::query()
                ->where('path', $validated['path'])
                ->when($menu, function ($query) use ($menu) {
                    $query->where('id', '!=', $menu->id);
                })
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'path' => [
                        'Path sudah digunakan menu lain.',
                    ],
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Unique route_name jika route_name diisi
        |--------------------------------------------------------------------------
        */
        if (!empty($validated['route_name'])) {
            $exists = Menu::query()
                ->where('route_name', $validated['route_name'])
                ->when($menu, function ($query) use ($menu) {
                    $query->where('id', '!=', $menu->id);
                })
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'route_name' => [
                        'Route name sudah digunakan menu lain.',
                    ],
                ]);
            }
        }

        return $validated;
    }

    private function validateParentIsNotDescendant(
        ?int $menuId,
        ?int $parentId,
    ): void {
        if (!$menuId || !$parentId) {
            return;
        }

        if ((int) $menuId === (int) $parentId) {
            throw ValidationException::withMessages([
                'parent_id' => [
                    'Menu tidak boleh menjadi parent untuk dirinya sendiri.',
                ],
            ]);
        }

        $currentParentId = $parentId;

        while ($currentParentId) {
            if ((int) $currentParentId === (int) $menuId) {
                throw ValidationException::withMessages([
                    'parent_id' => [
                        'Menu tidak boleh dipindahkan ke child turunannya sendiri.',
                    ],
                ]);
            }

            $currentParentId = Menu::query()
                ->where('id', $currentParentId)
                ->value('parent_id');
        }
    }

    private function appendMissingActiveParents($rows)
    {
        $allRows = collect($rows);
        $loadedIds = $allRows
            ->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->unique()
            ->values();

        $parentIds = $allRows
            ->pluck('parent_id')
            ->filter(fn($id) => $id !== null)
            ->map(fn($id): int => (int) $id)
            ->unique()
            ->values();

        while ($parentIds->diff($loadedIds)->isNotEmpty()) {
            $missingParentIds = $parentIds
                ->diff($loadedIds)
                ->values();

            $parents = DB::table('menus as m')
                ->whereIn('m.id', $missingParentIds->all())
                ->where('m.is_active', true)
                ->where(function ($query) {
                    $query
                        ->where('m.show_in_sidebar', true)
                        ->orWhereNull('m.show_in_sidebar');
                })
                ->select(
                    'm.id',
                    'm.parent_id',
                    'm.name',
                    'm.path',
                    'm.route_name',
                    'm.icon',
                    'm.order_no'
                )
                ->get();

            if ($parents->isEmpty()) {
                break;
            }

            $allRows = $allRows->merge($parents);

            $loadedIds = $allRows
                ->pluck('id')
                ->map(fn($id): int => (int) $id)
                ->unique()
                ->values();

            $parentIds = $allRows
                ->pluck('parent_id')
                ->filter(fn($id) => $id !== null)
                ->map(fn($id): int => (int) $id)
                ->unique()
                ->values();
        }

        return $allRows
            ->unique('id')
            ->sortBy([
                ['parent_id', 'asc'],
                ['order_no', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    private function cleanMaterioMenu(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            unset($item['id'], $item['parent_id'], $item['order_no']);

            if (empty($item['icon'])) {
                unset($item['icon']);
            }

            if (!empty($item['children'])) {
                $item['children'] = $this->cleanMaterioMenu($item['children']);

                /*
                |--------------------------------------------------------------------------
                | Parent group tidak boleh punya to
                |--------------------------------------------------------------------------
                */
                unset($item['to']);
            } else {
                unset($item['children']);

                if (empty($item['to'])) {
                    $item['to'] = [
                        'path' => '#',
                    ];
                }
            }

            return $item;
        }, $items));
    }

    private function buildTree($menus, ?int $parentId = null): array
    {
        return $menus
            ->where('parent_id', $parentId)
            ->sortBy([
                ['order_no', 'asc'],
                ['name', 'asc'],
            ])
            ->map(function (Menu $menu) use ($menus): array {
                return [
                    'id' => $menu->id,
                    'parent_id' => $menu->parent_id,
                    'name' => $menu->name,
                    'path' => $menu->path,
                    'route_name' => $menu->route_name,
                    'icon' => $menu->icon,
                    'order_no' => $menu->order_no,
                    'permission_key' => $menu->permission_key,
                    'show_in_sidebar' => (bool) $menu->show_in_sidebar,
                    'is_active' => (bool) $menu->is_active,
                    'type' => $this->resolveMenuType($menu),
                    'children' => $this->buildTree($menus, (int) $menu->id),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveMenuType(Menu $menu): string
    {
        if (!$menu->path) {
            return 'GROUP';
        }

        if (!$menu->show_in_sidebar) {
            return 'HIDDEN_PAGE';
        }

        return 'SIDEBAR_PAGE';
    }

    private function validateOrderNoIsUnique(
        ?int $menuId,
        ?int $parentId,
        int $orderNo,
    ): void {
        $query = Menu::query()
            ->where('order_no', $orderNo);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        if ($menuId) {
            $query->where('id', '!=', $menuId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'order_no' => [
                    'Order sudah digunakan pada parent menu yang sama.',
                ],
            ]);
        }
    }

    private function validateCanDeactivateMenu(Menu $menu): void
    {
        $activeChildCount = Menu::query()
            ->where('parent_id', $menu->id)
            ->where('is_active', true)
            ->count();

        if ($activeChildCount > 0) {
            throw ValidationException::withMessages([
                'menu' => [
                    'Menu tidak dapat dinonaktifkan karena masih memiliki child menu yang aktif. Nonaktifkan atau pindahkan child menu terlebih dahulu.',
                ],
            ]);
        }
    }

    private function validateParentChainIsActive(?int $parentId): void
    {
        $currentParentId = $parentId;

        while ($currentParentId) {
            $parent = Menu::query()
                ->where('id', $currentParentId)
                ->first();

            if (!$parent) {
                return;
            }

            if (!$parent->is_active) {
                throw ValidationException::withMessages([
                    'parent_id' => [
                        'Menu tidak dapat diaktifkan karena parent menu atau salah satu parent di atasnya masih nonaktif.',
                    ],
                ]);
            }

            $currentParentId = $parent->parent_id;
        }
    }

    private function getUserRoleIds($user): \Illuminate\Support\Collection
    {
        $userRoleIds = collect();

        if (
            isset($user->role_id)
            && $user->role_id
        ) {
            $userRoleIds->push((int) $user->role_id);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
            $userRoleIds = $userRoleIds->merge(
                \Illuminate\Support\Facades\DB::table('role_user')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id)
            );
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('user_roles')) {
            $userRoleIds = $userRoleIds->merge(
                \Illuminate\Support\Facades\DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id)
            );
        }

        return $userRoleIds
            ->filter(fn($id) => (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function countWaitingMyPurchaseRequests($user): int
    {
        $userRoleIds = $this->getUserRoleIds($user);

        return \App\Models\PurchaseRequest::query()
            ->whereRaw(
                'UPPER(TRIM(purchase_requests.status)) = ?',
                ['IN PROGRESS'],
            )
            ->whereHas('approvals', function ($approvalQuery) use ($user, $userRoleIds) {
                $approvalQuery
                    ->whereRaw(
                        'UPPER(TRIM(purchase_request_approvals.status)) = ?',
                        ['WAITING'],
                    )
                    ->whereRaw(
                        'purchase_request_approvals.step_order = (
                        SELECT MIN(pra_min.step_order)
                        FROM purchase_request_approvals AS pra_min
                        WHERE pra_min.purchase_request_id = purchase_request_approvals.purchase_request_id
                          AND UPPER(TRIM(pra_min.status)) = ?
                    )',
                        ['WAITING'],
                    )
                    ->where(function ($approverQuery) use ($user, $userRoleIds) {
                        $approverQuery->where(function ($userQuery) use ($user) {
                            $userQuery
                                ->whereRaw(
                                    'UPPER(TRIM(purchase_request_approvals.approver_type)) = ?',
                                    ['USER'],
                                )
                                ->where(
                                    'purchase_request_approvals.approver_id',
                                    $user->id,
                                );
                        });

                        if ($userRoleIds->isNotEmpty()) {
                            $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                                $roleQuery
                                    ->whereRaw(
                                        'UPPER(TRIM(purchase_request_approvals.approver_type)) = ?',
                                        ['ROLE'],
                                    )
                                    ->whereIn(
                                        'purchase_request_approvals.approver_id',
                                        $userRoleIds->all(),
                                    );
                            });
                        }
                    });
            })
            ->count();
    }

    private function countWaitingMyPurchaseOrders($user): int
    {
        $userRoleIds = $this->getUserRoleIds($user);

        return \App\Models\PurchaseOrder::query()
            ->whereRaw(
                'UPPER(TRIM(purchase_orders.status)) = ?',
                ['IN PROGRESS'],
            )
            ->whereHas('approvals', function ($approvalQuery) use ($user, $userRoleIds) {
                $approvalQuery
                    ->whereRaw(
                        'UPPER(TRIM(purchase_order_approvals.status)) = ?',
                        ['WAITING'],
                    )
                    ->whereRaw(
                        'purchase_order_approvals.step_order = (
                        SELECT MIN(poa_min.step_order)
                        FROM purchase_order_approvals AS poa_min
                        WHERE poa_min.purchase_order_id = purchase_order_approvals.purchase_order_id
                          AND UPPER(TRIM(poa_min.status)) = ?
                    )',
                        ['WAITING'],
                    )
                    ->where(function ($approverQuery) use ($user, $userRoleIds) {
                        $approverQuery->where(function ($userQuery) use ($user) {
                            $userQuery
                                ->whereRaw(
                                    'UPPER(TRIM(purchase_order_approvals.approver_type)) = ?',
                                    ['USER'],
                                )
                                ->where(
                                    'purchase_order_approvals.approver_id',
                                    $user->id,
                                );
                        });

                        if ($userRoleIds->isNotEmpty()) {
                            $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                                $roleQuery
                                    ->whereRaw(
                                        'UPPER(TRIM(purchase_order_approvals.approver_type)) = ?',
                                        ['ROLE'],
                                    )
                                    ->whereIn(
                                        'purchase_order_approvals.approver_id',
                                        $userRoleIds->all(),
                                    );
                            });
                        }
                    });
            })
            ->count();
    }

    private function injectNavigationBadges(array $menus, array $badges): array
    {
        $approvalModules = $this->approvalBadgeModules();

        return collect($menus)
            ->map(function (array $menu) use ($badges, $approvalModules) {
                if (!empty($menu['children']) && is_array($menu['children'])) {
                    $menu['children'] = $this->injectNavigationBadges($menu['children'], $badges);
                }

                $title = strtolower(trim((string) ($menu['title'] ?? $menu['name'] ?? '')));
                $path = strtolower(trim((string) ($menu['to']['path'] ?? $menu['path'] ?? '')));

                $ownBadgeCount = 0;

                foreach ($approvalModules as $moduleKey => $config) {
                    foreach (($config['menu_keywords'] ?? []) as $keyword) {
                        $normalizedKeyword = strtolower(trim((string) $keyword));

                        if ($normalizedKeyword === '') {
                            continue;
                        }

                        if (
                            str_contains($title, $normalizedKeyword)
                            || str_contains($path, $normalizedKeyword)
                        ) {
                            $ownBadgeCount += (int) ($badges[$moduleKey] ?? 0);
                            break;
                        }
                    }
                }

                $childrenBadgeCount = collect($menu['children'] ?? [])
                    ->sum(fn(array $child): int => (int) ($child['badge_count'] ?? 0));

                $totalBadgeCount = $ownBadgeCount + $childrenBadgeCount;

                if ($totalBadgeCount > 0) {
                    $menu['badge_count'] = $totalBadgeCount;
                    $menu['badge_color'] = 'error';
                    $menu['badgeContent'] = $totalBadgeCount > 99
                        ? '99+'
                        : (string) $totalBadgeCount;
                    $menu['badgeClass'] = 'bg-error';
                } else {
                    unset(
                        $menu['badge_count'],
                        $menu['badge_color'],
                        $menu['badgeContent'],
                        $menu['badgeClass']
                    );
                }

                return $menu;
            })
            ->values()
            ->all();
    }

    private function approvalBadgeModules(): array
    {
        return [
            'purchase_request' => [
                'document_table' => 'purchase_requests',
                'approval_table' => 'purchase_request_approvals',

                'document_primary_key' => 'id',
                'approval_foreign_key' => 'purchase_request_id',

                'document_status_column' => 'status',
                'document_in_progress_status' => 'IN PROGRESS',

                'approval_status_column' => 'status',
                'waiting_status' => 'WAITING',

                'step_order_column' => 'step_order',
                'approver_type_column' => 'approver_type',
                'approver_id_column' => 'approver_id',

                'menu_keywords' => [
                    'purchase requisition',
                    'purchase_request',
                    'purchase-requisition',
                    'purchase_requisition',
                ],
            ],

            'purchase_order' => [
                'document_table' => 'purchase_orders',
                'approval_table' => 'purchase_order_approvals',

                'document_primary_key' => 'id',
                'approval_foreign_key' => 'purchase_order_id',

                'document_status_column' => 'status',
                'document_in_progress_status' => 'IN PROGRESS',

                'approval_status_column' => 'status',
                'waiting_status' => 'WAITING',

                'step_order_column' => 'step_order',
                'approver_type_column' => 'approver_type',
                'approver_id_column' => 'approver_id',

                'menu_keywords' => [
                    'purchase order',
                    'purchase_order',
                    'purchase-order',
                ],
            ],

            'vendor' => [
                'document_table' => 'master_vendor',
                'approval_table' => 'master_vendor_approvals',

                'document_primary_key' => 'id',
                'approval_foreign_key' => 'vendor_id',

                'document_status_column' => 'status_approval',
                'document_in_progress_status' => 'PENDING REVIEW',

                'approval_status_column' => 'status',
                'waiting_status' => 'WAITING',

                'step_order_column' => 'step_order',
                'approver_type_column' => 'approver_type',
                'approver_id_column' => 'approver_id',

                'menu_keywords' => [
                    'vendor',
                    'master vendor',
                    'master_vendor',
                    'master-vendor',
                ],
            ],
            'inventory_po' => [
                'menu_keywords' => [
                    'inventory purchase order',
                    'inventory po',
                    'vendor po',
                    'PO Supplier',
                    'po-supplier',
                ],

            ],
        ];
    }

    private function buildApprovalBadges($user): array
    {
        $badges = [];

        foreach ($this->approvalBadgeModules() as $moduleKey => $config) {
            if ($moduleKey === 'inventory_po') {
                // Badge Inventory PO
                $badges[$moduleKey] = $this->countInventoryPOBadge($user);
            } else {
                $badges[$moduleKey] = $this->countWaitingMyApprovalByConfig($user, $config);
            }
            // $badges[$moduleKey] = $this->countWaitingMyApprovalByConfig($user, $config);

        }

        return $badges;
    }

    private function countWaitingMyApprovalByConfig($user, array $config): int
    {
        $documentTable = $config['document_table'];
        $approvalTable = $config['approval_table'];

        if (
            !Schema::hasTable($documentTable)
            || !Schema::hasTable($approvalTable)
        ) {
            return 0;
        }

        $requiredDocumentColumns = [
            $config['document_primary_key'],
            $config['document_status_column'],
        ];

        $requiredApprovalColumns = [
            $config['approval_foreign_key'],
            $config['approval_status_column'],
            $config['step_order_column'],
            $config['approver_type_column'],
            $config['approver_id_column'],
        ];

        foreach ($requiredDocumentColumns as $column) {
            if (!Schema::hasColumn($documentTable, $column)) {
                return 0;
            }
        }

        foreach ($requiredApprovalColumns as $column) {
            if (!Schema::hasColumn($approvalTable, $column)) {
                return 0;
            }
        }

        $userRoleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->map(fn($id): int => (int) $id)
            ->filter(fn($id): bool => $id > 0)
            ->unique()
            ->values();

        $documentPrimaryKey = $config['document_primary_key'];
        $approvalForeignKey = $config['approval_foreign_key'];

        $documentStatusColumn = $config['document_status_column'];
        $documentInProgressStatus = strtoupper(trim((string) $config['document_in_progress_status']));

        $approvalStatusColumn = $config['approval_status_column'];
        $waitingStatus = strtoupper(trim((string) $config['waiting_status']));

        $stepOrderColumn = $config['step_order_column'];
        $approverTypeColumn = $config['approver_type_column'];
        $approverIdColumn = $config['approver_id_column'];

        return DB::table($documentTable)
            ->whereRaw(
                "UPPER(TRIM({$documentTable}.{$documentStatusColumn})) = ?",
                [$documentInProgressStatus]
            )
            ->whereExists(function ($query) use (
                $user,
                $userRoleIds,
                $documentTable,
                $approvalTable,
                $documentPrimaryKey,
                $approvalForeignKey,
                $approvalStatusColumn,
                $waitingStatus,
                $stepOrderColumn,
                $approverTypeColumn,
                $approverIdColumn,
            ) {
                $query
                    ->selectRaw('1')
                    ->from($approvalTable)
                    ->whereColumn(
                        "{$approvalTable}.{$approvalForeignKey}",
                        "{$documentTable}.{$documentPrimaryKey}"
                    )
                    ->whereRaw(
                        "UPPER(TRIM({$approvalTable}.{$approvalStatusColumn})) = ?",
                        [$waitingStatus]
                    )
                    ->whereRaw(
                        "{$approvalTable}.{$stepOrderColumn} = (
                        SELECT MIN(approval_min.{$stepOrderColumn})
                        FROM {$approvalTable} AS approval_min
                        WHERE approval_min.{$approvalForeignKey} = {$approvalTable}.{$approvalForeignKey}
                          AND UPPER(TRIM(approval_min.{$approvalStatusColumn})) = ?
                    )",
                        [$waitingStatus]
                    )
                    ->where(function ($approverQuery) use (
                        $user,
                        $userRoleIds,
                        $approvalTable,
                        $approverTypeColumn,
                        $approverIdColumn,
                    ) {
                        $approverQuery->where(function ($userQuery) use (
                            $user,
                            $approvalTable,
                            $approverTypeColumn,
                            $approverIdColumn,
                        ) {
                            $userQuery
                                ->whereRaw(
                                    "UPPER(TRIM({$approvalTable}.{$approverTypeColumn})) = ?",
                                    ['USER']
                                )
                                ->where("{$approvalTable}.{$approverIdColumn}", $user->id);
                        });

                        if ($userRoleIds->isNotEmpty()) {
                            $approverQuery->orWhere(function ($roleQuery) use (
                                $userRoleIds,
                                $approvalTable,
                                $approverTypeColumn,
                                $approverIdColumn,
                            ) {
                                $roleQuery
                                    ->whereRaw(
                                        "UPPER(TRIM({$approvalTable}.{$approverTypeColumn})) = ?",
                                        ['ROLE']
                                    )
                                    ->whereIn("{$approvalTable}.{$approverIdColumn}", $userRoleIds->all());
                            });
                        }
                    });
            })
            ->count();
    }
    private function countInventoryPOBadge($user): int
    {
        $roleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        // Contoh cek role berdasarkan kode
        $role = DB::table('roles')
            ->whereIn('id', $roleIds)
            ->value('kode');

        $query = DB::table('inventory_vendor_po');

        switch ($role) {
            case 'CEO':
                $query->where('disposisi_po', 2)
                    ->where('cfo_result', 1);
                break;

            case 'CFO':
                $query->where('disposisi_po', 1);
                break;

            default:
                return 0;
        }

        return $query->count();
    }
}
