<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
  public function index(Request $request)
  {
    $q = Role::query();

    if ($request->filled('search')) {
      $s = (string) $request->input('search');
      $q->where(function ($qq) use ($s) {
        $qq->where('kode', 'ilike', "%{$s}%")
          ->orWhere('nama', 'ilike', "%{$s}%");
      });
    }

    if ($request->filled('is_active')) {
      $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
    }

    $perPage = (int) $request->input('per_page', 15);

    return response()->json(
      $q->orderBy('nama')->paginate($perPage)
    );
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'kode' => ['required', 'string', 'max:30', 'unique:roles,kode'],
      'nama' => ['required', 'string', 'max:120'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $row = Role::create($data);

    return response()->json($row, 201);
  }

  public function show(Role $role)
  {
    return response()->json($role);
  }

  public function update(Request $request, Role $role)
  {
    $data = $request->validate([
      'kode' => ['required', 'string', 'max:30', Rule::unique('roles', 'kode')->ignore($role->id)],
      'nama' => ['required', 'string', 'max:120'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $role->update($data);

    return response()->json($role);
  }

  public function destroy(Role $role)
  {
    $role->delete();
    return response()->json(['message' => 'Deleted']);
  }

  public function dropdown(Request $request)
  {
    try {
      $search = trim((string) $request->input('search', ''));

      $query = Role::query()
        ->select([
          'id',
          'nama',
          'kode',
        ])
        ->when($search !== '', function ($query) use ($search) {
          $query->where(function ($q) use ($search) {
            $q->where('nama', 'ILIKE', "%{$search}%")
              ->orWhere('kode', 'ILIKE', "%{$search}%");
          });
        })
        ->orderBy('nama');

      if (Schema::hasColumn('roles', 'is_active')) {
        $query->where('is_active', true);
      }

      $roles = $query
        ->limit(100)
        ->get();

      return response()->json([
        'success' => true,
        'message' => 'Data role dropdown berhasil dimuat.',
        'data' => $roles,
      ], 200);
    } catch (\Throwable $e) {
      Log::error('[Role] Dropdown error', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'request' => $request->all(),
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Gagal memuat data role dropdown.',
        'data' => [],
        'debug' => app()->environment('local') ? $e->getMessage() : null,
      ], 500);
    }
  }
}
