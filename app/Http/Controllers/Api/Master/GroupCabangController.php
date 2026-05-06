<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\GroupCabang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupCabangController extends Controller
{
    public function index(Request $request)
    {
        $query = GroupCabang::query()
            ->orderBy('id', 'desc');

        if ($request->search) {
            $search = $request->search;

            $query->where('group_wilayah', 'ILIKE', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) ($request->per_page ?? 10);

        $groups = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $groups->items(),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_wilayah' => [
                'required',
                'string',
                'max:50',
                Rule::unique('group_cabang', 'group_wilayah'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $group = GroupCabang::create([
                'group_wilayah' => strtoupper($validated['group_wilayah']),
                'is_active' => $validated['is_active'] ?? true,
                'created_time' => now(),
                'created_ip' => $request->ip(),
                'created_by' => auth()->user()->name ?? 'SYSTEM',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Group cabang berhasil dibuat.',
                'data' => $group,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Group cabang gagal dibuat.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $group = GroupCabang::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $group,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $group = GroupCabang::findOrFail($id);

        $validated = $request->validate([
            'group_wilayah' => [
                'required',
                'string',
                'max:50',
                Rule::unique('group_cabang', 'group_wilayah')->ignore($group->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $group->update([
                'group_wilayah' => strtoupper($validated['group_wilayah']),
                'is_active' => $validated['is_active'] ?? $group->is_active,
                'lastupdate_time' => now(),
                'lastupdate_ip' => $request->ip(),
                'lastupdate_by' => auth()->user()->name ?? 'SYSTEM',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Group cabang berhasil diperbarui.',
                'data' => $group,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Group cabang gagal diperbarui.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $group = GroupCabang::findOrFail($id);

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Group cabang berhasil dihapus.',
        ]);
    }
}
