<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Dashboard\DashboardModuleResource;
use App\Services\Dashboard\DashboardModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardModuleController extends Controller
{
    public function __construct(
        private readonly DashboardModuleService $dashboardModuleService,
    ) {}

    public function groups(Request $request): JsonResponse
    {
        $groups = $this->dashboardModuleService->getGroups(
            $request->user(),
        );

        return response()->json([
            'message' => 'Dashboard module groups retrieved successfully.',
            'data' => $groups->map(function ($group) {
                return [
                    'id' => $group->id,
                    'code' => $group->code,
                    'name' => $group->name,
                    'icon' => $group->icon,
                    'modules_count' => $group->modules_count,
                ];
            })->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_code' => [
                'nullable',
                'string',
                'max:50',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:24',
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 4);
        $groupCode = $validated['group_code'] ?? null;

        $modules = $this->dashboardModuleService->paginateModules(
            user: $request->user(),
            groupCode: $groupCode,
            perPage: $perPage,
        );

        return response()->json([
            'message' => 'Dashboard modules retrieved successfully.',

            'data' => DashboardModuleResource::collection(
                $modules->getCollection(),
            )->resolve($request),

            'meta' => [
                'current_page' => $modules->currentPage(),
                'last_page' => $modules->lastPage(),
                'per_page' => $modules->perPage(),
                'total' => $modules->total(),
                'has_more' => $modules->hasMorePages(),
            ],
        ]);
    }
}
