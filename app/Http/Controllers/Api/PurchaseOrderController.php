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
use App\Services\NonTrade\PurchaseOrder\PurchaseOrderNotificationService;
use App\Services\NonTrade\PurchaseOrder\PurchaseOrderMailService;
use App\Services\NonTrade\PurchaseOrder\PurchaseOrderRollbackService;
use App\Services\NonTrade\PurchaseOrder\PurchaseOrderApprovalService;

class PurchaseOrderController extends Controller
{
    protected PurchaseOrderNotificationService $poNotificationService;
    protected PurchaseOrderMailService $poMailService;
    protected PurchaseOrderRollbackService $poRollbackService;
    protected PurchaseOrderApprovalService $poApprovalService;

    public function __construct(
        PurchaseOrderNotificationService $poNotificationService,
        PurchaseOrderMailService $poMailService,
        PurchaseOrderRollbackService $poRollbackService,
        PurchaseOrderApprovalService $poApprovalService,
    ) {
        $this->poNotificationService = $poNotificationService;
        $this->poMailService = $poMailService;
        $this->poRollbackService = $poRollbackService;
        $this->poApprovalService = $poApprovalService;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $userRoleIds = collect();

            if (isset($user->role_id) && $user->role_id) {
                $userRoleIds->push((int) $user->role_id);
            }

            /**
             * Kalau suatu saat user-role memakai pivot role_user.
             */
            if (\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                $pivotRoleIds = DB::table('role_user')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id);

                $userRoleIds = $userRoleIds->merge($pivotRoleIds);
            }

            /**
             * Kalau suatu saat user-role memakai pivot user_roles.
             */
            if (\Illuminate\Support\Facades\Schema::hasTable('user_roles')) {
                $pivotRoleIds = DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id);

