<?php

namespace App\Http\Controllers;

use App\Models\MasterBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MasterBank::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('nama_bank', 'ilike', "%{$search}%")
                    ->orWhere('nama_bank_pendek', 'ilike', "%{$search}%")
                    ->orWhere('kode_bank', 'ilike', "%{$search}%")
                    ->orWhere('swift_code', 'ilike', "%{$search}%");
            });
        }

        $banks = $query
            ->orderBy('nama_bank')
            ->get([
                'id',
                'kode_bank',
                'nama_bank',
                'nama_bank_pendek',
                'swift_code',
                'is_active',
            ]);

        return response()->json([
            'message' => 'Data master bank berhasil diambil',
            'data' => $banks,
        ]);
    }

    public function show($id): JsonResponse
    {
        $bank = MasterBank::find($id);

        if (!$bank) {
            return response()->json([
                'message' => 'Data master bank tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail master bank berhasil diambil',
            'data' => $bank,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kode_bank' => 'nullable|string|max:10|unique:master_banks,kode_bank',
            'nama_bank' => 'required|string|max:150|unique:master_banks,nama_bank',
            'nama_bank_pendek' => 'nullable|string|max:100',
            'swift_code' => 'nullable|string|max:20',
            'tipe_bank' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        $bank = MasterBank::create([
            'kode_bank' => $validated['kode_bank'] ?? null,
            'nama_bank' => $validated['nama_bank'],
            'nama_bank_pendek' => $validated['nama_bank_pendek'] ?? null,
            'swift_code' => $validated['swift_code'] ?? null,
            'tipe_bank' => $validated['tipe_bank'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Master bank berhasil ditambahkan',
            'data' => $bank,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $bank = MasterBank::find($id);

        if (!$bank) {
            return response()->json([
                'message' => 'Data master bank tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'kode_bank' => 'nullable|string|max:10|unique:master_banks,kode_bank,' . $bank->id,
            'nama_bank' => 'required|string|max:150|unique:master_banks,nama_bank,' . $bank->id,
            'nama_bank_pendek' => 'nullable|string|max:100',
            'swift_code' => 'nullable|string|max:20',
            'tipe_bank' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        $bank->update([
            'kode_bank' => $validated['kode_bank'] ?? null,
            'nama_bank' => $validated['nama_bank'],
            'nama_bank_pendek' => $validated['nama_bank_pendek'] ?? null,
            'swift_code' => $validated['swift_code'] ?? null,
            'tipe_bank' => $validated['tipe_bank'] ?? null,
            'is_active' => $validated['is_active'] ?? $bank->is_active,
        ]);

        return response()->json([
            'message' => 'Master bank berhasil diperbarui',
            'data' => $bank->fresh(),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $bank = MasterBank::find($id);

        if (!$bank) {
            return response()->json([
                'message' => 'Data master bank tidak ditemukan',
            ], 404);
        }

        $bank->delete();

        return response()->json([
            'message' => 'Master bank berhasil dihapus',
        ]);
    }

    public function toggleStatus($id): JsonResponse
    {
        $bank = MasterBank::find($id);

        if (!$bank) {
            return response()->json([
                'message' => 'Data master bank tidak ditemukan',
            ], 404);
        }

        $bank->is_active = !$bank->is_active;
        $bank->save();

        return response()->json([
            'message' => 'Status master bank berhasil diperbarui',
            'data' => $bank,
        ]);
    }
}
