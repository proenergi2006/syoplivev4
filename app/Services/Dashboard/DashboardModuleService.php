<?php

namespace App\Services\Dashboard;

use App\Models\Dashboard\DashboardModule;
use App\Models\Dashboard\DashboardModuleGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DashboardModuleService
{
    /**
     * Mengambil kategori dashboard yang memiliki
     * minimal satu modul yang dapat diakses user.
     */
    public function getGroups(User $user): Collection
    {
        $permissionCodes = $this
            ->getAllowedDashboardPermissionCodes($user);

        return DashboardModuleGroup::query()
            ->where('is_active', true)
            ->whereHas(
                'modules',
                function (Builder $query) use (
                    $permissionCodes,
                ): void {
                    $this->applyModulePermissionFilter(
                        $query,
                        $permissionCodes,
                    );
                },
            )
            ->withCount([
                'modules as modules_count' => function (
                    Builder $query,
                ) use (
                    $permissionCodes,
                ): void {
                    $this->applyModulePermissionFilter(
                        $query,
                        $permissionCodes,
                    );
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Mengambil card dashboard menggunakan pagination.
     */
    public function paginateModules(
        User $user,
        ?string $groupCode,
        int $perPage,
    ): LengthAwarePaginator {
        $permissionCodes = $this
            ->getAllowedDashboardPermissionCodes($user);

        return DashboardModule::query()
            ->with([
                'group',
            ])
            ->where('is_active', true)
            ->whereHas(
                'group',
                function (Builder $groupQuery) use (
                    $groupCode,
                ): void {
                    $groupQuery
                        ->where('is_active', true);

                    if (
                        $groupCode !== null
                        && trim($groupCode) !== ''
                    ) {
                        $groupQuery->where(
                            'code',
                            trim($groupCode),
                        );
                    }
                },
            )
            ->where(
                function (Builder $query) use (
                    $permissionCodes,
                ): void {
                    $query
                        ->whereNull('permission_name')
                        ->orWhereIn(
                            'permission_name',
                            $permissionCodes,
                        );
                },
            )
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($perPage);
    }

    /**
     * Mengambil seluruh kode permission dashboard
     * yang benar-benar dimiliki user.
     *
     * Project ini tidak menggunakan Spatie.
     * Pemeriksaan permission memakai method custom:
     *
     * $user->hasPermission($permissionCode)
     */
    private function getAllowedDashboardPermissionCodes(
        User $user,
    ): array {
        $dashboardPermissionCodes = DashboardModule::query()
            ->where('is_active', true)
            ->whereNotNull('permission_name')
            ->where('permission_name', '<>', '')
            ->distinct()
            ->pluck('permission_name');

        return $dashboardPermissionCodes
            ->filter(
                function ($permissionCode) use ($user): bool {
                    $permissionCode = trim(
                        (string) $permissionCode,
                    );

                    if ($permissionCode === '') {
                        return false;
                    }

                    return $user->hasPermission(
                        $permissionCode,
                    );
                },
            )
            ->map(
                fn($permissionCode): string => trim(
                    (string) $permissionCode,
                ),
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Filter modul berdasarkan status aktif
     * dan permission yang dimiliki user.
     */
    private function applyModulePermissionFilter(
        Builder $query,
        array $permissionCodes,
    ): void {
        $query
            ->where('is_active', true)
            ->where(
                function (Builder $permissionQuery) use (
                    $permissionCodes,
                ): void {
                    $permissionQuery
                        ->whereNull('permission_name')
                        ->orWhereIn(
                            'permission_name',
                            $permissionCodes,
                        );
                },
            );
    }
}
