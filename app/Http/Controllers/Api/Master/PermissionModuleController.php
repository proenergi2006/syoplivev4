<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\PermissionModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PermissionModuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = PermissionModule::query();

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%")
                        ->orWhere('description', 'ILIKE', "%{$search}%")
                        ->orWhere('route_prefix', 'ILIKE', "%{$search}%");
                });
            }

            if ($request->filled('is_active')) {
                $isActive = filter_var(
                    $request->input('is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );

                if ($isActive !== null) {
                    $query->where('is_active', $isActive);
                }
            }

            $data = $query
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Data permission module berhasil dimuat.',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Permission Module] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data permission module.',
                'data' => [],
            ], 500);
        }
    }
}
