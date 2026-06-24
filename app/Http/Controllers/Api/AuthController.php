<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
            ], [
                'username.required' => 'Username wajib diisi.',
                'password.required' => 'Password wajib diisi.',
            ]);

            $user = User::where('username', $request->username)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'field' => 'username',
                    'message' => 'Username tidak ditemukan.',
                ], 401);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'field' => 'password',
                    'message' => 'Password salah.',
                ], 401);
            }

            if (isset($user->is_active) && !$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'User nonaktif.',
                ], 403);
            }

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            $token = $user->createToken('syop-v4')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil.',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Auth] Login error', [
                'username' => $request->username,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat login.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function me(Request $request)
    {
        // return response()->json($request->user());
        // $user = auth()->user()->load('roles');

        // return response()->json([
        //     'id' => $user->id,
        //     'name' => $user->name,
        //     'role' => $user->roles()->value('nama'), // atau 'code'
        // ]);
        $user = $request->user()->load([
            'roles:id,nama',
            'cabangData:id,nama_cabang,inisial_cabang',
            'departmentData:id,kode,nama',
        ]);

        $primaryRole = $user->roles->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,

                'role_id' => $primaryRole->id ?? null,
                'role' => $primaryRole->nama ?? null,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->nama,
                    ];
                })->values(),

                'cabang_id' => $user->cabang_id,
                'cabang' => $user->cabangData
                    ? trim(($user->cabangData->inisial_cabang ?? '-') . ' - ' . ($user->cabangData->nama_cabang ?? '-'))
                    : null,

                'department_id' => $user->departemen_id,
                'department' => $user->departmentData
                    ? trim(($user->departmentData->kode ?? '-') . ' - ' . ($user->departmentData->nama ?? '-'))
                    : null,
            ],
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permission user berhasil dimuat.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'permissions' => $user->getPermissionAbilities(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Auth Permission] Load error', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat permission user.',
            ], 500);
        }
    }
    

    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan.',
                ], 401);
            }

            $user->forceFill([
                'last_logout_at' => now(),
            ])->save();

            $token = $user->currentAccessToken();

            if ($token) {
                $token->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Auth] Logout error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal logout.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function sso(Request $request)
    {
        $request->validate([
            'email' => 'required'
        ]);

        $user = User::where(
            'email',
            $request->email
        )->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        $token = $user->createToken('syop-v4')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
