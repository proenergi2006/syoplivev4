<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CabangController extends Controller
{
    public function index(Request $request)
    {
        $query = Cabang::with('groupCabang')
            ->orderBy('id', 'asc');

        if ($request->search) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('nama_cabang', 'ILIKE', "%{$search}%")
                    ->orWhere('inisial_cabang', 'ILIKE', "%{$search}%")
                    ->orWhere('inisial_segel', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) ($request->per_page ?? 10);

        $cabangs = $query->paginate($perPage);

        $cabangs->getCollection()->transform(function ($cabang) {
            return [
                'id' => $cabang->id,
                'group_cabang_id' => $cabang->group_cabang_id,
                'group_wilayah' => $cabang->groupCabang->group_wilayah ?? '-',
                'nama_cabang' => $cabang->nama_cabang,
                'inisial_cabang' => $cabang->inisial_cabang,
                'inisial_segel' => $cabang->inisial_segel,
                'catatan_cabang' => $cabang->catatan_cabang,
                'kode_barcode' => $cabang->kode_barcode,
                'stok_segel' => $cabang->stok_segel,
                'is_active' => $cabang->is_active,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $cabangs->items(),
            'meta' => [
                'current_page' => $cabangs->currentPage(),
                'last_page' => $cabangs->lastPage(),
                'per_page' => $cabangs->perPage(),
                'total' => $cabangs->total(),
            ],
        ]);
    }

    public function dropdownSelect(Request $request)
    {
        try {
            $query = Cabang::query()
                ->where('is_active', true)
                ->orderBy('id', 'asc');

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_cabang', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_cabang', 'ILIKE', "%{$search}%");
                });
            }

            $cabangs = $query->get()->map(function ($cabang) {
                return [
                    'id' => $cabang->id,
                    'value' => $cabang->id,
                    'title' => $cabang->nama_cabang,
                    'nama' => $cabang->nama_cabang,
                    'nama_cabang' => $cabang->nama_cabang,
                    'inisial_cabang' => $cabang->inisial_cabang,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data cabang berhasil dimuat.',
                'data' => $cabangs,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Cabang] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data cabang.',
                'data' => [],
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_cabang_id' => ['required', 'exists:group_cabang,id'],
            'nama_cabang' => [
                'required',
                'string',
                'max:70',
                Rule::unique('cabang', 'nama_cabang'),
            ],
            'inisial_cabang' => ['required', 'string', 'max:15'],
            'inisial_segel' => ['nullable', 'string', 'max:20'],
            'catatan_cabang' => ['nullable', 'string', 'max:1000'],
            'kode_barcode' => ['nullable', 'integer'],
            'stok_segel' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $cabang = Cabang::create([
                'group_cabang_id' => $validated['group_cabang_id'],
                'nama_cabang' => $validated['nama_cabang'],
                'inisial_cabang' => strtoupper($validated['inisial_cabang']),
                'inisial_segel' => strtoupper($validated['inisial_segel'] ?? ''),
                'catatan_cabang' => $validated['catatan_cabang'] ?? null,
                'kode_barcode' => $validated['kode_barcode'] ?? 0,
                'stok_segel' => $validated['stok_segel'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,

                'created_time' => now(),
                'created_ip' => $request->ip(),
                'created_by' => auth()->user()->name ?? 'SYSTEM',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cabang berhasil dibuat.',
                'data' => $cabang,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Cabang gagal dibuat.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $cabang = Cabang::with('groupCabang')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $cabang,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $cabang = Cabang::findOrFail($id);

        $validated = $request->validate([
            'group_cabang_id' => ['required', 'exists:group_cabang,id'],
            'nama_cabang' => [
                'required',
                'string',
                'max:70',
                Rule::unique('cabang', 'nama_cabang')->ignore($cabang->id),
            ],
            'inisial_cabang' => ['required', 'string', 'max:15'],
            'inisial_segel' => ['nullable', 'string', 'max:20'],
            'catatan_cabang' => ['nullable', 'string', 'max:1000'],
            'kode_barcode' => ['nullable', 'integer'],
            'stok_segel' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $cabang->update([
                'group_cabang_id' => $validated['group_cabang_id'],
                'nama_cabang' => $validated['nama_cabang'],
                'inisial_cabang' => strtoupper($validated['inisial_cabang']),
                'inisial_segel' => strtoupper($validated['inisial_segel'] ?? ''),
                'catatan_cabang' => $validated['catatan_cabang'] ?? null,
                'kode_barcode' => $validated['kode_barcode'] ?? 0,
                'stok_segel' => $validated['stok_segel'] ?? 0,
                'is_active' => $validated['is_active'] ?? $cabang->is_active,

                'lastupdate_time' => now(),
                'lastupdate_ip' => $request->ip(),
                'lastupdate_by' => auth()->user()->name ?? 'SYSTEM',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cabang berhasil diperbarui.',
                'data' => $cabang,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Cabang gagal diperbarui.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $cabang = Cabang::findOrFail($id);

        $cabang->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cabang berhasil dihapus.',
        ]);
    }
}
