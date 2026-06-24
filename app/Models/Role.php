<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = ['kode', 'nama', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function menus()
    {
        // PENTING: pivot table role_menus, key role_id & menu_id
        return $this->belongsToMany(Menu::class, 'role_menus', 'role_id', 'menu_id');
        // JANGAN withTimestamps kalau role_menus tidak punya created_at/updated_at
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot([
                'scope',
                'is_active',
            ])
            ->withTimestamps();
    }
}
