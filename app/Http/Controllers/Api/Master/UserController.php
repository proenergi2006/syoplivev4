<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = User::query()->with([
            'cabang:id,nama',
            'departemen:id,nama',
            'roles:id,nama',
        ]);

        // search: name/email
        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                // PostgreSQL: ilike
                $qq->where('name', 'ilike', "%{$s}%")
                    ->orWhere('email', 'ilike', "%{$s}%");
            });
        }

        // filter is_active
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // filter role_id (pivot)
        if ($request->filled('role_id')) {
            $roleId = (int) $request->input('role_id');
            $q->whereHas('roles', fn($r) => $r->where('roles.id', $roleId));
        }

        $perPage = (int) $request->input('per_page', 10);

        $data = $q->orderBy('name')->paginate($perPage);

        // optional: tambah role_names biar UI gampang
        $data->getCollection()->transform(function ($u) {
            $u->role_names = $u->roles?->pluck('nama')?->values() ?? [];
            return $u;
        });

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],

            'cabang_id' => ['nullable', 'integer'],
            'departemen_id' => ['nullable', 'integer'],

            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
            'cabang_id' => $data['cabang_id'] ?? null,
            'departemen_id' => $data['departemen_id'] ?? null,
        ]);

        // attach roles
        if (!empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        return response()->json(
            $user->load(['cabang:id,nama', 'departemen:id,nama', 'roles:id,nama']),
            201
        );
    }

    public function show(User $user)
    {
        $user->load(['cabang:id,nama', 'departemen:id,nama', 'roles:id,nama']);
        $user->role_ids = $user->roles->pluck('id')->values();

        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'is_active' => ['nullable', 'boolean'],

            'cabang_id' => ['nullable', 'integer'],
            'departemen_id' => ['nullable', 'integer'],

            // password optional saat edit
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],

            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'] ?? $user->is_active,
            'cabang_id' => $data['cabang_id'] ?? null,
            'departemen_id' => $data['departemen_id'] ?? null,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        // sync roles (kalau dikirim)
        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids'] ?? []);
        }

        return response()->json(
            $user->load(['cabang:id,nama', 'departemen:id,nama', 'roles:id,nama'])
        );
    }

    public function destroy(User $user)
    {
        $user->roles()->detach();
        $user->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function checkUserSignature()
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'has_signature' => !empty($user->signature_path),
            'signature_path' => $user->signature_path,
        ]);
    }

    public function storeUserSignature(Request $request)
    {
        $request->validate([
            'signature' => ['required', 'string'],
        ]);

        $user = User::find(Auth::id());

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        $signatureData = $request->signature;

        if (!str_contains($signatureData, 'base64')) {
            return response()->json([
                'success' => false,
                'message' => 'Format tanda tangan tidak valid.',
            ], 422);
        }

        $image = str_replace('data:image/png;base64,', '', $signatureData);
        $image = str_replace(' ', '+', $image);

        $folderName = $user->id . '_' . Str::slug($user->name, '_');
        $folderPath = 'syopv4/uploads/users/signature/' . $folderName;

        Storage::disk('public')->makeDirectory($folderPath);

        $fileName = 'signature.png';
        $filePath = $folderPath . '/' . $fileName;

        Storage::disk('public')->put($filePath, base64_decode($image));

        $user->signature_path = $filePath;
        $user->signature_uploaded_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Tanda tangan berhasil disimpan.',
            'data' => [
                'signature_path' => $filePath,
                'signature_url' => asset('storage/' . $filePath),
            ],
        ], 201);
    }
}
