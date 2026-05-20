<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Department::query()
                ->orderBy('nama', 'asc');

            // Search
            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%");
                });
            }

            // Filter active
            if ($request->filled('is_active')) {
                $query->where(
                    'is_active',
                    filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                );
            }

            $perPage = (int) ($request->per_page ?? 10);

            $departments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $departments->items(),
                'meta' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                ],
            ]);
        } catch (\Throwable $e) {

            Log::error('[Department] Index error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data department.',
                'data' => [],
            ], 500);
        }
    }

    public function dropdownSelect(Request $request)
    {
        try {
            $query = Department::query()
                ->where('is_active', true)
                ->orderBy('nama', 'asc');

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%");
                });
            }

            $departments = $query->get()->map(function ($department) {
                return [
                    'id' => $department->id,
                    'value' => $department->id,
                    'title' => $department->nama,
                    'kode' => $department->kode,
                    'nama' => $department->nama,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data department berhasil dimuat.',
                'data' => $departments,
            ]);
        } catch (\Throwable $e) {

            Log::error('[Department] Dropdown error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data department',
                'data' => [],
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'kode'),
            ],
            'nama' => [
                'required',
                'string',
                'max:120',
                Rule::unique('departments', 'nama'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $department = Department::create([
                'kode' => strtoupper($validated['kode']),
                'nama' => $validated['nama'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Department berhasil dibuat.',
                'data' => $department,
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[Department] Store error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal dibuat.',
            ], 500);
        }
    }

    public function show(string $id)
    {
        $department = Department::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $department,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'kode')->ignore($department->id),
            ],
            'nama' => [
                'required',
                'string',
                'max:120',
                Rule::unique('departments', 'nama')->ignore($department->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {

            $department->update([
                'kode' => strtoupper($validated['kode']),
                'nama' => $validated['nama'],
                'is_active' => $validated['is_active'] ?? $department->is_active,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Department berhasil diperbarui.',
                'data' => $department,
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[Department] Update error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal diperbarui.',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $department = Department::findOrFail($id);

        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department berhasil dihapus.',
        ]);
    }
}
