<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Resources\Json\JsonResource;

class DashboardModuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'short_title' => $this->short_title,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'route_path' => $this->route_path,
            'permission_name' => $this->permission_name,
            'features' => $this->features ?? [],
            'is_available' => (bool) $this->is_available,

            'group' => [
                'id' => $this->group?->id,
                'code' => $this->group?->code,
                'name' => $this->group?->name,
                'icon' => $this->group?->icon,
            ],
        ];
    }
}
