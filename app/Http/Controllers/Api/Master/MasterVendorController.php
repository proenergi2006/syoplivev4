<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\MasterVendor;
use App\Models\VendorBank;
use App\Models\VendorDokumenPendukung;
use App\Models\VendorTransaksi;
use App\Models\MasterDokumenPendukung;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\ApprovalFlow;
use App\Models\MasterVendorApproval;
use App\Models\User;
use App\Models\Notification;
use App\Mail\MasterVendorApprovalMail;
use Illuminate\Support\Facades\Mail;
use App\Services\MasterVendor\MasterVendorApprovalService;

class MasterVendorController extends Controller
{

    protected MasterVendorApprovalService $vendorApprovalService;

    public function __construct(MasterVendorApprovalService $vendorApprovalService)
    {
        $this->vendorApprovalService = $vendorApprovalService;
    }

    public function index(Request $request)
    {
        try {
            $query = MasterVendor::query();

            /*
            |--------------------------------------------------------------------------
            | Filter khusus Finance
            |--------------------------------------------------------------------------
            | Jika user login adalah Finance / FIN, maka hanya tampil vendor
            | dengan status_approval PENDING REVIEW.
            |--------------------------------------------------------------------------
            */
            $user = $request->user();

            $roleCodes = [];

            if ($user && method_exists($user, 'roles')) {
                $roleCodes = $user->roles()
                    ->pluck('kode')
                    ->map(fn($kode) => strtoupper((string) $kode))
                    ->toArray();
            }

            $isFinance = in_array('FIN', $roleCodes, true)
                || in_array('FINANCE', $roleCodes, true);

            if ($isFinance) {
                $query->where('status_approval', '!=', 'DRAFT')->where('status_approval', '!=', 'REJECTED');
            }

            // search
            $search = trim((string) $request->get('search', ''));

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('kode_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%");
                });
            }

            // status
            $isActiveParam = $request->get('is_active');

            if ($isActiveParam !== null && $isActiveParam !== '' && $isActiveParam !== 'all') {
                $isActive = filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($isActive !== null) {
                    $query->where('is_active', $isActive);
                }
            }

            $perPage = (int) $request->get('per_page', 10);
            if ($perPage <= 0) {
                $perPage = 10;
            }

            $data = $query
                ->orderBy('id', 'desc')
                ->paginate($perPage);

            $items = collect($data->items())->map(function ($item) {
                $item->public_id = Crypt::encryptString((string) $item->id);
                return $item;
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $items,
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat data vendor', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $lastVendor = MasterVendor::where('kode_vendor', 'like', 'TEMP-%')
                ->orderBy('kode_vendor', 'desc')
                ->first();

            if ($lastVendor) {
                $lastNumber = (int) str_replace('TEMP-', '', $lastVendor->kode_vendor);
                $newNumber = $lastNumber + 1;
            } else {
                $newNumber = 1;
            }

            $kodeVendor = 'TEMP-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            $clean = fn($v) => is_null($v) ? null : htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $vendor = MasterVendor::create([
                'nama_vendor'       => $clean($request->nama_vendor),
                'kode_vendor'       => $kodeVendor,
                'inisial_vendor'    => $clean($request->inisial_vendor),
                'telepon'           => $clean($request->telepon),
                'fax'               => $clean($request->fax),
                'email'             => $clean($request->email),
                'jenis_perusahaan'  => $clean($request->jenis_perusahaan),
                'kategori_vendor'   => $clean($request->kategori_vendor),
                'id_department'     => $clean($request->id_department),
                'no_ktp'            => $clean($request->nomor_ktp),
                'alamat'            => $clean($request->alamat),

                'nama_pic'          => $clean($request->contact_nama),
                'jabatan_pic'       => $clean($request->contact_jabatan),
                'telp_pic'          => $clean($request->contact_hp),
                'email_pic'         => $clean($request->contact_email),

                'status_pkp'        => $clean($request->status_pkp),
                'no_npwp'           => $clean($request->npwp),
                'alamat_npwp'       => $clean($request->npwp_alamat),
                'no_sppkp'          => $clean($request->sppkp_nomor),
                'tgl_sppkp'         => $request->sppkp_tanggal ?: null,
                'alamat_sppkp'      => $clean($request->sppkp_alamat),
                'same_as_npwp'      => $request->same_as_npwp == "true" ? 1 : 0,

                'jenis_pembayaran'  => $clean($request->jenis_pembayaran),
                'top'               => $clean($request->top ?? 0),
            ]);

