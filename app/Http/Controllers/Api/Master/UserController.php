<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 0 ? $perPage : 10;
            $perPage = $perPage > 100 ? 100 : $perPage;

            $search = trim((string) $request->input('search', ''));
            $status = $request->input('is_active', null);
            $roleId = $request->input('role_id', null);

            $query = User::query()
                ->with([
                    /*
                    |--------------------------------------------------------------------------
                    | Jangan pakai cabang:id,nama
                    |--------------------------------------------------------------------------
                    | Karena table cabang tidak selalu punya column nama.
                    | Bisa saja column-nya nama_cabang / inisial_cabang.
                    |--------------------------------------------------------------------------
                    */
                    'cabang',
                    'departemen',
                    'roles',
                ]);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%");

                    if (Schema::hasColumn('users', 'username')) {
                        $q->orWhere('username', 'ILIKE', "%{$search}%");
                    }
                });
            }

            if ($status !== null && $status !== '' && $status !== 'all') {
                $query->where('is_active', filter_var($status, FILTER_VALIDATE_BOOLEAN));
            }

            if ($roleId !== null && $roleId !== '' && $roleId !== 'all') {
                $query->whereHas('roles', function ($q) use ($roleId) {
                    $q->where('roles.id', (int) $roleId);
                });
            }

            $users = $query
                ->orderBy('name')
                ->paginate($perPage);

            $users->getCollection()->transform(function (User $user) {
                return $this->transformUser($user);
            });

            return response()->json([
                'success' => true,
                'message' => 'Data user berhasil dimuat.',
                'data' => $users,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Users] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data user.',
                'data' => [],
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'username' => [
                    'nullable',
                    'string',
                    'max:120',
                    Rule::unique('users', 'username'),
                ],
                'email' => ['required', 'email', 'max:160'],
                'password' => ['required', 'string', 'min:6', 'confirmed'],
                'is_active' => ['nullable', 'boolean'],

                'cabang_id' => ['nullable', 'integer'],
                'departemen_id' => ['nullable', 'integer'],

                'role_ids' => ['nullable', 'array'],
                'role_ids.*' => ['integer', 'exists:roles,id'],
            ]);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'cabang_id' => $data['cabang_id'] ?? null,
                'departemen_id' => $data['departemen_id'] ?? null,
            ];

            if (Schema::hasColumn('users', 'username')) {
                $payload['username'] = $data['username'] ?? null;
            }

            $user = User::create($payload);

            $roleIds = $data['role_ids'] ?? [];
            $user->roles()->sync($roleIds);

            /*
            |--------------------------------------------------------------------------
            | Sync pivot user_cabang jika table tersedia
            |--------------------------------------------------------------------------
            | Saat ini FE hanya kirim satu cabang_id.
            |--------------------------------------------------------------------------
            */
            $this->syncUserCabangPivot($user, $data['cabang_id'] ?? null);

            DB::commit();

            $user->load([
                'cabang',
                'departemen',
                'roles',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User berhasil ditambahkan.',
                'data' => $this->transformUser($user),
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Users] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->except(['password', 'password_confirmation']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan user.',
            ], 500);
        }
    }

    public function show($user): JsonResponse
    {
        try {
            $userModel = $this->resolveUser($user);

            $userModel->load([
                'cabang',
                'departemen',
                'roles',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Detail user berhasil dimuat.',
                'data' => $this->transformUser($userModel),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Users] Show error', [
                'user' => $user,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail user.',
            ], 500);
        }
    }

    public function update(Request $request, $user): JsonResponse
    {
        DB::beginTransaction();

        try {
            $userModel = $this->resolveUser($user);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'username' => [
                    'nullable',
                    'string',
                    'max:120',
                    Rule::unique('users', 'username')->ignore($userModel->id),
                ],
                'email' => [
                    'required',
                    'email',
                    'max:160',
                ],
                'is_active' => ['nullable', 'boolean'],

                'cabang_id' => ['nullable', 'integer'],
                'departemen_id' => ['nullable', 'integer'],

                'password' => ['nullable', 'string', 'min:6', 'confirmed'],

                'role_ids' => ['nullable', 'array'],
                'role_ids.*' => ['integer', 'exists:roles,id'],
            ]);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'] ?? $userModel->is_active,
                'cabang_id' => $data['cabang_id'] ?? null,
                'departemen_id' => $data['departemen_id'] ?? null,
            ];

            if (Schema::hasColumn('users', 'username')) {
                $payload['username'] = $data['username'] ?? null;
            }

            if (!empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }

            $userModel->update($payload);

            if (array_key_exists('role_ids', $data)) {
                $userModel->roles()->sync($data['role_ids'] ?? []);
            }

            $this->syncUserCabangPivot($userModel, $data['cabang_id'] ?? null);

            DB::commit();

            $userModel->load([
                'cabang',
                'departemen',
                'roles',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User berhasil diperbarui.',
                'data' => $this->transformUser($userModel),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Users] Update error', [
                'user' => $user,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->except(['password', 'password_confirmation']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui user.',
            ], 500);
        }
    }

    public function destroy($user): JsonResponse
    {
        DB::beginTransaction();

        try {
            $userModel = $this->resolveUser($user);

            $userModel->roles()->detach();

            if (Schema::hasTable('user_cabang')) {
                DB::table('user_cabang')
                    ->where('user_id', $userModel->id)
                    ->delete();
            }

            $userModel->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Users] Destroy error', [
                'user' => $user,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus user.',
            ], 500);
        }
    }

    public function checkUserSignature(): JsonResponse
    {
        try {
            $user = auth()->user();

            return response()->json([
                'success' => true,
                'has_signature' => !empty($user?->signature_path),
                'signature_path' => $user?->signature_path,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Users] Check signature error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa tanda tangan user.',
                'has_signature' => false,
                'signature_path' => null,
            ], 500);
        }
    }

    public function storeUserSignature(Request $request): JsonResponse
    {
        try {
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Users] Store signature error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan tanda tangan user.',
            ], 500);
        }
    }

    public function dropdown(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->input('search', ''));

            $query = User::query()
                ->select([
                    'id',
                    'name',
                    'email',
                ])
                ->when(Schema::hasColumn('users', 'id_role'), function ($query) {
                    $query->addSelect('id_role');
                })
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('email', 'ILIKE', "%{$search}%");
                    });
                })
                ->orderBy('name');

            if (Schema::hasColumn('users', 'is_active')) {
                $query->where('is_active', true);
            }

            $users = $query
                ->limit(100)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'title' => trim($user->name . ' - ' . $user->email),
                        'id_role' => $user->id_role ?? null,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data user dropdown berhasil dimuat.',
                'data' => $users,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Users] Dropdown error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data user dropdown.',
                'data' => [],
            ], 500);
        }
    }

    private function resolveUser($value): User
    {
        if ($value instanceof User) {
            return $value;
        }

        return User::query()->findOrFail((int) $value);
    }

    private function syncUserCabangPivot(User $user, ?int $cabangId): void
    {
        if (!Schema::hasTable('user_cabang')) {
            return;
        }

        DB::table('user_cabang')
            ->where('user_id', $user->id)
            ->delete();

        if (!$cabangId) {
            return;
        }

        DB::table('user_cabang')->insertOrIgnore([
            'user_id' => $user->id,
            'cabang_id' => $cabangId,
        ]);
    }

    private function transformUser(User $user): array
    {
        $roles = $user->roles ?? collect();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username ?? null,
            'email' => $user->email,

            'cabang_id' => $user->cabang_id,
            'cabang' => $user->cabang
                ? [
                    'id' => $user->cabang->id,
                    'nama' => $this->getCabangName($user->cabang),
                    'nama_cabang' => $this->getCabangName($user->cabang),
                    'inisial_cabang' => $user->cabang->inisial_cabang ?? null,
                ]
                : null,

            'departemen_id' => $user->departemen_id,
            'departemen' => $user->departemen
                ? [
                    'id' => $user->departemen->id,
                    'nama' => $this->getDepartmentName($user->departemen),
                    'kode' => $user->departemen->kode ?? null,
                ]
                : null,

            'roles' => $roles
                ->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'nama' => $role->nama ?? $role->name ?? '-',
                        'name' => $role->name ?? $role->nama ?? '-',
                    ];
                })
                ->values(),

            'role_ids' => $roles->pluck('id')->values(),
            'role_names' => $roles
                ->map(fn($role) => $role->nama ?? $role->name ?? '-')
                ->values(),

            'is_active' => (bool) $user->is_active,

            'signature_path' => $user->signature_path,
            'signature_uploaded_at' => optional($user->signature_uploaded_at)->toDateTimeString(),

            'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            'last_logout_at' => optional($user->last_logout_at)->toDateTimeString(),

            'created_at' => optional($user->created_at)->toDateTimeString(),
            'updated_at' => optional($user->updated_at)->toDateTimeString(),
        ];
    }

    private function getCabangName($cabang): string
    {
        return $cabang->nama
            ?? $cabang->nama_cabang
            ?? $cabang->name
            ?? $cabang->inisial_cabang
            ?? '-';
    }

    private function getDepartmentName($department): string
    {
        return $department->nama
            ?? $department->name
            ?? $department->kode
            ?? '-';
    }
}
