<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'signature_uploaded_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function cabangData()
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'departemen_id');
    }
}