            $vendorId = $vendor->id;

            $transaksi = json_decode($request->transaksi_ids ?? '[]', true);
            if (is_array($transaksi) && !empty($transaksi)) {
                foreach ($transaksi as $trxId) {
                    VendorTransaksi::create([
                        'vendor_id'    => $vendorId,
                        'transaksi_id' => $trxId,
                    ]);
                }
            }

            $banks = json_decode($request->banks ?? '[]', true);

            if (is_array($banks) && !empty($banks)) {
                foreach ($banks as $index => $bank) {

                    if (
                        empty($bank['bank_id']) &&
                        empty($bank['atas_nama']) &&
                        empty($bank['nomor_rekening']) &&
                        empty($bank['cabang']) &&
                        empty($bank['alamat_bank'])
                    ) {
                        continue;
                    }

                    if (empty($bank['bank_id'])) {

                        Log::error('Validasi vendor gagal: bank kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " bank wajib diisi.",
                        ], 422);
                    }

                    if (empty($bank['atas_nama'])) {

                        Log::error('Validasi vendor gagal: atas nama kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " atas nama wajib diisi.",
                        ], 422);
                    }

                    if (empty($bank['nomor_rekening'])) {

                        Log::error('Validasi vendor gagal: nomor rekening kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " nomor rekening wajib diisi.",
                        ], 422);
                    }

                    VendorBank::create([
                        'vendor_id' => $vendorId,
                        'bank_id' => $bank['bank_id'] ?? null,
                        'atas_nama' => $bank['atas_nama'] ?? null,
                        'nomor_rekening' => $bank['nomor_rekening'] ?? null,
                        'cabang' => $bank['cabang'] ?? null,
                        'alamat_bank' => $bank['alamat_bank'] ?? null,
                        'swift_code_snapshot' => $bank['swift_code_snapshot'] ?? null,
                        'is_active' => true,
                    ]);
                }
            }

            $selectedDokumen = json_decode($request->dokumen_pendukung ?? '[]', true);
            $selectedDokumen = is_array($selectedDokumen) ? array_map('intval', $selectedDokumen) : [];

            $dokumenFiles = $request->file('dokumen_files', []);

            $namaVendor = $clean($request->nama_vendor);
            $vendorSlug = Str::slug($namaVendor);

            if ($vendorSlug === '') {
                $vendorSlug = 'vendor';
            }

            if (!empty($dokumenFiles)) {
                foreach ($dokumenFiles as $docId => $files) {
                    $docId = (int) $docId;

                    if (!in_array($docId, $selectedDokumen, true)) {
                        continue;
                    }

                    $masterDoc = MasterDokumenPendukung::find($docId);
                    if (!$masterDoc) {
                        continue;
                    }

                    $docSlug = $masterDoc->slug
                        ? Str::slug($masterDoc->slug)
                        : Str::slug($masterDoc->nama_dokumen);

                    if ($docSlug === '') {
                        $docSlug = 'dokumen-' . $docId;
                    }

                    $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$vendorId}_{$vendorSlug}/{$docSlug}";

                    // Buat folder jika belum ada
                    Storage::disk('public')->makeDirectory($folder);

                    // Set permission folder jadi all access
                    $fullFolderPath = storage_path('app/public/' . $folder);

                    if (File::exists($fullFolderPath)) {
                        @chmod($fullFolderPath, 0777);
                        @chmod(dirname($fullFolderPath), 0777);
                    }

                    $files = is_array($files) ? $files : [$files];

                    foreach ($files as $file) {
                        if (!$file || !$file->isValid()) {
                            continue;
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = strtolower($file->getClientOriginalExtension());

                        $safeOriginalName = Str::slug($originalName);

                        if ($safeOriginalName === '') {
                            $safeOriginalName = 'file';
                        }

                        $filename = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                        $path = $file->storeAs($folder, $filename, 'public');

                        // Set permission file jadi all access
                        $fullFilePath = storage_path('app/public/' . $path);

                        if (File::exists($fullFilePath)) {
                            @chmod($fullFilePath, 0777);
                        }

                        VendorDokumenPendukung::create([
                            'vendor_id'  => $vendorId,
                            'dokumen_id' => $docId,
                            'file_name'  => $filename,
                            'file_path'  => $path,
                            'file_size'  => $file->getSize(),
                            'file_type'  => $file->getMimeType(),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Vendor berhasil dibuat!',
                'vendor_id' => $vendorId,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal membuat vendor', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat vendor.',
            ], 500);
        }
    }

    public function destroy(string $publicId)
    {
        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);
            $vendorName = $vendor->nama_vendor;

            // Ambil semua dokumen vendor untuk hapus file fisik
            $dokumenPendukung = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();

            foreach ($dokumenPendukung as $dokumen) {
                if ($dokumen->file_path && Storage::disk('public')->exists($dokumen->file_path)) {
                    Storage::disk('public')->delete($dokumen->file_path);
                }
            }

            $vendor->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Vendor {$vendorName} berhasil dihapus.",
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal menghapus vendor', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus vendor.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, string $publicId)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'is_active' => $request->boolean('is_active'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Status vendor berhasil diupdate.',
                'data' => $vendor->fresh(),
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate status vendor', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status vendor.',
            ], 500);
        }
    }

    public function show(string $publicId)
    {
        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::with([
                'banks.masterBank',
                'transaksi:id,vendor_id,transaksi_id',
                'dokumenPendukung:id,vendor_id,dokumen_id,file_name,file_path',
                'department'
            ])->findOrFail($vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Detail vendor berhasil dimuat.',
                'data' => [
                    'public_id' => Crypt::encryptString((string) $vendor->id),
                    'nama_vendor' => $vendor->nama_vendor,
                    'inisial_vendor' => $vendor->inisial_vendor,
                    'telepon' => $vendor->telepon,
                    'fax' => $vendor->fax,
                    'email' => $vendor->email,
                    'jenis_perusahaan' => $vendor->jenis_perusahaan,
                    'kategori_vendor' => $vendor->kategori_vendor,
                    'department' => [
                        'id' => $vendor->department?->id,
                        'kode' => $vendor->department?->kode,
                        'nama' => $vendor->department?->nama,
                        'label' => trim(($vendor->department?->kode ?? '-') . ' - ' . ($vendor->department?->nama ?? '-')),
                    ],
                    'nomor_ktp' => $vendor->nomor_ktp,
                    'alamat' => $vendor->alamat,
                    'is_active' => $vendor->is_active,

                    'contact_nama' => $vendor->nama_pic,
                    'contact_jabatan' => $vendor->jabatan_pic,
                    'contact_hp' => $vendor->telp_pic,
                    'contact_email' => $vendor->email_pic,

                    'status_pkp' => $vendor->status_pkp,
                    'npwp' => $vendor->no_npwp,
                    'npwp_alamat' => $vendor->alamat_npwp,
                    'sppkp_nomor' => $vendor->no_sppkp,
                    'sppkp_tanggal' => $vendor->tgl_sppkp
                        ? Carbon::parse($vendor->tgl_sppkp)->format('Y-m-d')
                        : null,
                    'sppkp_alamat' => $vendor->alamat_sppkp,
                    'same_as_npwp' => (bool) $vendor->same_as_npwp,

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'transaksi_ids' => $vendor->transaksi->pluck('transaksi_id')->values(),
                    'dokumen_ids' => $vendor->dokumenPendukung->pluck('dokumen_id')->values(),

                    'dokumen_files' => $vendor->dokumenPendukung->map(function ($dokumen) {
                        return [
                            'id' => $dokumen->id,
                            'dokumen_id' => $dokumen->dokumen_id,
                            'file_name' => $dokumen->file_name,
                            'file_path' => $dokumen->file_path,
                            'file_url' => $dokumen->file_path ? asset('storage/' . $dokumen->file_path) : null,
                        ];
                    })->values(),

                    'banks' => $vendor->banks->map(function ($bank) {
                        return [
                            'id' => $bank->id,
                            'bank_id' => $bank->bank_id,
                            'nama_bank' => $bank->masterBank->nama_bank ?? '-',
                            'nama_bank_pendek' => $bank->masterBank->nama_bank_pendek ?? null,
                            'kode_bank' => $bank->masterBank->kode_bank ?? null,
                            'atas_nama' => $bank->atas_nama,
                            'nomor_rekening' => $bank->nomor_rekening,
                            'cabang' => $bank->cabang,
                            'alamat_bank' => $bank->alamat_bank,
                            'swift_code' => $bank->swift_code_snapshot ?? ($bank->masterBank->swift_code ?? null),
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (DecryptException $e) {
            Log::warning('Public ID vendor tidak valid', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid',
            ], 404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Vendor tidak ditemukan', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat detail vendor', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor',
            ], 500);
        }
    }

    public function update(Request $request, string $publicId)
    {
        $request->validate([
            'nama_vendor' => ['required', 'string', 'max:255'],
            'inisial_vendor' => ['required', 'string', 'max:50'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'jenis_perusahaan' => ['required'],
            'kategori_vendor' => ['required'],
            'nomor_ktp' => ['nullable', 'string', 'max:100'],
            'alamat' => ['nullable', 'string'],

            'contact_nama' => ['nullable', 'string', 'max:255'],
            'contact_jabatan' => ['nullable', 'string', 'max:255'],
            'contact_hp' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],

            'status_pkp' => ['nullable'],
            'npwp' => ['nullable', 'string', 'max:100'],
            'npwp_alamat' => ['nullable', 'string'],
            'sppkp_nomor' => ['nullable', 'string', 'max:100'],
            'sppkp_tanggal' => ['nullable', 'date'],
            'sppkp_alamat' => ['nullable', 'string'],
            'same_as_npwp' => ['nullable', 'boolean'],

            'jenis_pembayaran' => ['nullable'],
            'top' => ['nullable'],

            'transaksi_ids' => ['nullable', 'array'],
            'transaksi_ids.*' => ['integer'],

            'dokumen_ids' => ['nullable', 'array'],
            'dokumen_ids.*' => ['integer'],

            'banks' => ['nullable'],
            'banks.*.id' => ['nullable', 'integer'],
            'banks.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'banks.*.atas_nama' => ['nullable', 'string', 'max:255'],
            'banks.*.nomor_rekening' => ['nullable', 'string', 'max:100'],
            'banks.*.cabang' => ['nullable', 'string', 'max:255'],
            'banks.*.alamat_bank' => ['nullable', 'string'],
            'banks.*.swift_code' => ['nullable', 'string', 'max:100'],

            'dokumen_existing_ids' => ['nullable', 'array'],
            'dokumen_existing_ids.*' => ['nullable', 'array'],
            'dokumen_existing_ids.*.*' => ['integer'],

            'dokumen_files' => ['nullable', 'array'],
            'dokumen_files.*' => ['nullable', 'array'],
            'dokumen_files.*.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $clean = fn($v) => is_null($v) ? null : htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);
            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'nama_vendor' => $clean($request->nama_vendor),
                'inisial_vendor' => $clean($request->inisial_vendor),
                'telepon' => $clean($request->telepon),
                'fax' => $clean($request->fax),
                'email' => $clean($request->email),
                'jenis_perusahaan' => $clean($request->jenis_perusahaan),
                'kategori_vendor' => $clean($request->kategori_vendor),
                'no_ktp' => $clean($request->nomor_ktp),
                'alamat' => $clean($request->alamat),

                'nama_pic' => $clean($request->contact_nama),
                'jabatan_pic' => $clean($request->contact_jabatan),
                'telp_pic' => $clean($request->contact_hp),
                'email_pic' => $clean($request->contact_email),

                'status_pkp' => $clean($request->status_pkp),
                'no_npwp' => $clean($request->npwp),
                'alamat_npwp' => $clean($request->npwp_alamat),
                'no_sppkp' => $clean($request->sppkp_nomor),
                'tgl_sppkp' => $request->filled('sppkp_tanggal')
                    ? Carbon::parse($request->sppkp_tanggal)->format('Y-m-d')
                    : null,
                'alamat_sppkp' => $clean($request->sppkp_alamat),
                'same_as_npwp' => $clean($request->boolean('same_as_npwp')),

                'jenis_pembayaran' => $clean($request->jenis_pembayaran),
                'top' => $request->filled('top') ? $request->top : null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 1. Sinkron transaksi vendor
        |--------------------------------------------------------------------------
        */
            $transaksiIds = collect($request->input('transaksi_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            VendorTransaksi::where('vendor_id', $vendor->id)
                ->whereNotIn('transaksi_id', $transaksiIds->all())
                ->delete();

            foreach ($transaksiIds as $transaksiId) {
                VendorTransaksi::updateOrCreate(
                    [
                        'vendor_id' => $vendor->id,
                        'transaksi_id' => $transaksiId,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Sinkron bank vendor
            |--------------------------------------------------------------------------
            */
            $banks = $request->input('banks', []);

            if (is_string($banks)) {
                $decodedBanks = json_decode($banks, true);
                $banks = is_array($decodedBanks) ? $decodedBanks : [];
            }

            $banks = collect($banks);

            $bankIdsToKeep = [];

            foreach ($banks as $index => $bankData) {
                $isEmpty =
                    blank($bankData['bank_id'] ?? null) &&
                    blank($bankData['atas_nama'] ?? null) &&
                    blank($bankData['nomor_rekening'] ?? null) &&
                    blank($bankData['cabang'] ?? null) &&
                    blank($bankData['alamat_bank'] ?? null);

                if ($isEmpty) {
                    continue;
                }

                if (blank($bankData['bank_id'] ?? null)) {
                    Log::warning('Update vendor gagal: bank_id kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' nama bank wajib dipilih.',
                    ], 422);
                }

                if (blank($bankData['atas_nama'] ?? null)) {
                    Log::warning('Update vendor gagal: atas_nama kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' atas nama wajib diisi.',
                    ], 422);
                }

                if (blank($bankData['nomor_rekening'] ?? null)) {
                    Log::warning('Update vendor gagal: nomor_rekening kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' nomor rekening wajib diisi.',
                    ], 422);
                }

                $payload = [
                    'bank_id' => $bankData['bank_id'] ?? null,
                    'atas_nama' => $bankData['atas_nama'] ?? null,
                    'nomor_rekening' => $bankData['nomor_rekening'] ?? null,
                    'cabang' => $bankData['cabang'] ?? null,
                    'alamat_bank' => $bankData['alamat_bank'] ?? null,
                    'swift_code_snapshot' => $bankData['swift_code_snapshot'] ?? null,
                    'is_active' => true,
                ];

                if (!empty($bankData['id'])) {
                    $bank = VendorBank::where('vendor_id', $vendor->id)
                        ->where('id', $bankData['id'])
                        ->first();

                    if ($bank) {
                        $bank->update($payload);

                        $bankIdsToKeep[] = $bank->id;
                        continue;
                    }
                }

                $newBank = VendorBank::create([
                    'vendor_id' => $vendor->id,
                ] + $payload);

                $bankIdsToKeep[] = $newBank->id;
            }

            if (!empty($bankIdsToKeep)) {
                VendorBank::where('vendor_id', $vendor->id)
                    ->whereNotIn('id', $bankIdsToKeep)
                    ->delete();
            } else {
                VendorBank::where('vendor_id', $vendor->id)->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Sinkron dokumen pendukung
            |--------------------------------------------------------------------------
            */

            $pathsToDelete = [];

            $dokumenIds = collect($request->input('dokumen_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $dokumenExistingIds = collect($request->input('dokumen_existing_ids', []));

            /*
            |--------------------------------------------------------------------------
            | 1. Hapus record DB file lama yang tidak dipertahankan
            |    File fisik jangan dihapus dulu, hanya tampung path-nya.
            |--------------------------------------------------------------------------
            */
            foreach ($dokumenIds as $dokumenId) {
                $keepIdsForDokumen = collect($dokumenExistingIds->get((string) $dokumenId, []))
                    ->filter(fn($id) => $id !== null && $id !== '')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->all();

                $oldFilesQuery = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                    ->where('dokumen_id', $dokumenId);

                $filesToDelete = !empty($keepIdsForDokumen)
                    ? (clone $oldFilesQuery)->whereNotIn('id', $keepIdsForDokumen)->get()
                    : $oldFilesQuery->get();

                foreach ($filesToDelete as $file) {
                    if ($file->file_path) {
                        $pathsToDelete[] = $file->file_path;
                    }

                    $file->delete();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Hapus record DB dari dokumen yang sudah tidak dipilih
            |--------------------------------------------------------------------------
            */
            $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                ->when(
                    $dokumenIds->isNotEmpty(),
                    fn($query) => $query->whereNotIn('dokumen_id', $dokumenIds->all())
                )
                ->get();

            if ($dokumenIds->isEmpty()) {
                $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();
            }

            foreach ($dokumenYangDihapusTotal as $file) {
                if ($file->file_path) {
                    $pathsToDelete[] = $file->file_path;
                }

                $file->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Simpan file baru
            |--------------------------------------------------------------------------
            */
            $uploadedDokumenFiles = $request->file('dokumen_files', []);

            $namaVendor = $clean($request->nama_vendor);
            $vendorSlug = Str::slug($namaVendor);

            if ($vendorSlug === '') {
                $vendorSlug = 'vendor';
            }

            foreach ($uploadedDokumenFiles as $dokumenId => $files) {
                $dokumenId = (int) $dokumenId;

                if (!$dokumenIds->contains($dokumenId)) {
                    continue;
                }

                $masterDoc = MasterDokumenPendukung::find($dokumenId);

                if (!$masterDoc) {
                    continue;
                }

                $docSlug = $masterDoc->slug
                    ? Str::slug($masterDoc->slug)
                    : Str::slug($masterDoc->nama_dokumen);

                if ($docSlug === '') {
                    $docSlug = 'dokumen-' . $dokumenId;
                }

                $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$vendor->id}_{$vendorSlug}/{$docSlug}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                    @chmod(dirname($fullFolderPath), 0777);
                }

                $files = is_array($files) ? $files : [$files];

                foreach ($files as $file) {
                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());

                    $safeOriginalName = Str::slug($originalName);

                    if ($safeOriginalName === '') {
                        $safeOriginalName = 'file';
                    }

                    $filename = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                    $storedPath = $file->storeAs($folder, $filename, 'public');

                    $fullFilePath = storage_path('app/public/' . $storedPath);

                    if (File::exists($fullFilePath)) {
                        @chmod($fullFilePath, 0777);
                    }

                    VendorDokumenPendukung::create([
                        'vendor_id'  => $vendor->id,
                        'dokumen_id' => $dokumenId,
                        'file_name'  => $filename,
                        'file_path'  => $storedPath,
                        'file_size'  => $file->getSize(),
                        'file_type'  => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            foreach (array_unique($pathsToDelete) as $oldPath) {
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil diperbarui.',
                'data' => [
                    'public_id' => Crypt::encryptString((string) $vendor->id),
                ],
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate vendor', [
                'public_id' => $publicId,
                'request' => $request->except(['dokumen_files']),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate vendor.',
            ], 500);
        }
    }

    public function dropdownSelect(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function dropdownSelectForPurchaseRequest(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('status_approval')
                        ->orWhere('status_approval', '!=', 'REJECTED');
                })
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function dropdownSelectForPurchaseOrder(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->where('status_approval', 'APPROVED')
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function submit($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($id);

            if (strtoupper((string) $vendor->status_approval) !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Vendor hanya dapat disubmit jika status masih DRAFT.',
                ], 422);
            }

            $user = request()->user();

            $flow = ApprovalFlow::with(['steps' => function ($q) {
                $q->orderBy('step_order');
            }])
                ->where('module_name', 'MASTER_VENDOR')
                ->where('is_active', true)
                ->first();

            if (!$flow || $flow->steps->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Approval flow Master Vendor belum dikonfigurasi.',
                ], 422);
            }

            MasterVendorApproval::where('vendor_id', $vendor->id)->delete();

            foreach ($flow->steps as $step) {
                MasterVendorApproval::create([
                    'vendor_id' => $vendor->id,
                    'approval_flow_id' => $flow->id,
                    'approval_flow_step_id' => $step->id,
                    'step_order' => $step->step_order,
                    'approver_type' => $step->approver_type,
                    'approver_id' => $step->approver_id,
                    'status' => 'PENDING',
                ]);
            }

            $vendor->status_approval = 'PENDING REVIEW';
            $vendor->submitted_at = now();
            $vendor->submitted_by = $user->id;
            $vendor->save();

            $firstApproval = MasterVendorApproval::where('vendor_id', $vendor->id)
                ->where('status', 'PENDING')
                ->orderBy('step_order')
                ->first();

            if ($firstApproval) {
                $approvers = $this->vendorApprovalService->resolveApprovers($firstApproval);

                foreach ($approvers as $approver) {
                    Notification::create([
                        'user_id' => $approver->id,
                        'type' => 'master_vendor_approval',
                        'title' => 'Approval Master Vendor',
                        'message' => 'Vendor ' . $vendor->nama_vendor . ' menunggu approval Anda.',
                        'module' => 'master_vendor',
                        'reference_type' => MasterVendor::class,
                        'reference_id' => $vendor->id,
                        'reference_public_id' => $vendor->encrypted_id,
                        'url' => '/master/vendor',
                    ]);

                    try {
                        Mail::to($approver->email)->queue(
                            new MasterVendorApprovalMail($vendor, $approver, 'approval_request')
                        );
                    } catch (\Throwable $mailError) {
                        Log::error('[Vendor] Email approval gagal dikirim', [
                            'vendor_id' => $vendor->id,
                            'approver_id' => $approver->id,
                            'message' => $mailError->getMessage(),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor berhasil disubmit.',
                'data' => [
                    'id' => $vendor->id,
                    'public_id' => $vendor->encrypted_id,
                    'nama_vendor' => $vendor->nama_vendor,
                    'status_approval' => $vendor->status_approval,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Vendor] Submit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Vendor.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function approve($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::with(['approvals'])->findOrFail($id);

            if (strtoupper((string) $vendor->status_approval) !== 'PENDING REVIEW') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Vendor hanya dapat diapprove jika status masih PENDING REVIEW.',
                ], 422);
            }

            $user = request()->user();

            $currentApproval = MasterVendorApproval::where('vendor_id', $vendor->id)
                ->where('status', 'PENDING')
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if (!$currentApproval) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada approval pending untuk vendor ini.',
                ], 422);
            }

            $currentApproval->update([
                'status' => 'APPROVED',
                'approver_name_snapshot' => $user->name,
                'approved_at' => now(),
            ]);

            $hasPendingApproval = MasterVendorApproval::where('vendor_id', $vendor->id)
                ->where('status', 'PENDING')
                ->exists();

            if (!$hasPendingApproval) {
                $vendor->status_approval = 'APPROVED';
                $vendor->save();
            }

            $vendor->refresh();

            $submitter = User::find($vendor->submitted_by);

            if ($submitter) {
                Notification::create([
                    'user_id' => $submitter->id,
                    'type' => $hasPendingApproval
                        ? 'master_vendor_approval_step_approved'
                        : 'master_vendor_approved',
                    'title' => $hasPendingApproval
                        ? 'Tahap Approval Master Vendor Disetujui'
                        : 'Master Vendor Approved',
                    'message' => $hasPendingApproval
                        ? 'Vendor ' . $vendor->nama_vendor . ' telah disetujui oleh ' . ($user->name ?? '-') . ' dan masih menunggu approval berikutnya.'
                        : 'Vendor ' . $vendor->nama_vendor . ' telah disetujui.',
                    'module' => 'master_vendor',
                    'reference_type' => MasterVendor::class,
                    'reference_id' => $vendor->id,
                    'reference_public_id' => $vendor->encrypted_id,
                    'url' => '/master/vendor',
                ]);

                try {
                    Mail::to($submitter->email)->queue(
                        new MasterVendorApprovalMail(
                            $vendor,
                            $submitter,
                            $hasPendingApproval ? 'approved' : 'approved',
                            $user
                        )
                    );
                } catch (\Throwable $mailError) {
                    Log::error('[Vendor] Email approved gagal dikirim', [
                        'vendor_id' => $vendor->id,
                        'submitter_id' => $submitter->id,
                        'message' => $mailError->getMessage(),
                    ]);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Jika multi-level, kirim notif/email ke approver berikutnya
        |--------------------------------------------------------------------------
        */
            if ($hasPendingApproval) {
                $nextApproval = MasterVendorApproval::where('vendor_id', $vendor->id)
                    ->where('status', 'PENDING')
                    ->orderBy('step_order')
                    ->first();

                if ($nextApproval) {
                    $approvers = $this->vendorApprovalService->resolveApprovers($nextApproval);

                    foreach ($approvers as $approver) {
                        Notification::create([
                            'user_id' => $approver->id,
                            'type' => 'master_vendor_approval',
                            'title' => 'Approval Master Vendor',
                            'message' => 'Vendor ' . $vendor->nama_vendor . ' menunggu approval Anda.',
                            'module' => 'master_vendor',
                            'reference_type' => MasterVendor::class,
                            'reference_id' => $vendor->id,
                            'reference_public_id' => $vendor->encrypted_id,
                            'url' => '/master/vendor',
                        ]);

                        try {
                            Mail::to($approver->email)->queue(
                                new MasterVendorApprovalMail(
                                    $vendor,
                                    $approver,
                                    'approval_request'
                                )
                            );
                        } catch (\Throwable $mailError) {
                            Log::error('[Vendor] Email next approver gagal dikirim', [
                                'vendor_id' => $vendor->id,
                                'approver_id' => $approver->id,
                                'message' => $mailError->getMessage(),
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $hasPendingApproval
                    ? 'Approval vendor berhasil diproses.'
                    : 'Vendor berhasil diapprove.',
                'data' => [
                    'id' => $vendor->id,
                    'public_id' => $vendor->encrypted_id,
                    'nama_vendor' => $vendor->nama_vendor,
                    'status_approval' => $vendor->status_approval,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Vendor] Approve error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal approve Vendor.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function reject(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::with(['approvals'])->findOrFail($id);

            if (strtoupper((string) $vendor->status_approval) !== 'PENDING REVIEW') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Vendor hanya dapat direject jika status masih PENDING REVIEW.',
                ], 422);
            }

            $user = $request->user();

            $currentApproval = MasterVendorApproval::where('vendor_id', $vendor->id)
                ->where('status', 'PENDING')
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if (!$currentApproval) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada approval pending untuk vendor ini.',
                ], 422);
            }

            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $currentApproval->update([
                'status' => 'REJECTED',
                'approver_name_snapshot' => $user->name,
                'notes' => $clean($request->notes),
                'rejected_at' => now(),
            ]);

            MasterVendorApproval::where('vendor_id', $vendor->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'notes' => 'Cancelled karena Master Vendor direject.',
                ]);

            $vendor->status_approval = 'REJECTED';
            $vendor->save();
            $vendor->refresh();

            $submitter = User::find($vendor->submitted_by);

            if ($submitter) {
                Notification::create([
                    'user_id' => $submitter->id,
                    'type' => 'master_vendor_rejected',
                    'title' => 'Master Vendor Rejected',
                    'message' => 'Vendor ' . $vendor->nama_vendor . ' telah direject oleh ' . ($user->name ?? '-') . '.',
                    'module' => 'master_vendor',
                    'reference_type' => MasterVendor::class,
                    'reference_id' => $vendor->id,
                    'reference_public_id' => $vendor->encrypted_id,
                    'url' => '/master/vendor',
                ]);

                try {
                    Mail::to($submitter->email)->queue(
                        new MasterVendorApprovalMail(
                            $vendor,
                            $submitter,
                            'rejected',
                            $user,
                            $request->notes
                        )
                    );
                } catch (\Throwable $mailError) {
                    Log::error('[Vendor] Email rejected gagal dikirim', [
                        'vendor_id' => $vendor->id,
                        'submitter_id' => $submitter->id,
                        'message' => $mailError->getMessage(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor berhasil direject.',
                'data' => [
                    'id' => $vendor->id,
                    'public_id' => $vendor->encrypted_id,
                    'nama_vendor' => $vendor->nama_vendor,
                    'status_approval' => $vendor->status_approval,
                    'rejected_at' => $currentApproval->rejected_at,
                    'rejected_by' => $currentApproval->approver_name_snapshot,
                    'reject_notes' => $currentApproval->notes,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Vendor] Reject error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal reject Vendor.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
