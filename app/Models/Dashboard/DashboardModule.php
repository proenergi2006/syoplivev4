<?php

namespace App\Models\Dashboard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardModule extends Model
{
    protected $table = 'dashboard_modules';

    protected $fillable = [
        'dashboard_module_group_id',
        'code',
        'title',
        'short_title',
        'description',
        'icon',
        'color',
        'route_path',
        'permission_name',
        'features',
        'is_active',
        'is_available',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
        'sort_order' => 'integer',
        'dashboard_module_group_id' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(
            DashboardModuleGroup::class,
            'dashboard_module_group_id',
        );
    }
}
