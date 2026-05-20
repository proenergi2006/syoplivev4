<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseOrderApprovalHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PurchaseOrder::with([
                'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'purchaseRequests:id,nomor_pr',
            ])->orderByDesc('id');

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

            $perPage = (int) ($request->per_page ?? 10);
            $pos = $query->paginate($perPage);

            $pos->getCollection()->transform(function ($po) {
                return [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'tanggal_po' => $po->tanggal_po,

                    'vendor_id' => $po->vendor_id,
                    'vendor' => $po->vendor->nama_vendor ?? '-',
                    'status_pkp' => $po->vendor->status_pkp ?? 'NON_PKP',

                    // payment info ambil dari master vendor
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

    public function submit($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with(['items'])->findOrFail($id);

            if (!$po instanceof PurchaseOrder) {
                throw new \Exception('Purchase Order tidak ditemukan.');
            }

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

            $po->status = 'IN PROGRESS';
            $po->submitted_at = now();
            $po->save();

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
}
