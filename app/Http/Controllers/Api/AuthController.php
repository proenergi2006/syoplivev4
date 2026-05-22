<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email atau password salah'], 401);
        }

        if (isset($user->is_active) && !$user->is_active) {
            return response()->json(['message' => 'User nonaktif'], 403);
        }

        $token = $user->createToken('syop-v4')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
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

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
