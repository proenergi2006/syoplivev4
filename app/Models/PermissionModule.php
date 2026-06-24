<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionModule extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'route_prefix',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(
            Permission::class,
            'module',
            'code',
        );
    }
}
