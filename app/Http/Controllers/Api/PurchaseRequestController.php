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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use DocumentHelper;
use App\Helpers\ApprovalHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
        try {
            $query = PurchaseRequest::with([
                'cabangData',
                'departmentData',
                'recommendedVendor',
                'items',
            ])->orderBy('id', 'desc');

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nomor_pr', 'ILIKE', "%{$search}%")
                        ->orWhere('kategori', 'ILIKE', "%{$search}%");
                });
            }

            if ($request->tanggal_mulai) {
                $query->whereDate('tanggal_pr', '>=', $request->tanggal_mulai);
            }

            if ($request->tanggal_selesai) {
                $query->whereDate('tanggal_pr', '<=', $request->tanggal_selesai);
            }

            if ($request->status) {
                $query->where('status', $request->status);
            }

            if ($request->status_po) {
                $query->where('status_po', $request->status_po);
            }

            $perPage = (int) ($request->per_page ?? 10);

            $prs = $query->paginate($perPage);

            $prs->getCollection()->transform(function ($pr) {
                return [
                    'id'            => $pr->id,
                    'public_id'     => $pr->encrypted_id,
                    'nomor_pr'      => $pr->nomor_pr,
                    'tanggal_pr'    => $pr->tanggal_pr,

                    'cabang'        => $pr->cabangData->nama_cabang ?? '-',
                    'cabang_id'     => $pr->cabang,

                    'department'    => $pr->departmentData->kode ?? '-',
                    'department_id' => $pr->id_department,

                    'kategori'      => $pr->kategori,
                    'notes'         => $pr->notes,
                    'status'        => $pr->status,
                    'status_po'     => $pr->status_po,
                    'requested_by'  => $pr->requested_by,

                    'recommended_vendor_id' => $pr->recommended_vendor_id,
                    'recommended_vendor'    => $pr->recommendedVendor ? [
                        'id'          => $pr->recommendedVendor->id,
                        'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                        'status_pkp'  => $pr->recommendedVendor->status_pkp ?? '-',
                    ] : null,

                    'total_amount' => $pr->total_amount ?? $pr->items->sum('subtotal'),

                    'items' => $pr->items->map(function ($item) {
                        return [
                            'id'          => $item->id,
                            'nama_item'   => $item->nama_item,
                            'qty'         => $item->qty,
                            'satuan'      => $item->satuan,
                            'spesifikasi' => $item->spesifikasi,
                            'keterangan'  => $item->keterangan,
                            'harga_unit'  => $item->harga_unit,
                            'subtotal'    => $item->subtotal,
                        ];
                    })->values(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data Purchase Request berhasil dimuat.',
                'data'    => $prs->items(),
                'meta'    => [
                    'current_page' => $prs->currentPage(),
                    'last_page'    => $prs->lastPage(),
                    'per_page'     => $prs->perPage(),
                    'total'        => $prs->total(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Request] Index error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Purchase Request',
                'data'    => [],
                'meta'    => [
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => (int) ($request->per_page ?? 10),
                    'total'        => 0,
                ],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
    /**
     * POST /api/purchase-request
     * Simpan data baru dari form (axios.post)
     */
    public function store(Request $request)
    {
        $storedPaths = [];

        DB::beginTransaction();
        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $request->validate([
                'tanggal_pr'             => ['required', 'date_format:Y-m-d'],
                'cabang'                 => ['required'],
                'id_department'          => ['required', 'integer'],
                'recommended_vendor_id'  => ['nullable', 'integer', 'exists:master_vendor,id'],
                'kategori'               => ['required', 'string'],
                'items'                  => ['required', 'string'],
                'lampiran_request.*'     => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3000'],
            ]);
            /*
            |--------------------------------------------------------------------------
            | 1. Generate Nomor PR
            |--------------------------------------------------------------------------
            */
            $nomorPr = $this->generateDraftPRNumber();

            /*
            |--------------------------------------------------------------------------
            | 2. Decode & Validasi Items
            |--------------------------------------------------------------------------
            */
            $items = json_decode($request->items, true);

            if (!is_array($items) || count($items) === 0) {
                throw new \Exception('Data item tidak valid.');
            }

            foreach ($items as $item) {
                if (empty($item['nama_item'])) {
                    throw new \Exception('Nama item wajib diisi.');
                }

                if (empty($item['qty']) || (float) $item['qty'] <= 0) {
                    throw new \Exception('Qty item wajib diisi.');
                }

                if (empty($item['satuan'])) {
                    throw new \Exception('Satuan item wajib dipilih.');
                }

                if (!isset($item['harga_unit']) || (float) $item['harga_unit'] <= 0) {
                    throw new \Exception('Harga satuan item wajib diisi.');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Hitung Total Amount
            |--------------------------------------------------------------------------
            */
            $totalAmount = 0;

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);

                $totalAmount += $qty * $harga;
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Simpan Header PR
            |--------------------------------------------------------------------------
            */
            $pr = PurchaseRequest::create([
                'nomor_pr'              => $nomorPr,
                'tanggal_pr'            => $clean($request->tanggal_pr),
                'cabang'                => $clean($request->cabang),
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $request->filled('recommended_vendor_id')
                    ? (int) $request->recommended_vendor_id
                    : null,
                'kategori'              => $clean($request->kategori),
                'notes'                 => $clean($request->notes),
                'requested_by'          => $request->user()->fullname ?? $request->user()->name ?? 'System',
                'request_date'          => now(),
                'status'                => PurchaseRequest::STATUS_DRAFT,
                'current_level'         => 0,
                'total_amount'          => $totalAmount,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 5. Simpan Lampiran Request
            |--------------------------------------------------------------------------
            */
            if ($request->hasFile('lampiran_request')) {
                $folder = "syopv4/uploads/purchase_requests/lampiran/{$pr->id}";

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
            | 6. Simpan Item PR
            |--------------------------------------------------------------------------
            */
            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);
                $subtotal = $qty * $harga;

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'nama_item'           => $clean($item['nama_item'] ?? ''),
                    'qty'                 => $qty,
                    'qty_outstanding'     => $qty,
                    'satuan'              => $clean($item['satuan'] ?? ''),
                    'spesifikasi'         => $clean($item['spesifikasi'] ?? ''),
                    'keterangan'          => $clean($item['keterangan'] ?? ''),
                    'harga_unit'          => $harga,
                    'subtotal'            => $subtotal,
                ]);
            }

            DB::commit();

            return response()->json([
                'success'  => true,
                'message'  => 'Purchase Request berhasil disimpan.',
                'nomor_pr' => $nomorPr,
                'data'     => [
                    'id'        => $pr->id,
                    'public_id' => $pr->encrypted_id ?? null,
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
                'message' => 'Gagal menyimpan Purchase Request. Silakan periksa data atau hubungi IT.',
            ], 500);
        }
    }



    /**
     * GET /api/purchase-request/{id}
     * Ambil detail PR berdasarkan ID
     */
    public function show($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'purchaseOrders:id,nomor_po,tanggal_po,status,total_nilai',
                'items.unit:id,kode,nama',
                'attachments',
                'approvalHistories',
            ])->findOrFail($id);

            $items = $pr->getRelation('items');

            $totalPo = $items->sum(function ($item) {
                return (float) ($item->qty_po ?? 0) * (float) ($item->harga_unit ?? 0);
            });

            $totalOutstanding = $items->sum(function ($item) {
                return (float) ($item->qty_outstanding ?? 0) * (float) ($item->harga_unit ?? 0);
            });

            return response()->json([
                'success' => true,
                'message' => 'Detail Purchase Request berhasil dimuat.',
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr,

                    'cabang_id' => $pr->cabang,
                    'cabang' => $pr->cabangData
                        ? trim(($pr->cabangData->inisial_cabang ?? '-') . ' - ' . ($pr->cabangData->nama_cabang ?? '-'))
                        : '-',

                    'department_id' => $pr->id_department,
                    'department' => $pr->departmentData
                        ? trim(($pr->departmentData->kode ?? '-') . ' - ' . ($pr->departmentData->nama ?? '-'))
                        : '-',

                    'recommended_vendor_id' => $pr->recommended_vendor_id,
                    'recommended_vendor' => $pr->recommendedVendor ? [
                        'id' => $pr->recommendedVendor->id,
                        'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                        'status_pkp' => $pr->recommendedVendor->status_pkp ?? 'NON_PKP',
                        'jenis_pembayaran' => $pr->recommendedVendor->jenis_pembayaran ?? null,
                        'top' => $pr->recommendedVendor->top ?? null,
                    ] : null,

                    'kategori' => $pr->kategori,
                    'notes' => $pr->notes,
                    'status' => $pr->status,
                    'status_po' => $pr->status_po,

                    'purchase_orders' => $pr->purchaseOrders->map(function ($po) {
                        return [
                            'id' => $po->id,
                            'nomor_po' => $po->nomor_po,
                            'tanggal_po' => $po->tanggal_po,
                            'status' => $po->status,
                            'total_nilai' => (float) ($po->total_nilai ?? 0),
                        ];
                    })->values(),

                    'requested_by' => $pr->requested_by,

                    'total_amount' => (float) ($pr->total_amount ?? 0),
                    'total_po' => $totalPo,
                    'total_outstanding' => $totalOutstanding,

                    'items' => $items->map(function ($item) {
                        $qty = (float) ($item->qty ?? 0);
                        $qtyPo = (float) ($item->qty_po ?? 0);
                        $qtyOutstanding = (float) ($item->qty_outstanding ?? 0);
                        $hargaUnit = (float) ($item->harga_unit ?? 0);

                        return [
                            'id' => $item->id,
                            'nama_item' => $item->nama_item,
                            'qty' => $item->qty,
                            'qty_po' => $item->qty_po,
                            'qty_outstanding' => $item->qty_outstanding,

                            'satuan_id' => $item->satuan,
                            'satuan' => [
                                'id' => $item->unit->id ?? null,
                                'kode' => $item->unit->kode ?? '-',
                                'nama' => $item->unit->nama ?? '-',
                            ],

                            'spesifikasi' => $item->spesifikasi,
                            'harga_unit' => $item->harga_unit,
                            'subtotal' => $item->subtotal,
                            'subtotal_po' => $qtyPo * $hargaUnit,
                            'subtotal_outstanding' => $qtyOutstanding * $hargaUnit,
                            'keterangan' => $item->keterangan,
                        ];
                    })->values(),

                    'attachments' => $pr->attachments->map(function ($a) {
                        return [
                            'id' => $a->id,
                            'filename' => $a->filename,
                            'filepath' => asset('storage/' . $a->filepath),
                            'file_size' => $a->file_size,
                            'mime_type' => $a->mime_type,
                            'original_filename' => $a->original_filename,
                        ];
                    })->values(),

                    'approval_histories' => $pr->approvalHistories,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Request] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Purchase Request.',
                'data' => null,
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * PUT /api/purchase-request/{id}
     * Update PR
     */
    public function update(string $publicId, Request $request)
    {
        $storedPaths = [];

        DB::beginTransaction();

        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $request->validate([
                'tanggal_pr'             => ['required', 'date_format:Y-m-d'],
                'cabang'                 => ['required'],
                'id_department'          => ['required', 'integer'],
                'recommended_vendor_id'  => ['nullable', 'integer', 'exists:master_vendor,id'],
                'kategori'               => ['required', 'string'],
                'items'                  => ['required', 'string'],
                'existing_attachment_ids' => ['nullable', 'string'],
                'lampiran_requests.*'    => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3000'],
            ]);

            $id = Crypt::decryptString($publicId);
            $pr = PurchaseRequest::findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | 1. Proteksi Status
        |--------------------------------------------------------------------------
        */
            if ($pr->status === PurchaseRequest::STATUS_APPROVED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase request sudah diapprove. Tidak dapat diperbarui.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | 2. Decode & Validasi Items
        |--------------------------------------------------------------------------
        */
            $items = json_decode($request->items, true);

            if (!is_array($items) || count($items) === 0) {
                throw new \Exception('Data item tidak valid.');
            }

            foreach ($items as $item) {
                if (empty($item['nama_item'])) {
                    throw new \Exception('Nama item wajib diisi.');
                }

                if (empty($item['qty']) || (float) $item['qty'] <= 0) {
                    throw new \Exception('Qty item wajib diisi.');
                }

                if (empty($item['satuan'])) {
                    throw new \Exception('Satuan item wajib dipilih.');
                }

                if (!isset($item['harga_unit']) || (float) $item['harga_unit'] <= 0) {
                    throw new \Exception('Harga satuan item wajib diisi.');
                }
            }

            /*
        |--------------------------------------------------------------------------
        | 3. Hitung Total Amount
        |--------------------------------------------------------------------------
        */
            $totalAmount = 0;

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);

                $totalAmount += $qty * $harga;
            }

            /*
        |--------------------------------------------------------------------------
        | 4. Update Header PR
        |--------------------------------------------------------------------------
        */
            $pr->update([
                'tanggal_pr'            => $clean($request->tanggal_pr),
                'cabang'                => $clean($request->cabang),
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $request->filled('recommended_vendor_id')
                    ? (int) $request->recommended_vendor_id
                    : null,
                'kategori'              => $clean($request->kategori),
                'notes'                 => $clean($request->notes),
                'total_amount'          => $totalAmount,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 5. Sync Item PR
        | Cara aman: hapus item lama lalu insert ulang.
        |--------------------------------------------------------------------------
        */
            PurchaseRequestItem::where('purchase_request_id', $pr->id)->delete();

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);
                $subtotal = $qty * $harga;

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'nama_item'           => $clean($item['nama_item'] ?? ''),
                    'qty'                 => $qty,
                    'satuan'              => $clean($item['satuan'] ?? ''),
                    'spesifikasi'         => $clean($item['spesifikasi'] ?? ''),
                    'keterangan'          => $clean($item['keterangan'] ?? ''),
                    'harga_unit'          => $harga,
                    'subtotal'            => $subtotal,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | 6. Existing Attachment IDs
        |--------------------------------------------------------------------------
        */
            $existingAttachmentIds = json_decode(
                $request->existing_attachment_ids ?? '[]',
                true
            );

            if (!is_array($existingAttachmentIds)) {
                $existingAttachmentIds = [];
            }

            /*
        |--------------------------------------------------------------------------
        | 7. Hapus Attachment Lama Yang Dihapus Di FE
        |--------------------------------------------------------------------------
        */
            $deletedAttachments = PrAttachment::where('purchase_request_id', $pr->id)
                ->when(count($existingAttachmentIds) > 0, function ($query) use ($existingAttachmentIds) {
                    $query->whereNotIn('id', $existingAttachmentIds);
                })
                ->when(count($existingAttachmentIds) === 0, function ($query) {
                    $query->whereRaw('1 = 1');
                })
                ->get();

            foreach ($deletedAttachments as $attachment) {
                if (
                    $attachment->filepath &&
                    Storage::disk('public')->exists($attachment->filepath)
                ) {
                    Storage::disk('public')->delete($attachment->filepath);
                }

                $attachment->delete();
            }

            /*
        |--------------------------------------------------------------------------
        | 8. Tambah Lampiran Baru
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('lampiran_requests')) {
                $nomorPr = $pr->nomor_pr;
                $folder = "syopv4/uploads/purchase_requests/lampiran/{$pr->id}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (\Illuminate\Support\Facades\File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                }

                foreach ($request->file('lampiran_requests') as $file) {
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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Request berhasil diperbarui.',
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id ?? null,
                    'nomor_pr' => $pr->nomor_pr,
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
                'message' => 'Gagal update Purchase Request.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
                'line'    => config('app.debug') ? $e->getLine() : null,
            ], 500);
        }
    }

    /**
     * DELETE /api/purchase-request/{id}
     * Soft delete data
     */
    public function destroy($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'items',
                'attachments',
                'approvalHistories',
            ])->find($id);

            if (!$pr) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Request tidak ditemukan.',
                ], 404);
            }

            if (strtolower($pr->status) !== 'draft') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Request hanya dapat dihapus jika status masih Draft.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Hapus item PR
            |--------------------------------------------------------------------------
            */
            $pr->items()->delete();

            /*
            |--------------------------------------------------------------------------
            | Hapus attachment record
            | Kalau ingin hapus file fisiknya juga, aktifkan bagian delete storage.
            |--------------------------------------------------------------------------
            */
            foreach ($pr->attachments as $attachment) {
                if (
                    $attachment->filepath &&
                    Storage::disk('public')->exists($attachment->filepath)
                ) {
                    Storage::disk('public')->delete($attachment->filepath);
                }

                $attachment->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | Hapus approval histories
            |--------------------------------------------------------------------------
            */
            $pr->approvalHistories()->delete();

            /*
            |--------------------------------------------------------------------------
            | Hapus PR
            |--------------------------------------------------------------------------
            */
            $pr->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Request berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Request] Delete error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Purchase Request.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        $query = PurchaseRequest::with([
            "vendors.vendor",
            "vendors.items",
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

    public function edit($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'cabangData',
                'departmentData',
                'recommendedVendor',
                'items.unit',
                'attachments',
            ])->findOrFail($id);

            if (!$pr) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Request tidak ditemukan.',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data edit Purchase Request berhasil dimuat.',
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr,

                    'cabang_id' => $pr->cabang,
                    'cabang' => $pr->cabangData->nama_cabang ?? '-',

                    'department_id' => $pr->id_department,
                    'department' => $pr->departmentData
                        ? (($pr->departmentData->kode ?? '-') . ' - ' . ($pr->departmentData->nama ?? '-'))
                        : '-',

                    'recommended_vendor_id' => $pr->recommended_vendor_id,
                    'recommended_vendor' => $pr->recommendedVendor ? [
                        'id' => $pr->recommendedVendor->id,
                        'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                        'status_pkp' => $pr->recommendedVendor->status_pkp ?? 'NON_PKP',
                    ] : null,

                    'kategori' => $pr->kategori,
                    'notes' => $pr->notes,
                    'status' => $pr->status,
                    'requested_by' => $pr->requested_by,
                    'total_amount' => $pr->total_amount,

                    'items' => $pr->getRelation('items')->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'nama_item' => $item->nama_item,
                            'qty' => $item->qty,

                            'satuan_id' => $item->satuan,
                            'satuan' => [
                                'id' => $item->unit->id ?? null,
                                'kode' => $item->unit->kode ?? '-',
                                'nama' => $item->unit->nama ?? '-',
                            ],

                            'spesifikasi' => $item->spesifikasi,
                            'harga_unit' => $item->harga_unit,
                            'subtotal' => $item->subtotal,
                            'keterangan' => $item->keterangan,
                        ];
                    })->values(),

                    'attachments' => $pr->attachments->map(function ($a) {
                        return [
                            'id' => $a->id,
                            'filename' => $a->filename,
                            'original_filename' => $a->original_filename,
                            'filepath' => asset('storage/' . $a->filepath),
                            'file_size' => $a->file_size,
                            'mime_type' => $a->mime_type,
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Request] Edit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data edit Purchase Request.',
                'data' => null,
                'debug' => app()->environment('local') ? $e->getMessage() : null,
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

    public function submit($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::find($id);

            if (!$pr) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Request tidak ditemukan.',
                ], 404);
            }

            if ($pr->status !== PurchaseRequest::STATUS_DRAFT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Request hanya bisa disubmit dari status Draft.',
                ], 422);
            }

            $pr->status = PurchaseRequest::STATUS_IN_PROGRESS;
            $pr->current_level = 1;
            $pr->save();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Request berhasil disubmit.',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Purchase Request.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
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

    public function dropdownApproved(Request $request)
    {
        try {
            $cabangId = (int) $request->cabang;
            $departmentId = (int) $request->id_department;

            if (!$cabangId || !$departmentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cabang dan department wajib dipilih.',
                    'data' => [],
                ], 422);
            }

            $prs = PurchaseRequest::query()
                ->with([
                    'cabangData:id,nama_cabang,inisial_cabang',
                    'departmentData:id,kode,nama',
                    'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                    'attachments',
                    'items' => function ($q) {
                        $q->with('unit:id,kode,nama')
                            ->whereNull('deleted_at')
                            ->whereRaw('(COALESCE(qty_outstanding, qty - COALESCE(qty_po, 0)) > 0)');
                    },
                ])
                ->where('cabang', $cabangId)
                ->where('id_department', $departmentId)
                ->whereRaw('UPPER(status) = ?', ['APPROVED'])
                ->where(function ($q) {
                    $q->whereNull('status_po')
                        ->orWhereRaw('UPPER(status_po) IN (?, ?)', ['OPEN', 'PARTIAL']);
                })
                ->whereHas('items', function ($q) {
                    $q->whereNull('deleted_at')
                        ->whereRaw('(COALESCE(qty_outstanding, qty - COALESCE(qty_po, 0)) > 0)');
                })
                ->orderByDesc('id')
                ->get();

            $data = $prs->map(function ($pr) {
                $items = $pr->items
                    ->filter(function ($item) {
                        $qty = (float) ($item->qty ?? 0);
                        $qtyPo = (float) ($item->qty_po ?? 0);

                        $qtyOutstanding = $item->qty_outstanding !== null
                            ? (float) $item->qty_outstanding
                            : ($qty - $qtyPo);

                        return $qtyOutstanding > 0;
                    })
                    ->map(function ($item) {
                        $qty = (float) ($item->qty ?? 0);
                        $qtyPo = (float) ($item->qty_po ?? 0);
                        $hargaUnit = (float) ($item->harga_unit ?? 0);

                        $qtyOutstanding = $item->qty_outstanding !== null
                            ? (float) $item->qty_outstanding
                            : ($qty - $qtyPo);

                        $qtyOutstanding = max($qtyOutstanding, 0);

                        return [
                            'id' => $item->id,
                            'nama_item' => $item->nama_item,
                            'qty' => $qty,
                            'qty_po' => $qtyPo,
                            'qty_outstanding' => $qtyOutstanding,

                            'satuan_id' => $item->satuan,
                            'satuan' => [
                                'id' => $item->unit->id ?? null,
                                'kode' => $item->unit->kode ?? '-',
                                'nama' => $item->unit->nama ?? '-',
                            ],

                            'harga_unit' => $hargaUnit,
                            'subtotal' => (float) ($item->subtotal ?? 0),
                            'subtotal_po' => $qtyPo * $hargaUnit,
                            'subtotal_outstanding' => $qtyOutstanding * $hargaUnit,
                            'keterangan' => $item->keterangan,
                        ];
                    })
                    ->values();

                $totalOutstanding = $items->sum('subtotal_outstanding');

                return [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr,

                    'cabang' => $pr->cabangData
                        ? ($pr->cabangData->inisial_cabang ?? '-')
                        : '-',

                    'department' => $pr->departmentData
                        ? ($pr->departmentData->kode ?? '-')
                        : '-',

                    'status' => $pr->status,
                    'status_po' => $pr->status_po,

                    'total_amount' => (float) ($pr->total_amount ?? 0),
                    'total_outstanding' => $totalOutstanding,

                    'attachments' => $pr->attachments->map(function ($a) {
                        return [
                            'id' => $a->id,
                            'filename' => $a->filename,
                            'original_filename' => $a->original_filename,
                            'filepath' => asset('storage/' . $a->filepath),
                            'file_size' => $a->file_size,
                            'mime_type' => $a->mime_type,
                        ];
                    })->values(),

                    'items' => $items,

                    'recommended_vendor_id' => $pr->recommended_vendor_id,
                    'recommended_vendor' => $pr->recommendedVendor ? [
                        'id' => $pr->recommendedVendor->id,
                        'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                        'status_pkp' => $pr->recommendedVendor->status_pkp ?? 'NON_PKP',
                        'jenis_pembayaran' => $pr->recommendedVendor->jenis_pembayaran ?? null,
                        'top' => $pr->recommendedVendor->top ?? null,
                    ] : null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Request berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Request] dropdownApproved error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat Purchase Request.',
                'data'    => [],
                'debug'   => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
