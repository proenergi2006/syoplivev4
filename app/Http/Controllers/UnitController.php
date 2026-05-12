<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        try {

            $query = Unit::query()
                ->orderBy('nama', 'asc');

            // Search
            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%")
                        ->orWhere('kategori', 'ILIKE', "%{$search}%");
                });
            }

            $perPage = (int) ($request->per_page ?? 10);

            $units = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $units->items(),
                'meta' => [
                    'current_page' => $units->currentPage(),
                    'last_page' => $units->lastPage(),
                    'per_page' => $units->perPage(),
                    'total' => $units->total(),
                ],
            ], 200);
        } catch (\Throwable $e) {

            Log::error('[Unit] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data satuan.',
                'data' => [],
            ], 500);
        }
    }

    public function dropdownSelect(Request $request)
    {
        try {

            $query = Unit::query()
                ->orderBy('nama', 'asc');

            // Search
            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%")
                        ->orWhere('kategori', 'ILIKE', "%{$search}%");
                });
            }

            $units = $query->get()->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'value' => $unit->id,

                    'kode' => $unit->kode,
                    'nama' => $unit->nama,
                    'kategori' => $unit->kategori,

                    'title' => $unit->kode . ' - ' . $unit->nama,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data satuan berhasil dimuat.',
                'data' => $units,
            ], 200);
        } catch (\Throwable $e) {

            Log::error('[Unit] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data satuan.',
                'data' => [],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
                Rule::unique('units', 'kode'),
            ],
            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('units', 'nama'),
            ],
            'kategori' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        DB::beginTransaction();

        try {

            $unit = Unit::create([
                'kode' => strtoupper($validated['kode']),
                'nama' => strtoupper($validated['nama']),
                'kategori' => $validated['kategori'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil dibuat.',
                'data' => $unit,
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[Unit] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Satuan gagal dibuat.',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {

            $unit = Unit::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $unit,
            ], 200);
        } catch (\Throwable $e) {

            Log::error('[Unit] Show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Data satuan tidak ditemukan.',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        $unit = Unit::findOrFail($id);

        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('units', 'kode')->ignore($unit->id),
            ],
            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('units', 'nama')->ignore($unit->id),
            ],
            'kategori' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        DB::beginTransaction();

        try {

            $unit->update([
                'kode' => strtoupper($validated['kode']),
                'nama' => strtoupper($validated['nama']),
                'kategori' => $validated['kategori'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil diperbarui.',
                'data' => $unit,
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[Unit] Update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Satuan gagal diperbarui.',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {

            $unit = Unit::findOrFail($id);

            $unit->delete();

            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {

            Log::error('[Unit] Destroy error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Satuan gagal dihapus.',
            ], 500);
        }
    }
}
