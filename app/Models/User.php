<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'cabang_id',
        'departemen_id',
        'is_active',
        'signature_path',
        'signature_uploaded_at',
        'username',
        'last_login_at',
        'last_logout_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'signature_uploaded_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_logout_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    | Relasi role user memakai pivot table user_roles.
    |--------------------------------------------------------------------------
    */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang - Alias utama
    |--------------------------------------------------------------------------
    | Controller index memakai:
    | with('cabang:id,nama')
    |--------------------------------------------------------------------------
    */
    public function cabang()
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang - Alias lama
    |--------------------------------------------------------------------------
    | Dipertahankan supaya code existing yang pakai cabangData tetap aman.
    |--------------------------------------------------------------------------
    */
    public function cabangData()
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Department - Alias utama
    |--------------------------------------------------------------------------
    | Controller index memakai:
    | with('departemen:id,nama')
    |--------------------------------------------------------------------------
    */
    public function departemen()
    {
        return $this->belongsTo(Department::class, 'departemen_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Department - Alias lama
    |--------------------------------------------------------------------------
    | Dipertahankan supaya code existing yang pakai departmentData tetap aman.
    |--------------------------------------------------------------------------
    */
    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'departemen_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang Pivot
    |--------------------------------------------------------------------------
    | Kalau nanti user bisa punya lebih dari satu cabang lewat table user_cabang.
    |--------------------------------------------------------------------------
    */
    public function cabangs()
    {
        return $this->belongsToMany(Cabang::class, 'user_cabang', 'user_id', 'cabang_id');
    }

    // Permissions
    public function hasPermission(string $permissionCode): bool
    {
        $roleId = $this->getActiveRoleId();

        if (!$roleId) {
            return false;
        }

        return RolePermission::query()
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id',
            )
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.code', $permissionCode)
            ->where('role_permissions.is_active', true)
            ->where('permissions.is_active', true)
            ->exists();
    }

    public function getActiveRoleId(): ?int
    {
        $roleId = DB::table('user_roles')
            ->where('user_id', $this->id)
            ->value('role_id');

        return $roleId !== null
            ? (int) $roleId
            : null;
    }

    public function getPermissionScope(string $permissionCode): string
    {
        $roleId = $this->getActiveRoleId();

        if (!$roleId) {
            return 'NONE';
        }

        $scope = RolePermission::query()
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id',
            )
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.code', $permissionCode)
            ->where('role_permissions.is_active', true)
            ->where('permissions.is_active', true)
            ->value('role_permissions.scope');

        return $this->normalizePermissionScope($scope);
    }

    public function getPermissionAbilities(): array
    {
        $permissions = [];

        $this->loadMissing([
            'roles.permissions' => function ($query) {
                $query
                    ->where('permissions.is_active', true)
                    ->wherePivot('is_active', true);
            },
        ]);

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $code = (string) $permission->code;
                $scope = strtoupper((string) ($permission->pivot->scope ?? 'NONE'));

                if (!isset($permissions[$code])) {
                    $permissions[$code] = [
                        'allowed' => true,
                        'scope' => $scope,
                    ];

                    continue;
                }

                $currentScope = $permissions[$code]['scope'];

                if (
                    $this->getScopePriority($scope)
                    > $this->getScopePriority($currentScope)
                ) {
                    $permissions[$code]['scope'] = $scope;
                }
            }
        }

        return $permissions;
    }

    private function normalizePermissionScope(?string $scope): string
    {
        $scope = strtoupper(trim((string) $scope));

        $allowedScopes = [
            'NONE',
            'OWN_DATA',
            'OWN_DEPARTMENT',
            'OWN_CABANG',
            'ALL',
        ];

        return in_array($scope, $allowedScopes, true)
            ? $scope
            : 'NONE';
    }

    private function getScopePriority(string $scope): int
    {
        return match (strtoupper($scope)) {
            'ALL' => 4,
            'OWN_CABANG' => 3,
            'OWN_DEPARTMENT' => 2,
            'OWN_DATA' => 1,
            default => 0,
        };
    }
}