                $userRoleIds = $userRoleIds->merge($pivotRoleIds);
            }

            $userRoleIds = $userRoleIds
                ->filter()
                ->unique()
                ->values();

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
        | - Creator/requester melihat PO miliknya.
        | - Approver USER melihat PO jika approver_id = user id.
        | - Approver ROLE melihat PO jika approver_id ada di role_id user.
        |--------------------------------------------------------------------------
        */

            $query->where(function ($visibilityQuery) use ($user, $userRoleIds) {
                /**
                 * PO milik requester / creator.
                 */
                $visibilityQuery->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('requester_signed_by', $user->id);
                });

                /**
                 * PO yang user ini menjadi approver langsung.
                 */
                $visibilityQuery->orWhereHas('approvals', function ($q) use ($user) {
                    $q->where('approver_type', 'USER')
                        ->where('approver_id', $user->id);
                });

                /**
                 * PO yang user ini menjadi approver berdasarkan role.
                 */
                if ($userRoleIds->isNotEmpty()) {
                    $visibilityQuery->orWhereHas('approvals', function ($q) use ($userRoleIds) {
                        $q->where('approver_type', 'ROLE')
                            ->whereIn('approver_id', $userRoleIds->toArray());
                    });
                }
            });

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

            if ($request->search) {
                $search = trim((string) $request->search);

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('nomor_po', 'ILIKE', "%{$search}%");
                    });
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Status
        |--------------------------------------------------------------------------
        | Jangan filter kalau status = semua / all / kosong.
        |--------------------------------------------------------------------------
        */

            $status = strtoupper(trim((string) $request->status));

            if (
                $status !== ''
                && $status !== 'ALL'
                && $status !== 'SEMUA'
            ) {
                $query->whereRaw('UPPER(status) = ?', [$status]);
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
            $perPage = $perPage > 0 ? $perPage : 10;

            $pos = $query->paginate($perPage);

            $pos->getCollection()->transform(function ($po) use ($user, $userRoleIds) {
                /*
            |--------------------------------------------------------------------------
            | Current Approval
            |--------------------------------------------------------------------------
            | Step aktif sekarang statusnya WAITING.
            | PENDING artinya belum waktunya approve.
            |--------------------------------------------------------------------------
            */

                $currentApproval = $po->approvals
                    ->where('status', 'WAITING')
                    ->sortBy('step_order')
                    ->first();

                $canApprove = false;

                if ($currentApproval) {
                    $approverType = strtoupper((string) $currentApproval->approver_type);

                    if (
                        $approverType === 'USER'
                        && (int) $currentApproval->approver_id === (int) $user->id
                    ) {
                        $canApprove = true;
                    }

                    if (
                        $approverType === 'ROLE'
                        && $userRoleIds->contains((int) $currentApproval->approver_id)
                    ) {
                        $canApprove = true;
                    }
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
                    'status_receive' => $po->status_receive,

                    'can_approve' => $canApprove,

                    'current_approval' => $currentApproval ? [
                        'id' => $currentApproval->id,
                        'step_order' => $currentApproval->step_order,
                        'approver_type' => $currentApproval->approver_type,
                        'approver_id' => $currentApproval->approver_id,
                        'status' => $currentApproval->status,
                    ] : null,

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
                'items.*.satuan' => ['required', 'integer', 'exists:units,id'],
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
                    'satuan' => (int) $item['satuan'],
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

    public function edit($publicId)
    {
        return $this->show($publicId);
    }

    public function show($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with([
                'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'purchaseRequests:id,nomor_pr,tanggal_pr,total_amount,recommended_vendor_id,cabang,id_department',
                'purchaseRequests.recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'purchaseRequests.items.unit',
                'items.unit:id,kode,nama',
                'items.purchaseRequestItem.unit',
                'creator',
                'requesterSigner',
                'approvals',
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
                    'tanggal_pr' => $po->tanggal_po
                        ? \Carbon\Carbon::parse($po->tanggal_po)->format('Y-m-d')
                        : null,

                    'vendor_data' => $po->vendor ? [
                        'vendor_id' => $po->vendor->id,
                        'id' => $po->vendor->id,
                        'nama_vendor' => $po->vendor->nama_vendor ?? '-',
                        'status_pkp' => $po->vendor->status_pkp ?? 'NON_PKP',
                        'jenis_pembayaran' => $po->vendor->jenis_pembayaran ?? null,
                        'top' => $po->vendor->top ?? null,
                    ] : null,

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
                    'status_receive' => $po->status_receive,
                    'notes' => $po->notes,

                    'created_at' => $po->created_at,
                    'created_by' => $po->created_by,
                    'created_by_name' => $po->creator?->name ?? '-',

                    'submitted_at' => $po->submitted_at,
                    'submitted_by' => $po->requester_signed_by,
                    'submitted_by_name' => $po->requesterSigner?->name ?? '-',

                    'purchase_requests' => $purchaseRequests->map(function ($pr) use ($items) {
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

                            'items' => $pr->items->map(function ($prItem) use ($items) {
                                $currentPoItem = $items
                                    ->where('purchase_request_item_id', $prItem->id)
                                    ->first();

                                $currentPoQty = $currentPoItem
                                    ? (float) ($currentPoItem->qty ?? 0)
                                    : 0;

                                $qtyPr = (float) ($prItem->qty ?? 0);
                                $qtyPoRaw = (float) ($prItem->qty_po ?? 0);
                                $qtyOutstandingRaw = (float) ($prItem->qty_outstanding ?? 0);

                                $qtyPoExisting = max($qtyPoRaw - $currentPoQty, 0);
                                $editableOutstanding = $qtyOutstandingRaw + $currentPoQty;

                                return [
                                    'id' => $prItem->id,
                                    'purchase_request_item_id' => $prItem->id,
                                    'purchase_request_id' => $prItem->purchase_request_id,

                                    'nama_item' => $prItem->nama_item ?? '-',
                                    'qty' => $qtyPr,
                                    'qty_pr' => $qtyPr,
                                    'qty_po' => $qtyPoExisting,
                                    'qty_po_existing' => $qtyPoExisting,
                                    'qty_outstanding' => $editableOutstanding,

                                    'satuan_id' => $prItem->satuan,
                                    'satuan' => [
                                        'id' => $prItem->unit?->id,
                                        'kode' => $prItem->unit?->kode ?? '-',
                                        'nama' => $prItem->unit?->nama ?? '-',
                                    ],
                                    'unit' => [
                                        'id' => $prItem->unit?->id,
                                        'kode' => $prItem->unit?->kode ?? '-',
                                        'nama' => $prItem->unit?->nama ?? '-',
                                    ],

                                    'harga_unit' => (float) ($prItem->harga_unit ?? $currentPoItem?->harga_unit ?? 0),
                                    'subtotal' => (float) ($prItem->subtotal ?? 0),
                                    'keterangan' => $prItem->keterangan ?? '-',

                                    /*
                                |--------------------------------------------------------------------------
                                | Penanda tambahan untuk FE
                                |--------------------------------------------------------------------------
                                | Tidak wajib dipakai, tapi aman untuk debugging.
                                |--------------------------------------------------------------------------
                                */
                                    'is_in_current_po' => $currentPoItem ? true : false,
                                    'current_po_qty' => $currentPoQty,
                                ];
                            })->values(),
                        ];
                    })->values(),

                    'items' => $items->map(function ($item) {
                        $prItem = $item->purchaseRequestItem;

                        $qtyPo = (float) ($item->qty ?? 0);
                        $qtyReceived = (float) ($item->qty_received ?? 0);
                        $qtyOutstandingReceive = $item->qty_outstanding_receive !== null
                            ? (float) $item->qty_outstanding_receive
                            : max($qtyPo - $qtyReceived, 0);

                        return [
                            'id' => $item->id,
                            'purchase_order_item_id' => $item->id,

                            'purchase_request_id' => $prItem->purchase_request_id ?? $item->purchase_request_id ?? null,
                            'purchase_request_item_id' => $item->purchase_request_item_id,

                            'nama_item' => $item->nama_item,
                            'qty' => $qtyPo,

                            'qty_received' => $qtyReceived,
                            'qty_outstanding_receive' => $qtyOutstandingReceive,

                            'satuan_id' => $item->satuan,
                            'satuan' => $item->unit->nama ?? $item->unit->kode ?? $item->satuan,
                            'unit' => [
                                'id' => $item->unit->id ?? null,
                                'kode' => $item->unit->kode ?? '-',
                                'nama' => $item->unit->nama ?? '-',
                            ],

                            'harga_unit' => $item->harga_unit,
                            'subtotal' => $item->subtotal,
                            'keterangan' => $item->keterangan,

                            'purchase_request_item' => $prItem ? [
                                'id' => $prItem->id,
                                'purchase_request_item_id' => $prItem->id,
                                'purchase_request_id' => $prItem->purchase_request_id,
                                'nama_item' => $prItem->nama_item ?? $item->nama_item,
                                'qty' => $prItem->qty,
                                'qty_pr' => $prItem->qty,
                                'qty_po' => $prItem->qty_po,
                                'qty_outstanding' => $prItem->qty_outstanding,
                                'satuan_id' => $prItem->satuan,
                                'unit' => [
                                    'id' => $prItem->unit?->id,
                                    'kode' => $prItem->unit?->kode ?? '-',
                                    'nama' => $prItem->unit?->nama ?? '-',
                                ],
                            ] : null,
                        ];
                    })->values(),

                    'approvals' => $po->approvals
                        ->sortBy('step_order')
                        ->map(function ($approval) {
                            return [
                                'id' => $approval->id,
                                'step_order' => $approval->step_order,
                                'label' => $approval->label,
                                'approver_type' => $approval->approver_type,
                                'approver_id' => $approval->approver_id,
                                'approver_name_snapshot' => $approval->approver_name_snapshot,
                                'status' => $approval->status,
                                'approved_at' => $approval->approved_at,
                                'rejected_at' => $approval->rejected_at,
                                'signed_at' => $approval->signed_at,
                                'notes' => $approval->notes,
                            ];
                        })
                        ->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Purchase Order] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Purchase Order.',
                'data' => null,
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
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
                'items.*.satuan' => ['required', 'integer', 'exists:units,id'],
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

            $po = PurchaseOrder::with([
                'items.purchaseRequestItem',
                'purchaseRequests',
            ])->lockForUpdate()->findOrFail($id);

            if (strtoupper((string) $po->status) !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat dihapus jika status masih Draft.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Rollback PR item allocation sebelum PO dihapus
        |--------------------------------------------------------------------------
        | Ini penting:
        | - qty_po PR item dikurangi qty PO
        | - qty_outstanding dihitung ulang
        | - status_po PR disesuaikan OPEN / PARTIAL / COMPLETED
        |--------------------------------------------------------------------------
        */
            $this->poRollbackService->rollbackPurchaseRequestItems($po);

            /*
        |--------------------------------------------------------------------------
        | Hapus PO item, detach PR, lalu hapus PO
        |--------------------------------------------------------------------------
        */
            PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->whereNull('deleted_at')
                ->delete();

            $po->purchaseRequests()->detach();

            $po->delete();

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
            DB::commit();

            try {
                $po->refresh();

                $this->poNotificationService->notifyApprovalRequest($po);
                $this->poMailService->sendApprovalRequest($po);
            } catch (\Throwable $mailError) {
                Log::error('[Purchase Order] Email approver submit gagal dikirim', [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'message' => $mailError->getMessage(),
                ]);
            }

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

            $currentApproval = $this->poApprovalService->getCurrentPendingApproval($po);

            if (!$currentApproval) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada approval pending untuk Purchase Order ini.',
                ], 422);
            }

            if (!$this->poApprovalService->userCanApprove($currentApproval, $user)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan approver pada tahap approval ini.',
                ], 403);
            }

            $this->poApprovalService->approveCurrentStep(
                $currentApproval,
                $user,
                $request->notes
            );

            $hasPendingApproval = $this->poApprovalService->hasPendingApproval($po);

            if (!$hasPendingApproval) {
                $this->poApprovalService->markPurchaseOrderApproved($po, $user);
                $po->refresh();
            }

            $this->poNotificationService->notifyApprovalStep(
                $po,
                $user,
                $currentApproval,
                $hasPendingApproval
            );

            try {
                $this->poMailService->sendApprovalStep(
                    $po,
                    $user,
                    $hasPendingApproval
                );
            } catch (\Throwable $mailError) {
                Log::error('[Purchase Order] Email requester approval status gagal dikirim', [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'message' => $mailError->getMessage(),
                ]);
            }

            if ($hasPendingApproval) {
                $this->poNotificationService->notifyApprovalRequest($po);

                try {
                    $this->poMailService->sendApprovalRequest($po);
                } catch (\Throwable $mailError) {
                    Log::error('[Purchase Order] Email next approver gagal dikirim', [
                        'po_id' => $po->id,
                        'nomor_po' => $po->nomor_po,
                        'message' => $mailError->getMessage(),
                    ]);
                }
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

    public function reject(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $id = Crypt::decryptString($publicId);

            $po = PurchaseOrder::with([
                'approvals',
                'items',
                'purchaseRequests',
            ])->findOrFail($id);

            if (strtolower((string) $po->status) !== 'in progress') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order hanya dapat direject jika status masih In Progress.',
                ], 422);
            }

            $user = $request->user();

            $currentApproval = $this->poApprovalService->getCurrentPendingApproval($po);

            if (!$currentApproval) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada approval yang sedang menunggu untuk Purchase Order ini.',
                ], 422);
            }

            if (!$this->poApprovalService->userCanApprove($currentApproval, $user)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan approver pada tahap approval ini.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Reject current WAITING step
        |--------------------------------------------------------------------------
        */
            $this->poApprovalService->rejectCurrentStep(
                $currentApproval,
                $user,
                $request->notes
            );

            $currentApproval->refresh();

            /*
        |--------------------------------------------------------------------------
        | Stop approval flow
        |--------------------------------------------------------------------------
        | Step berikutnya yang masih PENDING/WAITING dibatalkan.
        | Tidak ada notifikasi ke approver berikutnya.
        |--------------------------------------------------------------------------
        */
            $this->poApprovalService->cancelRemainingPendingApprovals($po);

            /*
        |--------------------------------------------------------------------------
        | Rollback PR item qty_po / qty_outstanding
        |--------------------------------------------------------------------------
        */
            $this->poRollbackService->rollbackPurchaseRequestItems($po);

            /*
        |--------------------------------------------------------------------------
        | Mark PO rejected
        |--------------------------------------------------------------------------
        */
            $this->poApprovalService->markPurchaseOrderRejected($po);

            $po->refresh();

            /*
        |--------------------------------------------------------------------------
        | Notify requester only
        |--------------------------------------------------------------------------
        */
            $this->poNotificationService->notifyRejected($po, $user);

            try {
                $this->poMailService->sendRejected(
                    $po,
                    $user,
                    $request->notes
                );
            } catch (\Throwable $mailError) {
                Log::error('[Purchase Order] Email reject gagal dikirim', [
                    'po_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'message' => $mailError->getMessage(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil direject.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'status' => $po->status,
                    'rejected_at' => $currentApproval->rejected_at,
                    'rejected_by' => $currentApproval->approver_name_snapshot,
                    'reject_notes' => $currentApproval->notes,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Order] Reject error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal reject Purchase Order.',
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
                'items.unit:id,kode,nama',
                'requesterSignedBy:id,name',
                'approvals' => function ($q) {
                    $q->orderBy('step_order');
                },
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

    public function dropdownReceivable(Request $request)
    {
        try {
            $remainingSql = "
            (
                purchase_order_items.qty
                - COALESCE(purchase_order_items.qty_received, 0)
                - COALESCE((
                    SELECT SUM(gri.qty_receive)
                    FROM goods_receive_items gri
                    JOIN goods_receives gr ON gr.id = gri.goods_receive_id
                    WHERE gri.purchase_order_item_id = purchase_order_items.id
                      AND gr.status = 'DRAFT'
                      AND gr.deleted_at IS NULL
                ), 0)
            )
        ";

            $purchaseOrders = PurchaseOrder::query()
                ->with([
                    'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                    'cabangData:id,nama_cabang,inisial_cabang',
                    'departmentData:id,kode,nama',
                    'items' => function ($q) use ($remainingSql) {
                        $q->with('unit:id,kode,nama')
                            ->whereNull('deleted_at')
                            ->whereRaw("{$remainingSql} > 0");
                    },
                ])
                ->whereRaw('UPPER(status) = ?', ['APPROVED'])
                ->where(function ($q) {
                    $q->whereNull('status_receive')
                        ->orWhereRaw('UPPER(status_receive) IN (?, ?)', ['OPEN', 'PARTIAL']);
                })
                ->whereHas('items', function ($q) use ($remainingSql) {
                    $q->whereNull('deleted_at')
                        ->whereRaw("{$remainingSql} > 0");
                })
                ->orderByDesc('id')
                ->get();

            $data = $purchaseOrders->map(function ($po) {
                $items = $po->items
                    ->map(function ($item) {
                        $qty = (float) ($item->qty ?? 0);
                        $qtyReceived = (float) ($item->qty_received ?? 0);
                        $hargaUnit = (float) ($item->harga_unit ?? 0);

                        $draftQty = (float) DB::table('goods_receive_items as gri')
                            ->join('goods_receives as gr', 'gr.id', '=', 'gri.goods_receive_id')
                            ->where('gri.purchase_order_item_id', $item->id)
                            ->where('gr.status', 'DRAFT')
                            ->whereNull('gr.deleted_at')
                            ->sum('gri.qty_receive');

                        $qtyOutstanding = max($qty - $qtyReceived - $draftQty, 0);

                        return [
                            'id' => $item->id,
                            'nama_item' => $item->nama_item,

                            'qty' => $qty,
                            'qty_received' => $qtyReceived,
                            'qty_draft_receive' => $draftQty,
                            'qty_outstanding_receive' => $qtyOutstanding,

                            'satuan_id' => $item->satuan,
                            'satuan' => $item->unit->nama ?? $item->unit->kode ?? '-',
                            'unit' => $item->unit->nama ?? $item->unit->kode ?? '-',

                            'harga_unit' => $hargaUnit,
                            'subtotal' => (float) ($item->subtotal ?? 0),
                            'subtotal_gr' => $qtyReceived * $hargaUnit,
                            'subtotal_draft' => $draftQty * $hargaUnit,
                            'subtotal_outstanding' => $qtyOutstanding * $hargaUnit,

                            'keterangan' => $item->keterangan,
                        ];
                    })
                    ->filter(fn($item) => (float) $item['qty_outstanding_receive'] > 0)
                    ->values();

                return [
                    'id' => Crypt::encryptString((string) $po->id),
                    'public_id' => Crypt::encryptString((string) $po->id),
                    'nomor_po' => $po->nomor_po,
                    'tanggal_po' => $po->tanggal_po,

                    'cabang_id' => $po->cabang,
                    'cabang' => $po->cabangData
                        ? ($po->cabangData->inisial_cabang ?? '-')
                        : '-',

                    'department_id' => $po->id_department,
                    'department' => $po->departmentData
                        ? ($po->departmentData->kode ?? '-')
                        : '-',

                    'status' => $po->status,
                    'status_receive' => $po->status_receive,

                    'vendor_id' => $po->vendor_id,
                    'vendor' => $po->vendor ? [
                        'id' => $po->vendor->id,
                        'nama_vendor' => $po->vendor->nama_vendor ?? '-',
                        'status_pkp' => $po->vendor->status_pkp ?? 'NON_PKP',
                        'jenis_pembayaran' => $po->vendor->jenis_pembayaran ?? null,
                        'top' => $po->vendor->top ?? null,
                    ] : null,

                    'items' => $items,
                ];
            })
                ->filter(fn($po) => $po['items']->count() > 0)
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil dimuat.',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Order] dropdownReceivable error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat Purchase Order.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function receivableItems(Request $request, $publicId)
    {
        try {
            try {
                $poId = Crypt::decryptString($publicId);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID Purchase Order tidak valid.',
                    'data' => null,
                ], 422);
            }

            $availableQtySql = "
            (
                purchase_order_items.qty
                - COALESCE(purchase_order_items.qty_received, 0)
                - COALESCE((
                    SELECT SUM(gri.qty_receive)
                    FROM goods_receive_items gri
                    JOIN goods_receives gr ON gr.id = gri.goods_receive_id
                    WHERE gri.purchase_order_item_id = purchase_order_items.id
                      AND gr.status = 'DRAFT'
                      AND gr.deleted_at IS NULL
                ), 0)
            )
        ";

            $purchaseOrder = PurchaseOrder::query()
                ->with([
                    'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                    'cabangData:id,nama_cabang,inisial_cabang',
                    'departmentData:id,kode,nama',
                    'items' => function ($q) use ($availableQtySql) {
                        $q->with('unit:id,kode,nama')
                            ->whereNull('deleted_at')
                            ->whereRaw("{$availableQtySql} > 0");
                    },
                ])
                ->where('id', $poId)
                ->whereRaw('UPPER(status) = ?', ['APPROVED'])
                ->where(function ($q) {
                    $q->whereNull('status_receive')
                        ->orWhereRaw('UPPER(status_receive) IN (?, ?)', ['OPEN', 'PARTIAL']);
                })
                ->first();

            if (!$purchaseOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Order tidak ditemukan atau tidak tersedia untuk Goods Receive.',
                    'data' => null,
                ], 404);
            }

            $items = $purchaseOrder->items
                ->map(function ($item) {
                    $qty = (float) ($item->qty ?? 0);
                    $qtyPosted = (float) ($item->qty_received ?? 0);
                    $hargaUnit = (float) ($item->harga_unit ?? 0);

                    $qtyDraft = (float) DB::table('goods_receive_items as gri')
                        ->join('goods_receives as gr', 'gr.id', '=', 'gri.goods_receive_id')
                        ->where('gri.purchase_order_item_id', $item->id)
                        ->where('gr.status', 'DRAFT')
                        ->whereNull('gr.deleted_at')
                        ->sum('gri.qty_receive');

                    $qtyOutstanding = max($qty - $qtyPosted - $qtyDraft, 0);

                    return [
                        'id' => Crypt::encryptString((string) $item->id),
                        'public_id' => Crypt::encryptString((string) $item->id),
                        'po_item_id' => Crypt::encryptString((string) $item->id),

                        'item_id' => $item->item_id ?? null,
                        'nama_item' => $item->nama_item,
                        'item_name' => $item->nama_item,

                        'qty' => $qty,
                        'ordered_qty' => $qty,

                        'qty_received' => $qtyPosted,
                        'received_qty' => $qtyPosted,

                        'qty_draft_receive' => $qtyDraft,
                        'draft_receive_qty' => $qtyDraft,

                        'qty_outstanding_receive' => $qtyOutstanding,
                        'remaining_qty' => $qtyOutstanding,

                        'satuan_id' => $item->satuan,
                        'satuan' => $item->unit->nama ?? $item->unit->kode ?? '-',
                        'unit' => $item->unit->nama ?? $item->unit->kode ?? '-',

                        'harga_unit' => $hargaUnit,
                        'subtotal' => (float) ($item->subtotal ?? 0),
                        'subtotal_gr' => $qtyPosted * $hargaUnit,
                        'subtotal_draft' => $qtyDraft * $hargaUnit,
                        'subtotal_outstanding' => $qtyOutstanding * $hargaUnit,

                        'keterangan' => $item->keterangan,
                        'notes' => $item->keterangan,
                    ];
                })
                ->filter(fn($item) => (float) $item['remaining_qty'] > 0)
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Item Purchase Order berhasil dimuat.',
                'data' => [
                    'id' => Crypt::encryptString((string) $purchaseOrder->id),
                    'public_id' => Crypt::encryptString((string) $purchaseOrder->id),

                    'po_number' => $purchaseOrder->nomor_po,
                    'nomor_po' => $purchaseOrder->nomor_po,
                    'tanggal_po' => $purchaseOrder->tanggal_po,

                    'vendor_id' => $purchaseOrder->vendor_id,
                    'vendor_name' => $purchaseOrder->vendor->nama_vendor ?? '-',
                    'vendor' => $purchaseOrder->vendor ? [
                        'id' => $purchaseOrder->vendor->id,
                        'nama_vendor' => $purchaseOrder->vendor->nama_vendor ?? '-',
                        'status_pkp' => $purchaseOrder->vendor->status_pkp ?? 'NON_PKP',
                        'jenis_pembayaran' => $purchaseOrder->vendor->jenis_pembayaran ?? null,
                        'top' => $purchaseOrder->vendor->top ?? null,
                    ] : null,

                    'cabang_id' => $purchaseOrder->cabang,
                    'cabang_name' => $purchaseOrder->cabangData->nama_cabang ?? '-',
                    'cabang' => [
                        'id' => $purchaseOrder->cabangData->id ?? null,
                        'nama_cabang' => $purchaseOrder->cabangData->nama_cabang ?? '-',
                        'inisial_cabang' => $purchaseOrder->cabangData->inisial_cabang ?? '-',
                    ],

                    'department_id' => $purchaseOrder->id_department,
                    'department_name' => $purchaseOrder->departmentData->nama ?? '-',
                    'department' => [
                        'id' => $purchaseOrder->departmentData->id ?? null,
                        'kode' => $purchaseOrder->departmentData->kode ?? '-',
                        'nama' => $purchaseOrder->departmentData->nama ?? '-',
                    ],

                    'status' => $purchaseOrder->status,
                    'status_receive' => $purchaseOrder->status_receive,

                    'items' => $items,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Order] receivableItems error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat item Purchase Order.',
                'data' => null,
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function generatePurchaseOrderApprovals(PurchaseOrder $po): void
    {
        PurchaseOrderApproval::where('purchase_order_id', $po->id)->delete();

        $amount = (float) ($po->total_nilai ?? 0);

        if ($amount <= 0) {
            throw new \Exception('Total nilai Purchase Order tidak valid untuk approval.');
        }

        $flows = ApprovalFlow::with(['steps'])
            ->where('module_name', 'procurement')
            ->where('document_type', 'PO')
            ->where('is_active', true)
            ->where(function ($query) use ($amount) {
                $query
                    ->where(function ($q) {
                        $q->where(function ($qq) {
                            $qq->whereNull('min_amount')
                                ->orWhere('min_amount', '<=', 0);
                        })
                            ->where(function ($qq) {
                                $qq->whereNull('max_amount')
                                    ->orWhere('max_amount', '<=', 0);
                            });
                    })
                    ->orWhere(function ($q) use ($amount) {
                        $q->where(function ($qq) use ($amount) {
                            $qq->whereNull('min_amount')
                                ->orWhere('min_amount', '<=', $amount);
                        })
                            ->where(function ($qq) {
                                $qq->whereNotNull('min_amount')
                                    ->orWhereNotNull('max_amount');
                            });
                    });
            })
            ->orderByRaw('COALESCE(min_amount, 0) ASC')
            ->orderByRaw('COALESCE(max_amount, 0) ASC')
            ->get();

        if ($flows->isEmpty()) {
            throw new \Exception('Approval flow Purchase Order belum disetting untuk nominal PO ini.');
        }

        $approvalSteps = collect();
        $usedApproverKeys = [];

        foreach ($flows as $flow) {
            foreach ($flow->steps->sortBy('step_order') as $step) {
                $approverType = strtoupper((string) $step->approver_type);
                $approverKey = $approverType . '-' . $step->approver_id;

                if (in_array($approverKey, $usedApproverKeys, true)) {
                    continue;
                }

                $usedApproverKeys[] = $approverKey;

                $approvalSteps->push([
                    'approval_flow_id' => $flow->id,
                    'approval_flow_step_id' => $step->id,
                    'approver_type' => $approverType,
                    'approver_id' => $step->approver_id,
                    'label' => $step->label,
                ]);
            }
        }

        if ($approvalSteps->isEmpty()) {
            throw new \Exception('Step approval Purchase Order belum disetting.');
        }

        foreach ($approvalSteps->values() as $index => $step) {
            PurchaseOrderApproval::create([
                'purchase_order_id' => $po->id,
                'step_order' => $index + 1,
                'approver_type' => $step['approver_type'],
                'approver_id' => $step['approver_id'],
                'approver_name_snapshot' => null,
                'label' => $step['label'],
                'status' => $index === 0 ? 'WAITING' : 'PENDING',
            ]);
        }

        Log::info('[Purchase Order] Approval generated', [
            'po_id' => $po->id,
            'nomor_po' => $po->nomor_po,
            'amount' => $amount,
            'flows' => $flows->map(fn($flow) => [
                'id' => $flow->id,
                'name' => $flow->name,
                'document_type' => $flow->document_type,
                'min_amount' => $flow->min_amount,
                'max_amount' => $flow->max_amount,
            ])->values()->toArray(),
            'steps' => $approvalSteps->values()->toArray(),
        ]);
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

                /*
            |--------------------------------------------------------------------------
            | Jangan hitung PO yang sudah reject/cancel
            |--------------------------------------------------------------------------
            */
                ->whereNotIn('purchase_orders.status', [
                    'REJECTED',
                    'CANCELLED',
                ])
                ->sum('purchase_order_items.qty');

            $qtyRequest = (float) ($prItem->qty ?? 0);
            $qtyPo = (float) $qtyPo;

            $prItem->update([
                'qty_po' => $qtyPo,
                'qty_outstanding' => max($qtyRequest - $qtyPo, 0),
            ]);
        }
    }

    private function refreshPurchaseRequestPOStatus(int $purchaseRequestId): void
    {
        $pr = PurchaseRequest::where('id', $purchaseRequestId)
            ->lockForUpdate()
            ->first();

        if (!$pr) {
            return;
        }

        $summary = PurchaseRequestItem::query()
            ->where('purchase_request_id', $purchaseRequestId)
            ->whereNull('deleted_at')
            ->selectRaw('
            COALESCE(SUM(qty), 0) as total_qty_request,
            COALESCE(SUM(qty_po), 0) as total_qty_po,
            COALESCE(SUM(qty_outstanding), 0) as total_qty_outstanding
        ')
            ->first();

        $totalQtyRequest = (float) ($summary->total_qty_request ?? 0);
        $totalQtyPo = (float) ($summary->total_qty_po ?? 0);
        $totalOutstanding = (float) ($summary->total_qty_outstanding ?? 0);

        if ($totalQtyPo <= 0) {
            $statusPo = 'OPEN';
        } elseif ($totalOutstanding > 0 && $totalQtyPo < $totalQtyRequest) {
            $statusPo = 'PARTIAL';
        } else {
            $statusPo = 'COMPLETED';
        }

        $pr->update([
            'status_po' => $statusPo,
        ]);
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
