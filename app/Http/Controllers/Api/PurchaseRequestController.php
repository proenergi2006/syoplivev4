<?php

namespace App\Http\Controllers\Api;

use App\Exports\PurchaseRequestExport;
use App\Http\Controllers\Controller;
use App\Models\ApprovalHistoryPR;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixPR;
use App\Models\PrAttachment;
use Illuminate\Http\Request;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestHistoryApproval;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseRequestVendor;
use App\Models\PurchaseRequestVendorAttachment;
use App\Models\PurchaseRequestVendorItem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use DocumentHelper;
use App\Helpers\ApprovalHelper;
use Illuminate\Support\Facades\File;

class PurchaseRequestController extends Controller
{
    /**
     * GET /api/purchase-requests
     * Ambil semua data PR (optional: bisa ditambah pagination nanti)
     */

    private function generateDraftPRNumber()
    {
        $year = date('Y');

        // Hitung PR yang dibuat tahun ini (baik draft maupun approved)
        $count = PurchaseRequest::whereYear('created_at', $year)->count() + 1;

        return "DRAFT/PR/$year/" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function index(Request $request)
    {
        $query = PurchaseRequest::with([
            'vendors.vendor',
            'vendors.items',
            'vendors.attachments'
        ])->orderBy('id', 'desc');

        // SEARCH
        if ($request->search) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('nomor_pr', 'LIKE', "%{$search}%")
                    ->orWhere('kategori', 'LIKE', "%{$search}%")
                    ->orWhere('cabang', 'LIKE', "%{$search}%");
            });
        }

        // FILTER STATUS
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $perPage = $request->per_page ?? 10;

        $prs = $query->paginate($perPage);

        $prs->getCollection()->transform(function ($pr) {
            return [
                'id'            => $pr->id,
                'encrypted_id'  => $pr->encrypted_id,
                'nomor_pr'      => $pr->nomor_pr,
                'tanggal_pr'    => $pr->tanggal_pr,
                'cabang'        => $pr->cabang,
                'department'    => $pr->department ?? '-',
                'kategori'      => $pr->kategori,
                'notes'         => $pr->notes,
                'status'        => $pr->status,
                'requested_by'  => $pr->requested_by,

                'vendors' => $pr->vendors->map(function ($v) {
                    return [
                        'vendor_id'   => $v->vendor_id,
                        'nama_vendor' => $v->vendor->nama_vendor ?? '-',
                        'status_pkp'  => $v->vendor->status_pkp ?? '-',
                        'price_offer' => $v->price_offer,
                        'dpp'         => $v->dpp,
                        'ppn'         => $v->ppn,
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $prs->items(),
            'meta'    => [
                'current_page' => $prs->currentPage(),
                'last_page'    => $prs->lastPage(),
                'per_page'     => $prs->perPage(),
                'total'        => $prs->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/purchase-request
     * Simpan data baru dari form (axios.post)
     */
    public function store(Request $request)
    {
        $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        $request->validate([
            'tanggal_pr'         => ['required', 'date_format:Y-m-d'],
            'cabang'             => ['required', 'string'],
            'id_department'      => ['required', 'integer'],
            'kategori'           => ['required', 'string'],
            'vendors'            => ['required', 'string'],
            'lampiran_request.*' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3000'],
        ]);

        $storedPaths = [];

        DB::beginTransaction();

        try {
            /*
            |--------------------------------------------------------------------------
            | 1. Generate Nomor PR
            |--------------------------------------------------------------------------
            */
            $nomorPr = $this->generateDraftPRNumber();

            /*
            |--------------------------------------------------------------------------
            | 2. Decode & Validasi Vendors
            |--------------------------------------------------------------------------
            */
            $vendors = json_decode($request->vendors, true);

            if (!is_array($vendors) || count($vendors) === 0) {
                throw new \Exception('Data vendor tidak valid.');
            }

            $selectedVendorCount = collect($vendors)
                ->filter(fn($v) => filter_var($v['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN))
                ->count();

            if ($selectedVendorCount !== 1) {
                throw new \Exception('Harus ada tepat 1 vendor yang dipilih.');
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Simpan Header PR
            |--------------------------------------------------------------------------
            */
            $pr = PurchaseRequest::create([
                'nomor_pr'      => $nomorPr,
                'tanggal_pr'    => $clean($request->tanggal_pr),
                'cabang'        => $clean($request->cabang),
                'id_department' => (int) $request->id_department,
                'kategori'      => $clean($request->kategori),
                'notes'         => $clean($request->notes),
                'requested_by'  => $request->user()->fullname ?? $request->user()->name ?? 'System',
                'request_date'  => now(),
                'status'        => PurchaseRequest::STATUS_DRAFT,
                'current_level' => 0,
                'total_amount'  => 0,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 4. Simpan Lampiran Request
            |--------------------------------------------------------------------------
            */
            if ($request->hasFile('lampiran_request')) {
                $folder = "syopv4/uploads/purchase_requests/{$pr->id}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (\Illuminate\Support\Facades\File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                }

                foreach ($request->file('lampiran_request') as $file) {
                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());

                    $safeOriginalName = \Illuminate\Support\Str::slug($originalName);

                    if ($safeOriginalName === '') {
                        $safeOriginalName = 'file';
                    }

                    $filename = str_replace('/', '-', $nomorPr)
                        . '_' . now()->format('YmdHis')
                        . '_' . uniqid()
                        . '_' . $safeOriginalName
                        . '.' . $extension;

                    $path = $file->storeAs($folder, $filename, 'public');

                    $storedPaths[] = $path;

                    $fullFilePath = storage_path('app/public/' . $path);

                    if (\Illuminate\Support\Facades\File::exists($fullFilePath)) {
                        @chmod($fullFilePath, 0777);
                    }

                    PrAttachment::create([
                        'purchase_request_id' => $pr->id,
                        'filename'            => $filename,
                        'original_filename'   => $file->getClientOriginalName(),
                        'mime_type'           => $file->getMimeType(),
                        'file_size'           => $file->getSize(),
                        'filepath'            => $path,
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Simpan Vendor & Item Vendor
            |--------------------------------------------------------------------------
            */
            $selectedPrice = 0;

            foreach ($vendors as $v) {
                $items = $v['items'] ?? [];

                if (!is_array($items) || count($items) === 0) {
                    throw new \Exception('Setiap vendor wajib memiliki minimal 1 item.');
                }

                $grandTotal = 0;

                foreach ($items as $item) {
                    $qty = (float) ($item['qty'] ?? 0);
                    $harga = (float) ($item['harga_unit'] ?? 0);

                    $grandTotal += $qty * $harga;
                }

                $dpp = (float) ($v['dpp'] ?? $grandTotal);
                $ppn = (float) ($v['ppn'] ?? 0);
                $isSelected = filter_var($v['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $offer = PurchaseRequestVendor::create([
                    'purchase_request_id' => $pr->id,
                    'vendor_id'           => (int) ($v['vendor_id'] ?? 0),
                    'price_offer'         => $dpp + $ppn,
                    'dpp'                 => $dpp,
                    'ppn'                 => $ppn,
                    'is_selected'         => $isSelected,
                ]);

                if ($isSelected) {
                    $selectedPrice = $offer->price_offer;
                }

                foreach ($items as $item) {
                    $qty = (float) ($item['qty'] ?? 0);
                    $harga = (float) ($item['harga_unit'] ?? 0);

                    PurchaseRequestVendorItem::create([
                        'pr_vendor_id' => $offer->id,
                        'nama_item'    => $clean($item['nama_item'] ?? ''),
                        'qty'          => $qty,
                        'satuan'       => $clean($item['satuan'] ?? ''),
                        'keterangan'   => $clean($item['keterangan'] ?? ''),
                        'harga_unit'   => $harga,
                        'subtotal'     => $qty * $harga,
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Update Total PR
            |--------------------------------------------------------------------------
            */
            $pr->update([
                'total_amount' => $selectedPrice,
            ]);

            DB::commit();

            return response()->json([
                'success'  => true,
                'message'  => 'Purchase Request berhasil disimpan.',
                'nomor_pr' => $nomorPr,
                'data'     => [
                    'id'        => $pr->id,
                    'public_id' => $pr->public_id ?? null,
                    'nomor_pr'  => $pr->nomor_pr ?? $nomorPr,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan Purchase Request.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
                'line'    => config('app.debug') ? $e->getLine() : null,
            ], 500);
        }
    }



    /**
     * GET /api/purchase-request/{id}
     * Ambil detail PR berdasarkan ID
     */
    public function show($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $pr = PurchaseRequest::with([
            // vendor
            'vendors',
            'vendors.vendor',
            'vendors.items',
            'vendors.attachments',

            // lampiran PR
            'attachments',

            // 🔥 history approval
            'approvalHistories',
        ])->findOrFail($id);

        return response()->json([
            'data' => $pr
        ]);
    }


    /**
     * PUT /api/purchase-request/{id}
     * Update PR
     */
    public function update(string $encryptedId, Request $request)
    {
        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');

            $id = Crypt::decryptString($encryptedId);
            $pr = PurchaseRequest::findOrFail($id);

            /* =====================================================
           1️⃣ PROTEKSI STATUS
        ===================================================== */
            if ($pr->status === 'APPROVED') {
                return response()->json([
                    'message' => 'Purchase request sudah diapprove. Tidak dapat diperbarui.'
                ], 403);
            }

            /* =====================================================
           2️⃣ UPDATE HEADER PR
        ===================================================== */
            $pr->update([
                'tanggal_pr'    => $clean($request->tanggal_pr),
                'cabang'        => $clean($request->cabang),
                'id_department' => $clean($request->id_department),
                'kategori'      => $clean($request->kategori),
                'notes'         => $clean($request->notes),
            ]);

            /* =====================================================
           3️⃣ UPDATE / SYNC VENDOR
        ===================================================== */
            $vendors = json_decode($request->vendors, true);

            if (!is_array($vendors)) {
                throw new \Exception('Format vendor tidak valid');
            }

            $vendorIds = collect($vendors)->pluck('vendor_id')->toArray();

            // Hapus vendor yang dihilangkan di edit
            PurchaseRequestVendor::where('purchase_request_id', $pr->id)
                ->whereNotIn('vendor_id', $vendorIds)
                ->delete();

            foreach ($vendors as $v) {

                /* -----------------------------------------
               3.1 CREATE / UPDATE VENDOR
            ----------------------------------------- */
                $vendor = PurchaseRequestVendor::updateOrCreate(
                    [
                        'purchase_request_id' => $pr->id,
                        'vendor_id'           => $v['vendor_id'],
                    ],
                    [
                        'dpp'         => $v['dpp'] ?? 0,
                        'ppn'         => $v['ppn'] ?? 0,
                        'price_offer' => ($v['dpp'] ?? 0) + ($v['ppn'] ?? 0),
                        'is_selected' => $v['is_selected'] ?? false,
                    ]
                );

                /* -----------------------------------------
               3.2 RESET & INSERT ITEM
            ----------------------------------------- */
                PurchaseRequestVendorItem::where('pr_vendor_id', $vendor->id)->delete();

                foreach ($v['items'] as $item) {
                    $qty   = (int) ($item['qty'] ?? 0);
                    $harga = (int) ($item['harga_unit'] ?? 0);

                    PurchaseRequestVendorItem::create([
                        'pr_vendor_id' => $vendor->id,
                        'nama_item'    => $clean($item['nama_item']),
                        'qty'          => $qty,
                        'satuan'       => $clean($item['satuan']),
                        'keterangan'   => $clean($item['keterangan'] ?? ''),
                        'harga_unit'   => $harga,
                        'subtotal'     => $qty * $harga,
                    ]);
                }
            }

            /* =====================================================
           4️⃣ TAMBAH LAMPIRAN PR (MULTIPLE) – SAMA DENGAN STORE
        ===================================================== */
            if ($request->hasFile('lampiran_requests')) {

                $folder = "asset-management/uploads/purchase_request/lampiran_request";
                Storage::disk('public')->makeDirectory($folder);

                foreach ($request->file('lampiran_requests') as $file) {

                    if ($file->getSize() > 3 * 1024 * 1024) {
                        throw new \Exception("Ukuran file maksimal 3MB");
                    }

                    $filename = str_replace("/", "-", $pr->nomor_pr)
                        . "_" . $file->getClientOriginalName();

                    $path = $file->storeAs($folder, $filename, 'public');

                    PrAttachment::create([
                        'purchase_request_id' => $pr->id,
                        'filename'            => $filename,
                        'original_filename'   => $file->getClientOriginalName(),
                        'mime_type'           => $file->getMimeType(),
                        'file_size'           => $file->getSize(),
                        'filepath'            => $path,
                    ]);
                }
            }

            return response()->json([
                'message' => 'Purchase Request berhasil diperbarui.'
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Gagal update Purchase Request.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/purchase-request/{id}
     * Soft delete data
     */
    public function destroy($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $pr = PurchaseRequest::findOrFail($id);

        // Hanya PR NEW yang boleh dihapus
        if ($pr->status !== 'DRAFT') {
            return response()->json([
                'error' => 'Purchase Request tidak dapat dihapus karena status bukan DRAFT'
            ], 400);
        }

        $pr->delete(); // soft delete

        return response()->json([
            'message' => 'Purchase Request berhasil dihapus'
        ]);
    }

    public function exportExcel(Request $request)
    {
        $query = PurchaseRequest::with([
            "vendors.vendor",
            "vendors.items",
            "vendors.attachments"
        ]);

        // ===============================
        // FILTER FIELD
        // ===============================
        if ($request->field && $request->value && $request->type !== null) {

            $field = $request->field;
            $type  = $request->type;
            $value = $request->value;

            if ($type === "like") {
                $query->where($field, "ILIKE", "%$value%");
            } else {
                $query->where($field, $type, $value);
            }
        }

        // ===============================
        // FILTER RANGE TANGGAL
        // ===============================
        if ($request->dateStart && $request->dateEnd) {
            $query->whereBetween('tanggal_pr', [
                $request->dateStart,
                $request->dateEnd
            ]);
        }

        $data = $query->orderBy("id", "desc")->get();

        return Excel::download(
            new PurchaseRequestExport($data),
            "purchase_request.xlsx"
        );
    }

    public function edit($encryptedId)
    {
        try {
            $id = Crypt::decryptString($encryptedId);

            $pr = PurchaseRequest::with([
                "vendors.vendor",
                "vendors.items",
                "attachments"
            ])->findOrFail($id);

            return response()->json($pr);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Data tidak ditemukan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteDokumen($id)
    {
        try {
            // 1️⃣ Cari attachment
            $attachment = PrAttachment::findOrFail($id);

            // 2️⃣ Ambil parent PR
            $pr = PurchaseRequest::find($attachment->purchase_request_id);
            if (!$pr) {
                return response()->json([
                    'message' => 'Purchase Request tidak ditemukan.'
                ], 404);
            }

            // 3️⃣ Proteksi status
            if (in_array($pr->status, ['APPROVED', 'IN PROGRESS'])) {
                return response()->json([
                    'message' => 'PR sudah diapprove atau sedang tahap approval. Lampiran tidak dapat dihapus.'
                ], 403);
            }

            // 4️⃣ PATH ASLI (JANGAN DIUBAH)
            $path = $attachment->filepath;

            // 5️⃣ Hapus file fisik
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            // 6️⃣ Hapus DB
            $attachment->delete();

            return response()->json([
                'message' => 'Lampiran berhasil dihapus.'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Lampiran tidak ditemukan.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus lampiran.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function approve($id, Request $request)
    {
        DB::beginTransaction();

        try {
            $user = auth()->user();
            $pr = PurchaseRequest::findOrFail($id);

            // Total PR untuk menentukan matrix
            $totalPR = $pr->total_amount ?? $pr->total_amount ?? 0;

            // Ambil approval matrix sesuai nominal PR
            $matrix = ApprovalMatrixPR::where('min_value', '<=', $totalPR)
                ->where(function ($q) use ($totalPR) {
                    $q->where('max_value', '>=', $totalPR)
                        ->orWhereNull('max_value');
                })
                ->orderBy('level_order')
                ->get();

            if ($matrix->isEmpty()) {
                return response()->json(['error' => 'Approval matrix tidak ditemukan'], 400);
            }

            // Ambil step yang sedang berjalan
            $currentFlow = $matrix->firstWhere('level_order', $pr->current_level);

            if (!$currentFlow) {
                return response()->json(['error' => 'Flow approval tidak valid'], 400);
            }

            // CEK ROLE USER — wajib sama dengan matrix
            if ($user->role != $currentFlow->approver_role) {
                return response()->json([
                    'error' => "Anda tidak berhak approve tahap ini. Dibutuhkan role: {$currentFlow->approver_role}"
                ], 403);
            }

            // ======================================
            // 1. CATAT KE APPROVAL HISTORY
            // ======================================
            ApprovalHistoryPR::create([
                'purchase_request_id'   => $pr->id,
                'level'                 => $pr->current_level,
                'approver_user_id'      => $request->id_user,
                'approver_role'         => $user->role,
                'status'                => 'APPROVED',
                'notes'                 => $request->note,
                'created_at'            => now(),
            ]);

            // ======================================
            // 2. CEK APAKAH INI APPROVAL TERAKHIR
            // ======================================
            $maxLevel = $matrix->max('level_order');
            $isLastApproval = $pr->current_level >= $maxLevel;

            if ($isLastApproval) {

                // =========== GENERATE NOMOR PR RESMI ===========
                if (!$pr->nomor_pr) {
                    $nomorResmi = generatePRNumber($pr);
                    $pr->nomor_pr = $nomorResmi;
                }

                // =============== RENAME FILE LAMPIRAN ============
                if ($pr->lampiran_request && $pr->path_lampiran) {
                    $oldPath = $pr->path_lampiran;
                    $folder = dirname($oldPath);
                    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);

                    $newFilename = str_replace("/", "-", $pr->nomor_pr) . "." . $ext;
                    $newPath = $folder . "/" . $newFilename;

                    Storage::disk('public')->move($oldPath, $newPath);

                    $pr->lampiran_request = $newFilename;
                    $pr->path_lampiran = $newPath;
                }

                // =============== FINAL APPROVE ===============
                $pr->status = 'APPROVED';
                $pr->approved_by = $user->id;
                $pr->approved_at = now();
            } else {
                // =============== LANJUT KE LEVEL SELANJUTNYA ===============
                $pr->current_level += 1;
                $pr->status = 'IN_PROGRESS';
            }

            $pr->save();

            DB::commit();

            return response()->json([
                'message' => $isLastApproval
                    ? 'PR berhasil disetujui dan FINAL APPROVED'
                    : 'Approval berhasil, menunggu approval level berikutnya',
                'current_level' => $pr->current_level,
                'status' => $pr->status,
                'nomor_pr' => $pr->nomor_pr ?? null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal approve PR',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function submit(string $encryptedId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($encryptedId);

            $pr = PurchaseRequest::with(['vendors', 'attachments'])->findOrFail($id);

            /* ===============================
           VALIDASI STATUS
        =============================== */
            if ($pr->status !== 'DRAFT') {
                return response()->json([
                    'message' => 'Purchase Request sudah dikirim.'
                ], 400);
            }

            /* ===============================
           AMBIL VENDOR REKOMENDASI
        =============================== */
            $vendor = $pr->vendors->firstWhere('is_selected', true);

            if (!$vendor) {
                return response()->json([
                    'message' => 'Vendor rekomendasi belum dipilih.'
                ], 422);
            }

            $grandTotal = $vendor->price_offer;

            /* ===============================
           MAP DEPARTMENT & LOCATION
        =============================== */
            $location   = ApprovalHelper::mapLocation($pr->cabang);
            $departmentGroup = ApprovalHelper::mapDepartmentById((int) $pr->id_department);

            /* ===============================
           APPROVAL MATRIX
        =============================== */
            $matrix = ApprovalMatrixPR::where('location', $location)
                ->where('department', $departmentGroup)
                ->where('min_value', '<=', $grandTotal)
                ->where(function ($q) use ($grandTotal) {
                    $q->where(function ($qq) use ($grandTotal) {
                        $qq->whereNotNull('max_value')
                            ->where('max_value', '>=', $grandTotal);
                    })
                        ->orWhereNull('max_value');
                })
                ->orderBy('level_order')
                ->get();

            if ($matrix->isEmpty()) {
                return response()->json([
                    'message' => 'Approval matrix tidak ditemukan.'
                ], 422);
            }

            /* ===============================
           GENERATE NOMOR PR RESMI (PAKSA)
        =============================== */
            $nomorResmi = generatePRNumber($pr);
            $oldNomor   = $pr->nomor_pr;

            $pr->nomor_pr = $nomorResmi;

            /* ===============================
           RENAME SEMUA LAMPIRAN PR
        =============================== */
            foreach ($pr->attachments as $attachment) {

                if (!$attachment->filepath) continue;

                $oldPath = $attachment->filepath;

                if (!Storage::disk('public')->exists($oldPath)) continue;

                $folder = dirname($oldPath);

                // 🔥 GANTI PREFIX DRAFT → NOMOR RESMI
                $newFilename = preg_replace(
                    '/^' . preg_quote(str_replace('/', '-', $oldNomor), '/') . '/',
                    str_replace('/', '-', $nomorResmi),
                    $attachment->filename
                );

                $newPath = $folder . '/' . $newFilename;

                if ($oldPath !== $newPath) {
                    Storage::disk('public')->move($oldPath, $newPath);
                }

                $attachment->update([
                    'filename' => $newFilename,
                    'filepath' => $newPath,
                ]);
            }

            /* ===============================
           UPDATE STATUS PR
        =============================== */
            $pr->status = 'IN PROGRESS';
            $pr->current_level = $matrix->first()->level_order;
            $pr->save();

            /* ===============================
           SIMPAN HISTORY APPROVAL
        =============================== */
            foreach ($matrix as $row) {
                PurchaseRequestHistoryApproval::create([
                    'purchase_request_id' => $pr->id,
                    'level'               => $row->level_order,
                    'approver_role'       => $row->approver_role,
                    'approver_user_id'    => null,
                    'status'              => $row->level_order === $matrix->first()->level_order
                        ? 'WAITING'
                        : 'PENDING',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Purchase Request berhasil dikirim ke proses approval.'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal mengirim Purchase Request.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function prByVendor($vendorId)
    {
        $prs = PurchaseRequest::where('status', 'APPROVED')
            ->whereHas('vendors', function ($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId);
            })
            ->with([
                'vendors' => function ($q) use ($vendorId) {
                    $q->where('vendor_id', $vendorId)
                        ->select(
                            'id',
                            'purchase_request_id',
                            'vendor_id',
                            'is_selected',
                            'dpp',
                            'ppn',
                            'price_offer'
                        )
                        ->with([
                            'items' => function ($qi) {
                                $qi->select(
                                    'id',
                                    'pr_vendor_id',
                                    'nama_item',
                                    'qty',
                                    'satuan',
                                    'keterangan',
                                    'harga_unit',
                                    'subtotal'
                                );
                            }
                        ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'nomor_pr',
                'tanggal_pr',
                'cabang',
                'id_department',
                'total_amount'
            ]);

        return response()->json($prs);
    }
}
