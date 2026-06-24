<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends Model
{
    protected $fillable = [
        'module',
        'action',
        'code',
        'name',
        'description',
        'route_prefix',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withPivot([
                'scope',
                'is_active',
            ])
            ->withTimestamps();
    }

    public function permissionModule(): BelongsTo
    {
        return $this->belongsTo(
            PermissionModule::class,
            'module',
            'code',
        );
    }
}
