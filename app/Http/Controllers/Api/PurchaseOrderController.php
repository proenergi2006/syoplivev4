<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseOrderApprovalHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use DocumentHelper;
use App\Mail\PurchaseOrderApprovalMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $query = PurchaseOrder::with([
                'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'purchaseRequests:id,nomor_pr',
                'approvals:id,purchase_order_id,approver_type,approver_id,status,step_order',
            ])->orderByDesc('id');

            /*
        |--------------------------------------------------------------------------
        | Filter Visibility PO
        |--------------------------------------------------------------------------
        | - Requester / creator dapat melihat PO miliknya termasuk DRAFT.
        | - Approver melihat PO yang sudah submit saja.
        | - Role diambil dari table roles berdasarkan kode role.
        |--------------------------------------------------------------------------
        */

            $userRoleCode = null;

            if (isset($user->role_id)) {
                $userRoleCode = DB::table('roles')
                    ->where('id', $user->role_id)
                    ->value('kode');
            }

            $userRoleCode = strtoupper((string) $userRoleCode);

            $isApproverByUser = PurchaseOrderApproval::where('approver_type', 'USER')
                ->where('approver_id', $user->id)
                ->exists();

            $isApproverByRole = false;

            if ($userRoleCode !== '') {
                $isApproverByRole = PurchaseOrderApproval::where('approver_type', 'ROLE')
                    ->where(function ($q) use ($userRoleCode) {
                        $q->where('approver_role', $userRoleCode)
                            ->orWhere('approver_role_code', $userRoleCode)
                            ->orWhere('approver_code', $userRoleCode);
                    })
                    ->exists();
            }

            $isApprover = $isApproverByUser || $isApproverByRole;

            if ($isApprover) {
                $query->whereIn('status', ['IN PROGRESS', 'APPROVED', 'REJECTED'])
                    ->whereHas('approvals', function ($q) use ($user, $userRoleCode) {
                        $q->where(function ($qq) use ($user, $userRoleCode) {
                            $qq->where(function ($qqq) use ($user) {
                                $qqq->where('approver_type', 'USER')
                                    ->where('approver_id', $user->id);
                            });

                            if ($userRoleCode !== '') {
                                $qq->orWhere(function ($qqq) use ($userRoleCode) {
                                    $qqq->where('approver_type', 'ROLE')
                                        ->where(function ($r) use ($userRoleCode) {
                                            $r->where('approver_role', $userRoleCode)
                                                ->orWhere('approver_role_code', $userRoleCode)
                                                ->orWhere('approver_code', $userRoleCode);
                                        });
                                });
                            }
                        });
                    });
            } else {
                $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('requester_signed_by', $user->id);
                });
            }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nomor_po', 'ILIKE', "%{$search}%");
                });
            }

            if ($request->status) {
                $query->where('status', $request->status);
            }

            if ($request->tanggal_mulai) {
                $query->whereDate('tanggal_po', '>=', $request->tanggal_mulai);
            }

            if ($request->tanggal_selesai) {
                $query->whereDate('tanggal_po', '<=', $request->tanggal_selesai);
            }

            $year = (int) ($request->year ?? now()->year);

            $query->whereYear('tanggal_po', $year);

            $perPage = (int) ($request->per_page ?? 10);
            $pos = $query->paginate($perPage);

            $pos->getCollection()->transform(function ($po) use ($user) {
                $currentApproval = $po->approvals
                    ->where('status', 'PENDING')
                    ->sortBy('step_order')
                    ->first();

                $canApprove = false;

                if ($currentApproval) {
                    $canApprove = $currentApproval->approver_type === 'USER'
                        && (int) $currentApproval->approver_id === (int) $user->id;
                }
                return [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'tanggal_po' => $po->tanggal_po,

                    'vendor_id' => $po->vendor_id,
                    'vendor' => $po->vendor->nama_vendor ?? '-',
                    'status_pkp' => $po->vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $po->vendor->jenis_pembayaran ?? '-',
                    'top' => $po->vendor->top ?? null,

                    'cabang_id' => $po->cabang,
                    'cabang' => $po->cabangData->nama_cabang ?? '-',

                    'department_id' => $po->id_department,
                    'department' => $po->departmentData->kode ?? '-',

                    'dpp' => $po->dpp,
                    'ppn' => $po->ppn,
                    'total_nilai' => $po->total_nilai,
                    'status' => $po->status,
                    'notes' => $po->notes,

                    'can_approve' => $canApprove,

                    'purchase_requests' => $po->purchaseRequests
                        ->pluck('nomor_pr')
                        ->values(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data Purchase Order berhasil dimuat.',
                'data' => $pos->items(),
                'meta' => [
                    'current_page' => $pos->currentPage(),
                    'last_page' => $pos->lastPage(),
                    'per_page' => $pos->perPage(),
                    'total' => $pos->total(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Order] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Purchase Order.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $request->validate([
                'tanggal_po' => ['required', 'date_format:Y-m-d'],
                'vendor_id' => ['required', 'integer'],
                'cabang' => ['required'],
                'id_department' => ['required', 'integer'],
                'purchase_request_ids' => ['required', 'array', 'min:1'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.purchase_request_id' => ['required', 'integer'],
                'items.*.purchase_request_item_id' => ['required', 'integer'],
                'items.*.nama_item' => ['required', 'string'],
                'items.*.qty' => ['required', 'numeric', 'gt:0'],
                'items.*.satuan' => ['nullable', 'string'],
                'items.*.harga_unit' => ['required', 'numeric', 'gte:0'],
                'items.*.subtotal' => ['required', 'numeric', 'gte:0'],
            ]);

            $nomorPo = $this->generateDraftPONumber();

            $po = PurchaseOrder::create([
                'nomor_po' => $nomorPo,
                'tanggal_po' => $request->tanggal_po,
                'vendor_id' => (int) $request->vendor_id,
                'cabang' => $clean($request->cabang),
                'id_department' => (int) $request->id_department,
                'notes' => $clean($request->notes),
                'total_nilai' => (float) ($request->total_nilai ?? 0),
                'dpp' => (float) ($request->dpp ?? 0),
                'ppn' => (float) ($request->ppn ?? 0),
                'status' => 'DRAFT',
                'created_by' => $request->user()->id ?? null,
            ]);

            $purchaseRequestIds = collect($request->purchase_request_ids)
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $po->purchaseRequests()->sync($purchaseRequestIds);

            foreach ($request->items as $item) {
                $prItem = PurchaseRequestItem::whereNull('deleted_at')
                    ->lockForUpdate()
                    ->findOrFail((int) $item['purchase_request_item_id']);

                $qtyPoInput = (float) $item['qty'];
                $qtyOutstanding = (float) ($prItem->qty_outstanding ?? 0);

                if ($qtyPoInput > $qtyOutstanding) {
                    throw new \Exception("Qty PO item {$prItem->nama_item} melebihi qty outstanding.");
                }

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'purchase_request_item_id' => $prItem->id,
                    'nama_item' => $clean($item['nama_item']),
                    'qty' => $qtyPoInput,
                    'satuan' => $clean($item['satuan'] ?? ''),
                    'spesifikasi' => $clean($item['spesifikasi'] ?? ''),
                    'keterangan' => $clean($item['keterangan'] ?? ''),
                    'harga_unit' => (float) $item['harga_unit'],
                    'subtotal' => $qtyPoInput * (float) $item['harga_unit'],
                ]);

                $prItem->qty_po = (float) ($prItem->qty_po ?? 0) + $qtyPoInput;
                $prItem->qty_outstanding = max((float) $prItem->qty - (float) $prItem->qty_po, 0);
                $prItem->save();
            }

            foreach ($purchaseRequestIds as $prId) {
                $this->refreshPurchaseRequestPOStatus($prId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil disimpan.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Store error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan Purchase Order.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with([
                'vendor:id,nama_vendor,status_pkp',
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'purchaseRequests:id,nomor_pr,tanggal_pr,total_amount,recommended_vendor_id',
                'purchaseRequests.recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'items.purchaseRequestItem.unit',
            ])->findOrFail($id);

            $items = $po->getRelation('items');
            $purchaseRequests = $po->getRelation('purchaseRequests');

            return response()->json([
                'success' => true,
                'message' => 'Detail Purchase Order berhasil dimuat.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'tanggal_po' => $po->tanggal_po,

                    'vendor_id' => $po->vendor_id,
                    'vendor' => $po->vendor->nama_vendor ?? '-',
                    'status_pkp' => $po->vendor->status_pkp ?? 'NON_PKP',

                    'cabang_id' => $po->cabang,
                    'cabang' => $po->cabangData
                        ? ($po->cabangData->inisial_cabang ?? '-')
                        : '-',

                    'department_id' => $po->id_department,
                    'department' => $po->departmentData
                        ? ($po->departmentData->kode ?? '-')
                        : '-',

                    'dpp' => $po->dpp,
                    'ppn' => $po->ppn,
                    'total_nilai' => $po->total_nilai,
                    'status' => $po->status,
                    'notes' => $po->notes,

                    'purchase_requests' => $purchaseRequests->map(function ($pr) {
                        return [
                            'id' => $pr->id,
                            'public_id' => $pr->encrypted_id ?? null,
                            'nomor_pr' => $pr->nomor_pr,
                            'tanggal_pr' => $pr->tanggal_pr,
                            'total_amount' => (float) ($pr->total_amount ?? 0),

                            'recommended_vendor_id' => $pr->recommended_vendor_id,
                            'recommended_vendor' => $pr->recommendedVendor ? [
                                'id' => $pr->recommendedVendor->id,
                                'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                                'status_pkp' => $pr->recommendedVendor->status_pkp ?? 'NON_PKP',
                                'jenis_pembayaran' => $pr->recommendedVendor->jenis_pembayaran ?? null,
                                'top' => $pr->recommendedVendor->top ?? null,
                            ] : null,
                        ];
                    })->values(),

                    'items' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'purchase_request_id' => $item->purchaseRequestItem->purchase_request_id ?? null,
                            'purchase_request_item_id' => $item->purchase_request_item_id,
                            'nama_item' => $item->nama_item,
                            'qty' => $item->qty,
                            'satuan' => $item->satuan,
                            'harga_unit' => $item->harga_unit,
                            'subtotal' => $item->subtotal,
                            'keterangan' => $item->keterangan,

                            'purchase_request_item' => $item->purchaseRequestItem ? [
                                'purchase_request_id' => $item->purchaseRequestItem->purchase_request_id,
                                'qty' => $item->purchaseRequestItem->qty,
                                'qty_po' => $item->purchaseRequestItem->qty_po,
                                'qty_outstanding' => $item->purchaseRequestItem->qty_outstanding,
                            ] : null,
                        ];
                    })->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Purchase Order] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Purchase Order.',
                'data' => null,
            ], 500);
        }
    }

    public function edit($publicId)
    {
        return $this->show($publicId);
    }

    public function update(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with([
                'items',
                'purchaseRequests',
            ])->findOrFail($id);

            if ($po->status !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat diperbarui jika status masih Draft.',
                ], 422);
            }

            $request->validate([
                'tanggal_po' => ['required', 'date_format:Y-m-d'],
                'vendor_id' => ['required', 'integer'],
                'cabang' => ['required'],
                'id_department' => ['required', 'integer'],

                'purchase_request_ids' => ['required', 'array', 'min:1'],
                'purchase_request_ids.*' => ['required', 'integer'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.purchase_request_id' => ['required', 'integer'],
                'items.*.purchase_request_item_id' => ['required', 'integer'],
                'items.*.nama_item' => ['required', 'string'],
                'items.*.qty' => ['required', 'numeric', 'gt:0'],
                'items.*.satuan' => ['nullable', 'string'],
                'items.*.harga_unit' => ['required', 'numeric', 'gte:0'],
            ]);

            $oldPrIds = $po->purchaseRequests
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values();

            /*
        |--------------------------------------------------------------------------
        | 1. Soft delete item PO lama
        |--------------------------------------------------------------------------
        */
            PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->whereNull('deleted_at')
                ->delete();

            /*
        |--------------------------------------------------------------------------
        | 2. Update header PO
        |--------------------------------------------------------------------------
        */
            $po->update([
                'tanggal_po'    => $request->tanggal_po,
                'vendor_id'     => (int) $request->vendor_id,
                'cabang'        => $clean($request->cabang),
                'id_department' => (int) $request->id_department,
                'notes'         => $clean($request->notes),
                'total_nilai'   => (float) ($request->total_nilai ?? 0),
                'dpp'           => (float) ($request->dpp ?? 0),
                'ppn'           => (float) ($request->ppn ?? 0),
            ]);

            /*
        |--------------------------------------------------------------------------
        | 3. Sync PR relation
        |--------------------------------------------------------------------------
        */
            $newPrIds = collect($request->purchase_request_ids)
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $po->purchaseRequests()->sync($newPrIds);

            /*
        |--------------------------------------------------------------------------
        | 4. Insert ulang item PO baru
        |--------------------------------------------------------------------------
        */
            foreach ($request->items as $item) {
                $prItem = PurchaseRequestItem::whereNull('deleted_at')
                    ->lockForUpdate()
                    ->findOrFail((int) $item['purchase_request_item_id']);

                $qtyPoInput = (float) $item['qty'];
                $hargaUnit = (float) ($item['harga_unit'] ?? 0);

                PurchaseOrderItem::create([
                    'purchase_order_id'        => $po->id,
                    'purchase_request_item_id' => $prItem->id,
                    'nama_item'                => $clean($item['nama_item'] ?? $prItem->nama_item),
                    'qty'                      => $qtyPoInput,
                    'satuan'                   => $clean($item['satuan'] ?? ''),
                    'spesifikasi'              => $clean($item['spesifikasi'] ?? ''),
                    'keterangan'               => $clean($item['keterangan'] ?? ''),
                    'harga_unit'               => $hargaUnit,
                    'subtotal'                 => $qtyPoInput * $hargaUnit,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | 5. Recalculate ulang semua PR terdampak
        |--------------------------------------------------------------------------
        */
            $affectedPrIds = $oldPrIds
                ->merge($newPrIds)
                ->unique()
                ->values();

            foreach ($affectedPrIds as $prId) {
                $this->recalculatePurchaseRequestItems((int) $prId);
                $this->refreshPurchaseRequestPOStatus((int) $prId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil diperbarui.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Update error', [
                'public_id' => $publicId,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Purchase Order.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with(['items.purchaseRequestItem'])->findOrFail($id);

            if ($po->status !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat dihapus jika status masih Draft.',
                ], 422);
            }

            $poItems = $po->getRelation('items');

            $affectedPrIds = $poItems
                ->map(fn($item) => $item->purchaseRequestItem?->purchase_request_id)
                ->filter()
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->whereNull('deleted_at')
                ->delete();

            $po->purchaseRequests()->detach();
            $po->delete();

            foreach ($affectedPrIds as $prId) {
                $this->recalculatePurchaseRequestItems((int) $prId);
                $this->refreshPurchaseRequestPOStatus((int) $prId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Purchase Order.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // public function submit($publicId)
    // {
    //     DB::beginTransaction();

    //     try {
    //         $id = Crypt::decryptString($publicId);

    //         $po = PurchaseOrder::with(['items'])->findOrFail($id);

    //         if (!$po instanceof PurchaseOrder) {
    //             throw new \Exception('Purchase Order tidak ditemukan.');
    //         }

    //         $items = $po->getRelation('items');

    //         if (strtolower((string) $po->status) !== 'draft') {
    //             DB::rollBack();

    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Purchase Order hanya dapat disubmit jika status masih Draft.',
    //             ], 422);
    //         }

    //         if ($items->isEmpty()) {
    //             DB::rollBack();

    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Purchase Order tidak dapat disubmit karena item belum tersedia.',
    //             ], 422);
    //         }

    //         if ((float) ($po->total_nilai ?? 0) <= 0) {
    //             DB::rollBack();

    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Purchase Order tidak dapat disubmit karena total nilai masih 0.',
    //             ], 422);
    //         }

    //         $po->status = 'IN PROGRESS';
    //         $po->submitted_at = now();
    //         $po->save();

    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Purchase Order berhasil disubmit.',
    //             'data' => [
    //                 'id' => $po->id,
    //                 'public_id' => $po->encrypted_id,
    //                 'nomor_po' => $po->nomor_po,
    //                 'status' => $po->status,
    //                 'submitted_at' => $po->submitted_at,
    //             ],
    //         ], 200);
    //     } catch (\Throwable $e) {
    //         DB::rollBack();

    //         Log::error('[Purchase Order] Submit error', [
    //             'public_id' => $publicId,
    //             'message' => $e->getMessage(),
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine(),
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Gagal submit Purchase Order.',
    //             'debug' => app()->environment('local') ? $e->getMessage() : null,
    //         ], 500);
    //     }
    // }

    public function submit(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with(['items'])->findOrFail($id);
            $items = $po->getRelation('items');

            if (strtolower((string) $po->status) !== 'draft') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat disubmit jika status masih Draft.',
                ], 422);
            }

            if ($items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order tidak dapat disubmit karena item belum tersedia.',
                ], 422);
            }

            if ((float) ($po->total_nilai ?? 0) <= 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order tidak dapat disubmit karena total nilai masih 0.',
                ], 422);
            }

            $user = $request->user();

            if (empty($user->signature_path)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'need_signature' => true,
                    'message' => 'Anda belum memiliki tanda tangan digital. Silakan registrasi tanda tangan terlebih dahulu.',
                ], 422);
            }

            if (str_starts_with((string) $po->nomor_po, 'DRAFT/')) {
                $po->nomor_po = generatePONumber($po);
            }
            $po->status = 'IN PROGRESS';
            $po->submitted_at = now();
            $po->requester_signature_path = $user->signature_path;
            $po->requester_signed_at = now();
            $po->requester_signed_by = $user->id;
            $po->save();

            $this->generatePurchaseOrderApprovals($po);
            $this->createPurchaseOrderApprovalNotifications($po);
            $this->sendPurchaseOrderApprovalEmails($po);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil disubmit.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'status' => $po->status,
                    'submitted_at' => $po->submitted_at,
                    'requester_signature_path' => asset('storage/' . $po->requester_signature_path),
                    'requester_signed_at' => $po->requester_signed_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Submit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Purchase Order.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function approve(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with(['approvals'])->findOrFail($id);

            if (strtolower((string) $po->status) !== 'in progress') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat diapprove jika status masih In Progress.',
                ], 422);
            }

            $user = $request->user();

            if (empty($user->signature_path)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'need_signature' => true,
                    'message' => 'Anda belum memiliki tanda tangan digital. Silakan registrasi tanda tangan terlebih dahulu.',
                ], 422);
            }

            $currentApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
                ->where('status', 'PENDING')
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if (!$currentApproval) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada approval pending untuk Purchase Order ini.',
                ], 422);
            }

            if (
                $currentApproval->approver_type === 'USER'
                && (int) $currentApproval->approver_id !== (int) $user->id
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan approver pada tahap approval ini.',
                ], 403);
            }

            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $currentApproval->update([
                'status' => 'APPROVED',
                'approver_name_snapshot' => $user->name,
                'signature_path' => $user->signature_path,
                'signed_at' => now(),
                'approved_at' => now(),
                'notes' => $clean($request->notes),
            ]);

            $hasPendingApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
                ->where('status', 'PENDING')
                ->exists();

            if (!$hasPendingApproval) {
                $po->status = 'APPROVED';
                $po->approved_at = now();
                $po->approved_by = $user->name;
                $po->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $hasPendingApproval
                    ? 'Approval Purchase Order berhasil diproses.'
                    : 'Purchase Order berhasil disetujui.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'status' => $po->status,
                    'approved_at' => $po->approved_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Approve error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal approve Purchase Order.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function print($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with([
                'vendor',
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'purchaseRequests:id,nomor_pr,tanggal_pr,total_amount',
                'items',
            ])->findOrFail($id);

            $terbilang = $this->terbilangRupiah((float) $po->total_nilai);

            $pdf = Pdf::loadView('pdf.purchase-order', [
                'po' => $po,
                'terbilang' => $terbilang,
            ])->setPaper('a4', 'portrait');

            $fileName = str_replace(['/', '\\'], '-', $po->nomor_po);

            return $pdf->stream("PO-{$fileName}.pdf");
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Purchase Order.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function createPurchaseOrderApprovalNotifications(PurchaseOrder $po): void
    {
        $approvals = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->get();

        foreach ($approvals as $approval) {
            if ($approval->approver_type !== 'USER' || !$approval->approver_id) {
                continue;
            }

            Notification::create([
                'user_id' => $approval->approver_id,
                'type' => 'purchase_order_approval',
                'title' => 'Approval Purchase Order',
                'message' => 'Purchase Order ' . $po->nomor_po . ' menunggu approval Anda.',
                'module' => 'purchase_order',
                'reference_type' => PurchaseOrder::class,
                'reference_id' => $po->id,
                'reference_public_id' => $po->encrypted_id,
                'url' => '/non_trade/purchase_order',
            ]);
        }
    }

    private function generatePurchaseOrderApprovals(PurchaseOrder $po): void
    {
        PurchaseOrderApproval::where('purchase_order_id', $po->id)->delete();

        $flow = ApprovalFlow::with('steps')
            ->where('module_name', 'PURCHASE_ORDER')
            ->where('is_active', true)
            ->first();

        if (!$flow) {
            throw new \Exception('Approval flow Purchase Order belum disetting.');
        }

        foreach ($flow->steps as $step) {
            PurchaseOrderApproval::create([
                'purchase_order_id' => $po->id,
                'step_order' => $step->step_order,
                'approver_type' => $step->approver_type,
                'approver_id' => $step->approver_id,
                'approver_name_snapshot' => null,
                'label' => $step->label,
                'status' => 'PENDING',
            ]);
        }
    }

    private function terbilang($angka): string
    {
        $angka = abs((int) $angka);
        $huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

        if ($angka < 12) {
            return $huruf[$angka];
        }

        if ($angka < 20) {
            return $this->terbilang($angka - 10) . ' Belas';
        }

        if ($angka < 100) {
            return $this->terbilang($angka / 10) . ' Puluh ' . $this->terbilang($angka % 10);
        }

        if ($angka < 200) {
            return 'Seratus ' . $this->terbilang($angka - 100);
        }

        if ($angka < 1000) {
            return $this->terbilang($angka / 100) . ' Ratus ' . $this->terbilang($angka % 100);
        }

        if ($angka < 2000) {
            return 'Seribu ' . $this->terbilang($angka - 1000);
        }

        if ($angka < 1000000) {
            return $this->terbilang($angka / 1000) . ' Ribu ' . $this->terbilang($angka % 1000);
        }

        if ($angka < 1000000000) {
            return $this->terbilang($angka / 1000000) . ' Juta ' . $this->terbilang($angka % 1000000);
        }

        return $this->terbilang($angka / 1000000000) . ' Miliar ' . $this->terbilang($angka % 1000000000);
    }

    private function terbilangRupiah(float $angka): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->terbilang($angka))) . ' Rupiah';
    }

    private function recalculatePurchaseRequestItems(int $purchaseRequestId): void
    {
        $prItems = PurchaseRequestItem::where('purchase_request_id', $purchaseRequestId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get();

        foreach ($prItems as $prItem) {
            $qtyPo = PurchaseOrderItem::query()
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                ->where('purchase_order_items.purchase_request_item_id', $prItem->id)
                ->whereNull('purchase_order_items.deleted_at')
                ->whereNull('purchase_orders.deleted_at')
                ->sum('purchase_order_items.qty');

            $prItem->qty_po = (float) $qtyPo;
            $prItem->qty_outstanding = max((float) $prItem->qty - (float) $qtyPo, 0);
            $prItem->save();
        }
    }

    private function refreshPurchaseRequestPOStatus(int $purchaseRequestId): void
    {
        $pr = PurchaseRequest::find($purchaseRequestId);

        if (!$pr instanceof PurchaseRequest) {
            return;
        }

        $items = PurchaseRequestItem::where('purchase_request_id', $pr->id)
            ->whereNull('deleted_at')
            ->get();

        $totalQty = (float) $items->sum(function ($item) {
            return (float) ($item->qty ?? 0);
        });

        $totalQtyPo = (float) $items->sum(function ($item) {
            return (float) ($item->qty_po ?? 0);
        });

        $totalOutstanding = (float) $items->sum(function ($item) {
            return (float) ($item->qty_outstanding ?? 0);
        });

        /*
    |--------------------------------------------------------------------------
    | STATUS PO
    |--------------------------------------------------------------------------
    */

        if ($totalQtyPo <= 0) {

            // BELUM ADA PO
            $pr->status_po = 'OPEN';
        } elseif ($totalOutstanding > 0) {

            // SUDAH ADA PO TAPI MASIH ADA OUTSTANDING
            $pr->status_po = 'PARTIAL';
        } else {

            // SEMUA QTY SUDAH MASUK PO
            $pr->status_po = 'COMPLETED';
        }

        $pr->save();
    }

    private function generateDraftPONumber(): string
    {
        $year = now()->format('Y');

        $lastPo = PurchaseOrder::whereYear('created_at', $year)
            ->where('nomor_po', 'ILIKE', "DRAFT/PO/{$year}/%")
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;

        if ($lastPo) {
            $lastNumber = (int) substr($lastPo->nomor_po, -4);
            $nextNumber = $lastNumber + 1;
        }

        return 'DRAFT/PO/' . $year . '/' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function sendPurchaseOrderApprovalEmails(PurchaseOrder $po): void
    {
        $approvals = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->get();

        foreach ($approvals as $approval) {
            if ($approval->approver_type !== 'USER' || !$approval->approver_id) {
                continue;
            }

            $approver = User::find($approval->approver_id);

            if (!$approver || !$approver->email) {
                continue;
            }

            Mail::to($approver->email)
                ->send(new PurchaseOrderApprovalMail($po, $approver));
        }
    }
}
