<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceive;
use App\Models\GoodsReceiveAttachment;
use App\Models\GoodsReceiveItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\NonTrade\GoodsReceive\GoodsReceivePostingService;
use App\Services\NonTrade\GoodsReceive\GoodsReceiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GoodsReceiveController extends Controller
{
    public function __construct(
        protected GoodsReceiveService $goodsReceiveService,
        protected GoodsReceivePostingService $goodsReceivePostingService,
    ) {}

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission Goods Receipt
        |--------------------------------------------------------------------------
        | Samakan helper ini dengan yang sudah digunakan pada index PR.
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission(
                'goods_receive.view',
            );

            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_receive.view',
                    ),
                ),
            );

            $canCreate = $user->hasPermission(
                'goods_receive.create',
            );

            $canUpdate = $user->hasPermission(
                'goods_receive.update',
            );

            $canDelete = $user->hasPermission(
                'goods_receive.delete',
            );

            /*
        |--------------------------------------------------------------------------
        | Normalisasi scope
        |--------------------------------------------------------------------------
        */
            $allowedScopes = [
                'ALL',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'OWN_DATA',
                'NONE',
            ];

            if (!in_array($viewScope, $allowedScopes, true)) {
                $viewScope = 'NONE';
            }

            /*
        |--------------------------------------------------------------------------
        | Identitas organisasi user
        |--------------------------------------------------------------------------
        */
            $departmentId = (int) (
                $user->department_id
                ?? 0
            );

            $cabangId = (int) (
                $user->cabang_id
                ?? 0
            );

            /*
        |--------------------------------------------------------------------------
        | Query Goods Receipt
        |--------------------------------------------------------------------------
        */
            $query = GoodsReceive::query()
                ->with([
                    /*
                |--------------------------------------------------------------------------
                | Department dan cabang berasal dari Purchase Order
                |--------------------------------------------------------------------------
                */
                    'purchaseOrder:id,nomor_po,cabang,id_department',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'purchaseOrder.departmentData:id,kode,nama',

                    'vendor:id,nama_vendor',

                    'creator:id,name',
                ]);

            /*
        |--------------------------------------------------------------------------
        | Filter visibility berdasarkan permission scope
        |--------------------------------------------------------------------------
        */
            if (!$canView || $viewScope === 'NONE') {
                /*
            |--------------------------------------------------------------------------
            | Jangan return kosong secara manual
            |--------------------------------------------------------------------------
            | Tetap gunakan query false agar response pagination konsisten.
            |--------------------------------------------------------------------------
            */
                $query->whereRaw('1 = 0');
            } elseif ($viewScope === 'OWN_DATA') {
                /*
            |--------------------------------------------------------------------------
            | Hanya GR yang dibuat oleh user login
            |--------------------------------------------------------------------------
            */
                $query->where(
                    'created_by',
                    $user->id,
                );
            } elseif ($viewScope === 'OWN_DEPARTMENT') {
                /*
            |--------------------------------------------------------------------------
            | Hanya GR dari PO department user login
            |--------------------------------------------------------------------------
            */
                if ($departmentId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas(
                        'purchaseOrder',
                        function ($poQuery) use ($departmentId) {
                            $poQuery->where(
                                'id_department',
                                $departmentId,
                            );
                        },
                    );
                }
            } elseif ($viewScope === 'OWN_CABANG') {
                /*
            |--------------------------------------------------------------------------
            | Hanya GR dari PO cabang user login
            |--------------------------------------------------------------------------
            */
                if ($cabangId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas(
                        'purchaseOrder',
                        function ($poQuery) use ($cabangId) {
                            $poQuery->where(
                                'cabang',
                                $cabangId,
                            );
                        },
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Scope ALL
        |--------------------------------------------------------------------------
        | Tidak perlu menambahkan filter visibility.
        |--------------------------------------------------------------------------
        */

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->search,
                );

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q
                            ->where(
                                'nomor_gr',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhereHas(
                                'purchaseOrder',
                                function ($po) use ($search) {
                                    $po->where(
                                        'nomor_po',
                                        'ILIKE',
                                        "%{$search}%",
                                    );
                                },
                            )
                            ->orWhereHas(
                                'vendor',
                                function ($vendor) use ($search) {
                                    $vendor->where(
                                        'nama_vendor',
                                        'ILIKE',
                                        "%{$search}%",
                                    );
                                },
                            );
                    });
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Status
        |--------------------------------------------------------------------------
        */
            $status = strtoupper(
                trim((string) $request->status),
            );

            if (
                $status !== ''
                && $status !== 'ALL'
                && $status !== 'SEMUA'
            ) {
                $query->whereRaw(
                    'UPPER(TRIM(status)) = ?',
                    [$status],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Tanggal
        |--------------------------------------------------------------------------
        */
            $startDate = $request->input('tanggal_mulai')
                ?? $request->input('start_date');

            $endDate = $request->input('tanggal_selesai')
                ?? $request->input('end_date');

            if (!empty($startDate)) {
                $query->whereDate(
                    'tanggal_gr',
                    '>=',
                    $startDate,
                );
            }

            if (!empty($endDate)) {
                $query->whereDate(
                    'tanggal_gr',
                    '<=',
                    $endDate,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
            $perPage = (int) (
                $request->per_page
                ?? 10
            );

            $perPage = $perPage > 0
                ? $perPage
                : 10;

            $goodsReceives = $query
                ->orderByDesc('id')
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Transform Response
        |--------------------------------------------------------------------------
        */
            $goodsReceives
                ->getCollection()
                ->transform(function ($gr) use (
                    $user,
                    $canUpdate,
                    $canDelete,
                ) {
                    /*
                |--------------------------------------------------------------------------
                | Hak edit/delete per row
                |--------------------------------------------------------------------------
                | Permission menentukan tombol tersedia.
                | Aturan status tetap dapat ditambahkan di sini.
                |--------------------------------------------------------------------------
                */
                    $status = strtoupper(
                        trim((string) $gr->status),
                    );

                    $canUpdateRow = $canUpdate
                        && $status === 'DRAFT';

                    $canDeleteRow = $canDelete
                        && $status === 'DRAFT';

                    return [
                        'id' => $gr->id,

                        'public_id' => Crypt::encryptString(
                            (string) $gr->id,
                        ),

                        'nomor_gr' => $gr->nomor_gr,

                        'tanggal_gr' => $gr->tanggal_gr,

                        'purchase_order_id'
                        => $gr->purchase_order_id,

                        'nomor_po'
                        => $gr->purchaseOrder?->nomor_po
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Informasi cabang PO
                    |--------------------------------------------------------------------------
                    */
                        'cabang_id'
                        => $gr->purchaseOrder?->cabang,

                        'cabang'
                        => $gr->purchaseOrder
                            ?->cabangData
                            ?->nama_cabang
                            ?? '-',

                        'inisial_cabang'
                        => $gr->purchaseOrder
                            ?->cabangData
                            ?->inisial_cabang
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Informasi department PO
                    |--------------------------------------------------------------------------
                    */
                        'department_id'
                        => $gr->purchaseOrder
                            ?->id_department,

                        'department'
                        => $gr->purchaseOrder
                            ?->departmentData
                            ?->kode
                            ?? '-',

                        'department_name'
                        => $gr->purchaseOrder
                            ?->departmentData
                            ?->nama
                            ?? '-',

                        'vendor_id' => $gr->vendor_id,

                        'vendor'
                        => $gr->vendor?->nama_vendor
                            ?? '-',

                        'status' => $gr->status,

                        'total_qty' => (float) (
                            $gr->total_qty
                            ?? 0
                        ),

                        'total_nilai' => (float) (
                            $gr->total_nilai
                            ?? 0
                        ),

                        'created_by_id'
                        => $gr->created_by,

                        'created_by'
                        => $gr->creator?->name
                            ?? $gr->created_by,

                        'created_at'
                        => $gr->created_at
                            ?->format('Y-m-d H:i:s'),

                        /*
                    |--------------------------------------------------------------------------
                    | Kemampuan per row
                    |--------------------------------------------------------------------------
                    */
                        'can_update'
                        => $canUpdateRow,

                        'can_delete'
                        => $canDeleteRow,

                        'is_owner'
                        => (int) $gr->created_by
                            === (int) $user->id,
                    ];
                });

            return response()->json([
                'success' => true,

                'message'
                => 'Data Goods Receipt berhasil dimuat.',

                'data'
                => $goodsReceives->items(),

                /*
            |--------------------------------------------------------------------------
            | Pertahankan pagination lama
            |--------------------------------------------------------------------------
            */
                'pagination' => [
                    'current_page'
                    => $goodsReceives->currentPage(),

                    'last_page'
                    => $goodsReceives->lastPage(),

                    'per_page'
                    => $goodsReceives->perPage(),

                    'total'
                    => $goodsReceives->total(),
                ],

                /*
            |--------------------------------------------------------------------------
            | Tambahkan meta juga agar konsisten dengan PR/PO
            |--------------------------------------------------------------------------
            | Ini tidak merusak frontend lama karena pagination tetap tersedia.
            |--------------------------------------------------------------------------
            */
                'meta' => [
                    'current_page'
                    => $goodsReceives->currentPage(),

                    'last_page'
                    => $goodsReceives->lastPage(),

                    'per_page'
                    => $goodsReceives->perPage(),

                    'total'
                    => $goodsReceives->total(),
                ],

                /*
            |--------------------------------------------------------------------------
            | Global abilities untuk frontend
            |--------------------------------------------------------------------------
            */
                'abilities' => [
                    'can_view' => $canView,

                    'view_scope' => $viewScope,

                    'can_create' => $canCreate,

                    'can_update' => $canUpdate,

                    'can_delete' => $canDelete,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Receipt] Index error',
                [
                    'user_id'
                    => $request->user()?->id,

                    'message'
                    => $e->getMessage(),

                    'file'
                    => $e->getFile(),

                    'line'
                    => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,

                'message'
                => 'Gagal memuat data Goods Receipt.',

                'data' => [],

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('goods_receive.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Goods Receipt.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'purchase_order_public_id' => ['required', 'string'],
                'tanggal_gr' => ['required', 'date'],
                'nomor_surat_jalan' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.purchase_order_item_public_id' => ['required', 'string'],
                'items.*.qty_receive' => ['required', 'numeric', 'gt:0'],
                'items.*.notes' => ['nullable', 'string'],
                'attachments' => ['nullable', 'array'],
                'attachments.*' => [
                    'file',
                    'mimes:pdf,jpg,jpeg,png,webp',
                    'max:3072',
                ],
            ]);

            $poId = Crypt::decryptString($validated['purchase_order_public_id']);

            $po = PurchaseOrder::with(['items', 'vendor'])
                ->findOrFail($poId);

            $items = collect($validated['items'])->map(function ($item) {
                return [
                    'purchase_order_item_id' => Crypt::decryptString($item['purchase_order_item_public_id']),
                    'qty_receive' => (float) $item['qty_receive'],
                    'notes' => $item['notes'] ?? null,
                ];
            })->values()->toArray();

            $nomor_gr = $this->generateDraftGRNumber();

            $payload = [
                'purchase_order_public_id' => $validated['purchase_order_public_id'],
                'purchase_order_id' => $poId,
                'nomor_gr' => $nomor_gr,
                'tanggal_gr' => $validated['tanggal_gr'],
                'nomor_surat_jalan' => $validated['nomor_surat_jalan'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->name,
                'items' => $items,
            ];

            $gr = $this->goodsReceiveService->createDraftFromPurchaseOrder(
                $po,
                $payload,
                $request->user()->id
            );

            if ($request->hasFile('attachments')) {
                $basePath = "syopv4/uploads/goods_receipt/{$gr->id}";

                foreach ($request->file('attachments') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();

                    $safeOriginalName = pathinfo($originalName, PATHINFO_FILENAME);
                    $safeOriginalName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $safeOriginalName);

                    $fileName = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                    $filePath = $file->storeAs($basePath, $fileName, 'public');

                    GoodsReceiveAttachment::create([
                        'goods_receive_id' => $gr->id,
                        'document_type' => null,
                        'file_name' => $fileName,
                        'file_original_name' => $originalName,
                        'file_path' => $filePath,
                        'file_mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->id ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil dibuat sebagai draft.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => $gr->encrypted_id,
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'tanggal_gr' => $gr->tanggal_gr,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function edit($publicId, Request $request)
    {
        return $this->show($publicId, $request);
    }

    public function update(Request $request, $publicId)
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('goods_receive.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Goods Receipt.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            /**
             * Tambahan khusus attachment:
             * Karena upload file biasanya pakai FormData,
             * existing_attachment_ids bisa masuk sebagai JSON string.
             */
            if ($request->has('deleted_attachment_ids') && is_string($request->deleted_attachment_ids)) {
                $decodedDeletedAttachmentIds = json_decode($request->deleted_attachment_ids, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([
                        'deleted_attachment_ids' => $decodedDeletedAttachmentIds,
                    ]);
                }
            }

            $validated = $request->validate([
                'tanggal_gr' => ['required', 'date'],
                'nomor_surat_jalan' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.goods_receive_item_public_id' => ['required', 'string'],
                'items.*.purchase_order_item_public_id' => ['required', 'string'],
                'items.*.qty_receive' => ['required', 'numeric', 'gt:0'],
                'items.*.notes' => ['nullable', 'string'],

                /**
                 * Tambahan validasi attachment.
                 */
                'deleted_attachment_ids' => ['nullable', 'array'],
                'deleted_attachment_ids.*' => ['string'],

                'attachments' => ['nullable', 'array'],
                'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx'],
                'remove_all_attachments' => ['nullable', 'boolean'],
            ]);

            $grId = Crypt::decrypt(urldecode($publicId));

            $gr = GoodsReceive::with(['items'])
                ->lockForUpdate()
                ->findOrFail($grId);

            if (strtoupper((string) $gr->status) !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receipt hanya dapat diubah jika status masih DRAFT.',
                ], 422);
            }

            $gr->update([
                'tanggal_gr' => $validated['tanggal_gr'],
                'nomor_surat_jalan' => $validated['nomor_surat_jalan'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $itemPayload) {
                $grItemId = Crypt::decryptString($itemPayload['goods_receive_item_public_id']);
                $poItemId = Crypt::decryptString($itemPayload['purchase_order_item_public_id']);

                $grItem = GoodsReceiveItem::where('goods_receive_id', $gr->id)
                    ->where('id', $grItemId)
                    ->where('purchase_order_item_id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $poItem = PurchaseOrderItem::where('id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyReceive = (float) $itemPayload['qty_receive'];
                $qtyOrdered = (float) ($grItem->qty_ordered ?? $poItem->qty ?? 0);
                $qtyReceivedBefore = (float) ($grItem->qty_received_before ?? 0);

                $maxReceive = max($qtyOrdered - $qtyReceivedBefore, 0);

                if ($qtyReceive > $maxReceive) {
                    throw new \Exception("Qty receipt item {$grItem->nama_item} melebihi qty yang tersedia.");
                }

                $qtyReceivedAfter = $qtyReceivedBefore + $qtyReceive;
                $qtyOutstanding = max($qtyOrdered - $qtyReceivedAfter, 0);

                $grItem->update([
                    'qty_receive' => $qtyReceive,
                    'qty_received_after' => $qtyReceivedAfter,
                    'qty_outstanding' => $qtyOutstanding,
                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }

            /**
             * ============================================================
             * TAMBAHAN: HAPUS ATTACHMENT LAMA YANG DIHAPUS DI FE
             * ============================================================
             *
             * existing_attachment_ids berisi ID attachment lama yang masih dipertahankan.
             * Attachment lama yang tidak ada di existing_attachment_ids akan dihapus.
             */
            $deletedAttachmentIds = collect($validated['deleted_attachment_ids'] ?? [])
                ->filter()
                ->map(function ($encryptedAttachmentId) {
                    try {
                        return Crypt::decrypt($encryptedAttachmentId);
                    } catch (\Throwable $e) {
                        return null;
                    }
                })
                ->filter()
                ->values()
                ->toArray();

            $removeAllAttachments = filter_var(
                $request->input('remove_all_attachments', false),
                FILTER_VALIDATE_BOOLEAN
            );

            if ($removeAllAttachments) {
                $attachmentsToDelete = GoodsReceiveAttachment::where('goods_receive_id', $gr->id)
                    ->get();
            } elseif (count($deletedAttachmentIds) > 0) {
                $attachmentsToDelete = GoodsReceiveAttachment::where('goods_receive_id', $gr->id)
                    ->whereIn('id', $deletedAttachmentIds)
                    ->get();
            } else {
                $attachmentsToDelete = collect();
            }

            foreach ($attachmentsToDelete as $attachment) {
                if (!empty($attachment->file_path) && Storage::disk('public')->exists($attachment->file_path)) {
                    Storage::disk('public')->delete($attachment->file_path);
                }

                $attachment->delete();
            }

            /**
             * ============================================================
             * TAMBAHAN: UPLOAD ATTACHMENT BARU
             * ============================================================
             *
             * Disamakan dengan logic store.
             */
            if ($request->hasFile('attachments')) {
                $basePath = "syopv4/uploads/goods_receipt/{$gr->id}";

                foreach ($request->file('attachments') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();

                    $safeOriginalName = pathinfo($originalName, PATHINFO_FILENAME);
                    $safeOriginalName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $safeOriginalName);

                    $fileName = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                    $filePath = $file->storeAs($basePath, $fileName, 'public');

                    GoodsReceiveAttachment::create([
                        'goods_receive_id' => $gr->id,
                        'document_type' => null,
                        'file_name' => $fileName,
                        'file_original_name' => $originalName,
                        'file_path' => $filePath,
                        'file_mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->id ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil diperbarui.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => Crypt::encryptString((string) $gr->id),
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'tanggal_gr' => $gr->tanggal_gr,
                    'nomor_surat_jalan' => $gr->nomor_surat_jalan,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Goods Receive] Update error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            try {

                $gr = GoodsReceive::with([
                    'purchaseOrder:id,nomor_po,tanggal_po,cabang,id_department,vendor_id,status_receive',
                    'purchaseOrder.vendor:id,nama_vendor,status_pkp',
                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',
                    'purchaseOrder.departmentData:id,kode,nama',
                    'items.unitData:id,kode,nama',
                    'creator:id,name',
                    'poster:id,name',
                    'attachments:id,goods_receive_id,file_name,file_original_name,file_path,file_mime_type,file_size,created_at',
                ])->findOrFail($id);

                $items = $gr->getRelation('items');

                return response()->json([
                    'success' => true,
                    'message' => 'Detail Goods Receipt berhasil dimuat.',
                    'data' => [
                        'id' => $gr->id,
                        'public_id' => $gr->encrypted_id,

                        'nomor_gr' => $gr->nomor_gr,
                        'tanggal_gr' => $gr->tanggal_gr,
                        'nomor_surat_jalan' => $gr->nomor_surat_jalan,

                        'status' => $gr->status,
                        'notes' => $gr->notes,

                        'purchase_order_id' => $gr->purchase_order_id,
                        'nomor_po' => $gr->purchaseOrder->nomor_po ?? '-',
                        'tanggal_po' => $gr->purchaseOrder->tanggal_po ?? '-',
                        'status_receive' => $gr->purchaseOrder->status_receive ?? '-',

                        'vendor_id' => $gr->purchaseOrder->vendor_id ?? null,
                        'vendor' => $gr->purchaseOrder->vendor->nama_vendor ?? '-',
                        'status_pkp' => $gr->purchaseOrder->vendor->status_pkp ?? 'NON_PKP',

                        'cabang_id' => $gr->purchaseOrder->cabang ?? null,
                        'cabang' => $gr->purchaseOrder->cabangData->nama_cabang
                            ?? $gr->purchaseOrder->cabangData->inisial_cabang
                            ?? '-',

                        'department_id' => $gr->purchaseOrder->id_department ?? null,
                        'department' => $gr->purchaseOrder->departmentData->nama
                            ?? $gr->purchaseOrder->departmentData->kode
                            ?? '-',

                        'created_by_id' => $gr->created_by,
                        'created_by' => $gr->creator->name ?? '-',
                        'created_at' => $gr->created_at?->format('Y-m-d H:i:s'),

                        'posted_at' => $gr->posted_at,
                        'posted_by_id' => $gr->posted_by,
                        'posted_by' => $gr->poster->name ?? '-',

                        'attachments' => $gr->attachments->map(function ($attachment) {
                            return [
                                'id' => $attachment->id,
                                'file_name' => $attachment->file_name,
                                'file_original_name' => $attachment->file_original_name,
                                'file_path' => $attachment->file_path,
                                'file_url' => asset('storage/' . $attachment->file_path),
                                'file_mime_type' => $attachment->file_mime_type,
                                'file_size' => (int) ($attachment->file_size ?? 0),
                                'created_at' => $attachment->created_at?->format('Y-m-d H:i:s'),
                            ];
                        })->values(),

                        'items' => $items->map(function ($item) use ($gr) {
                            $qtyOrdered = (float) ($item->qty_ordered ?? 0);
                            $qtyReceive = (float) ($item->qty_receive ?? 0);

                            $qtyReceivedBefore = (float) DB::table('goods_receive_items as gri')
                                ->join('goods_receives as gr', 'gr.id', '=', 'gri.goods_receive_id')
                                ->where('gri.purchase_order_item_id', $item->purchase_order_item_id)
                                ->whereIn('gr.status', ['DRAFT', 'POSTED'])
                                ->whereNull('gr.deleted_at')
                                ->where('gr.id', '<', $gr->id)
                                ->sum('gri.qty_receive');

                            $qtyReceivedAfter = $qtyReceivedBefore + $qtyReceive;
                            $qtyOutstanding = max($qtyOrdered - $qtyReceivedAfter, 0);

                            return [
                                'id' => $item->id,
                                'public_id' => Crypt::encryptString((string) $item->id),

                                'purchase_order_item_id' => $item->purchase_order_item_id,
                                'purchase_order_item_public_id' => Crypt::encryptString((string) $item->purchase_order_item_id),
                                'purchase_request_item_id' => $item->purchase_request_item_id,

                                'nama_item' => $item->nama_item,
                                'unit_id' => $item->unit,
                                'unit' => $item->unitData->nama ?? $item->unitData->kode ?? '-',

                                'qty_ordered' => $qtyOrdered,
                                'qty_received_before' => $qtyReceivedBefore,
                                'qty_receive' => $qtyReceive,
                                'qty_received_after' => $qtyReceivedAfter,
                                'qty_outstanding' => $qtyOutstanding,

                                'notes' => $item->notes,
                            ];
                        })->values(),
                    ],
                ], 200);
            } catch (\Throwable $e) {
                Log::error('[Goods Receipt] Show error', [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal memuat detail Goods Receipt.',
                    'data' => null,
                    'debug' => app()->environment('local') ? $e->getMessage() : null,
                ], 500);
            }
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function post(Request $request, $publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $gr = GoodsReceive::with(['items', 'purchaseOrder.items', 'purchaseOrder.departmentData'])
                ->findOrFail($id);

            $this->goodsReceivePostingService->post($gr, $request->user());

            $gr->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil diposting.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => $gr->encrypted_id,
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'posted_at' => $gr->posted_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Post error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal posting Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy($publicId, Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('goods_receive.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Goods Receipt.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString(urldecode($publicId));

            $gr = GoodsReceive::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $gr->status) !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receipt hanya dapat dihapus jika status masih DRAFT.',
                ], 422);
            }

            DB::table('goods_receive_items')
                ->where('goods_receive_id', $gr->id)
                ->delete();

            $gr->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Goods Receipt] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function generateDraftGRNumber(): string
    {
        $year = now()->format('Y');

        $lastGR = GoodsReceive::withTrashed()
            ->whereYear('created_at', $year)
            ->where('nomor_gr', 'ILIKE', "DRAFT/GR/{$year}/%")
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;

        if ($lastGR) {
            $lastNumber = (int) substr($lastGR->nomor_gr, -4);
            $nextNumber = $lastNumber + 1;
        }

        return 'DRAFT/GR/' . $year . '/' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
