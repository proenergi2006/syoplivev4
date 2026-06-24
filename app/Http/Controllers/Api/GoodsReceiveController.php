<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceive;
use App\Models\GoodsReceiveAttachment;
use App\Models\GoodsReceiveItem;
use App\Models\GoodsReturn;
use App\Models\GoodsReturnItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\NonTrade\GoodsReceive\GoodsReceivePostingService;
use App\Services\NonTrade\GoodsReceive\GoodsReceiveService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
                $user->departemen_id
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

                    'sourceGoodsReturn:id,nomor_return,tanggal_return,status,purchase_order_id',

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
                            )
                            ->orWhereHas(
                                'sourceGoodsReturn',
                                function ($goodsReturn) use ($search) {
                                    $goodsReturn->where(
                                        'nomor_return',
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
        | Filter jenis Goods Receipt
        |--------------------------------------------------------------------------
        | Tidak memengaruhi request lama jika receipt_type tidak dikirim.
        |--------------------------------------------------------------------------
        */
            $receiptType = strtoupper(
                trim(
                    (string) $request->input(
                        'receipt_type',
                        'ALL',
                    ),
                ),
            );

            if ($receiptType === 'NORMAL') {
                $query->whereNull(
                    'source_goods_return_id',
                );
            } elseif (
                in_array(
                    $receiptType,
                    [
                        'REPLACEMENT',
                        'PENGGANTI',
                    ],
                    true,
                )
            ) {
                $query->whereNotNull(
                    'source_goods_return_id',
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

                        'is_replacement'
                        => $gr->source_goods_return_id !== null,

                        'source_goods_return_id'
                        => $gr->source_goods_return_id,

                        'goods_return_public_id'
                        => $gr->source_goods_return_id
                            ? Crypt::encryptString(
                                (string) $gr->source_goods_return_id,
                            )
                            : null,

                        'nomor_return'
                        => $gr->sourceGoodsReturn?->nomor_return,

                        'tanggal_return'
                        => $gr->sourceGoodsReturn?->tanggal_return,

                        'goods_return_status'
                        => $gr->sourceGoodsReturn?->status,

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

        if (
            !$user
            || !$user->hasPermission('goods_receive.create')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Goods Receipt.',
            ], 403);
        }

        $storedFilePaths = [];

        try {
            /*
        |--------------------------------------------------------------------------
        | Normalisasi items jika dikirim melalui FormData sebagai JSON string
        |--------------------------------------------------------------------------
        */
            if (
                $request->has('items')
                && is_string($request->input('items'))
            ) {
                $decodedItems = json_decode(
                    (string) $request->input('items'),
                    true,
                );

                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([
                        'items' => $decodedItems,
                    ]);
                }
            }

            $isReplacement = $request->filled(
                'goods_return_public_id',
            );

            /*
        |--------------------------------------------------------------------------
        | Validasi request
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'purchase_order_public_id' => [
                    'required',
                    'string',
                ],

                /*
            |--------------------------------------------------------------------------
            | Nullable untuk GR normal, wajib diisi untuk GR replacement
            |--------------------------------------------------------------------------
            */
                'goods_return_public_id' => [
                    'nullable',
                    'string',
                ],

                'tanggal_gr' => [
                    'required',
                    'date',
                ],

                'nomor_surat_jalan' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'notes' => [
                    'nullable',
                    'string',
                ],

                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'items.*.purchase_order_item_public_id' => [
                    'required',
                    'string',
                    'distinct',
                ],

                /*
            |--------------------------------------------------------------------------
            | Wajib hanya untuk replacement
            |--------------------------------------------------------------------------
            */
                'items.*.goods_return_item_public_id' => [
                    $isReplacement
                        ? 'required'
                        : 'nullable',
                    'string',
                    'distinct',
                ],

                'items.*.qty_receive' => [
                    'required',
                    'numeric',
                    'gt:0',
                ],

                'items.*.notes' => [
                    'nullable',
                    'string',
                ],

                'attachments' => [
                    'nullable',
                    'array',
                ],

                'attachments.*' => [
                    'file',
                    'mimes:pdf,jpg,jpeg,png,webp',
                    'max:3072',
                ],
            ], [
                'purchase_order_public_id.required'
                => 'Purchase Order wajib dipilih.',

                'goods_return_public_id.required'
                => 'Goods Return sumber wajib dipilih.',

                'items.required'
                => 'Item Goods Receipt wajib diisi.',

                'items.min'
                => 'Minimal terdapat satu item Goods Receipt.',

                'items.*.goods_return_item_public_id.required'
                => 'Item Goods Return sumber wajib dipilih.',

                'items.*.qty_receive.gt'
                => 'Qty receive harus lebih besar dari nol.',
            ]);

            /** @var \App\Models\GoodsReceive $gr */
            $gr = DB::transaction(function () use (
                $request,
                $user,
                $validated,
                $isReplacement,
                &$storedFilePaths,
            ) {
                $goodsReturn = null;

                /*
            |--------------------------------------------------------------------------
            | Lock Goods Return terlebih dahulu
            |--------------------------------------------------------------------------
            | Urutan ini dibuat konsisten dengan proses cancel Goods Return:
            | Goods Return -> PO -> PO Item.
            |--------------------------------------------------------------------------
            */
                if ($isReplacement) {
                    $goodsReturnId = Crypt::decryptString(
                        urldecode(
                            $validated['goods_return_public_id'],
                        ),
                    );

                    /** @var GoodsReturn $goodsReturn */
                    $goodsReturn = GoodsReturn::query()
                        ->whereKey($goodsReturnId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        strtoupper(
                            trim((string) $goodsReturn->status),
                        ) !== GoodsReturn::STATUS_POSTED
                    ) {
                        throw ValidationException::withMessages([
                            'goods_return_public_id' => [
                                'Goods Return sumber harus berstatus POSTED.',
                            ],
                        ]);
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Decrypt dan lock Purchase Order
            |--------------------------------------------------------------------------
            */
                $poId = Crypt::decryptString(
                    urldecode(
                        $validated['purchase_order_public_id'],
                    ),
                );

                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()
                    ->with([
                        'vendor',
                    ])
                    ->whereKey($poId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
            |--------------------------------------------------------------------------
            | PO harus final approved
            |--------------------------------------------------------------------------
            */
                if (
                    strtoupper(
                        trim((string) $po->status),
                    ) !== 'APPROVED'
                ) {
                    throw ValidationException::withMessages([
                        'purchase_order_public_id' => [
                            'Purchase Order harus berstatus APPROVED.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Department user harus sama dengan department PO
            |--------------------------------------------------------------------------
            */
                $userDepartmentId = (int) (
                    $user->departemen_id
                    ?? 0
                );

                if ($userDepartmentId <= 0) {
                    throw ValidationException::withMessages([
                        'department_id' => [
                            'Department akun login tidak ditemukan.',
                        ],
                    ]);
                }

                if (
                    (int) $po->id_department
                    !== $userDepartmentId
                ) {
                    throw ValidationException::withMessages([
                        'purchase_order_public_id' => [
                            'Purchase Order tidak berasal dari department Anda.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Validasi hubungan Goods Return dan PO
            |--------------------------------------------------------------------------
            */
                if ($isReplacement) {
                    if (
                        (int) $goodsReturn->purchase_order_id
                        !== (int) $po->id
                    ) {
                        throw ValidationException::withMessages([
                            'goods_return_public_id' => [
                                'Purchase Order tidak sesuai dengan Goods Return sumber.',
                            ],
                        ]);
                    }

                    if (
                        (int) $goodsReturn->id_department
                        !== $userDepartmentId
                    ) {
                        throw ValidationException::withMessages([
                            'goods_return_public_id' => [
                                'Goods Return tidak berasal dari department Anda.',
                            ],
                        ]);
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Decrypt ID item PO terlebih dahulu
            |--------------------------------------------------------------------------
            */
                $decryptedItems = collect(
                    $validated['items'],
                )->map(function ($item, $index) use (
                    $isReplacement,
                ) {
                    $purchaseOrderItemId = Crypt::decryptString(
                        urldecode(
                            $item['purchase_order_item_public_id'],
                        ),
                    );

                    $goodsReturnItemId = null;

                    if ($isReplacement) {
                        $goodsReturnItemId = Crypt::decryptString(
                            urldecode(
                                $item['goods_return_item_public_id'],
                            ),
                        );
                    }

                    return [
                        'index'
                        => $index,

                        'purchase_order_item_id'
                        => (int) $purchaseOrderItemId,

                        'goods_return_item_id'
                        => $goodsReturnItemId !== null
                            ? (int) $goodsReturnItemId
                            : null,

                        'qty_receive'
                        => (float) $item['qty_receive'],

                        'notes'
                        => $item['notes'] ?? null,
                    ];
                })->values();

                /*
            |--------------------------------------------------------------------------
            | Lock seluruh PO item yang dikirim
            |--------------------------------------------------------------------------
            */
                $purchaseOrderItemIds = $decryptedItems
                    ->pluck('purchase_order_item_id')
                    ->unique()
                    ->values()
                    ->all();

                $purchaseOrderItems = PurchaseOrderItem::query()
                    ->where(
                        'purchase_order_id',
                        $po->id,
                    )
                    ->whereIn(
                        'id',
                        $purchaseOrderItemIds,
                    )
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (
                    $purchaseOrderItems->count()
                    !== count($purchaseOrderItemIds)
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Terdapat item yang tidak termasuk dalam Purchase Order.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Validasi qty per item
            |--------------------------------------------------------------------------
            */
                $items = $decryptedItems
                    ->map(function ($item) use (
                        $po,
                        $goodsReturn,
                        $isReplacement,
                        $purchaseOrderItems,
                    ) {
                        $index = $item['index'];

                        /** @var PurchaseOrderItem|null $poItem */
                        $poItem = $purchaseOrderItems->get(
                            $item['purchase_order_item_id'],
                        );

                        if (!$poItem) {
                            throw ValidationException::withMessages([
                                "items.{$index}" => [
                                    'Item Purchase Order tidak ditemukan.',
                                ],
                            ]);
                        }

                        $qtyReceive = (float) (
                            $item['qty_receive']
                            ?? 0
                        );

                        if ($qtyReceive <= 0) {
                            throw ValidationException::withMessages([
                                "items.{$index}.qty_receive" => [
                                    'Qty receive harus lebih besar dari nol.',
                                ],
                            ]);
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Alur GR replacement
                    |--------------------------------------------------------------------------
                    */
                        if ($isReplacement) {
                            /** @var GoodsReturnItem $goodsReturnItem */
                            $goodsReturnItem = GoodsReturnItem::query()
                                ->where(
                                    'goods_return_id',
                                    $goodsReturn->id,
                                )
                                ->whereKey(
                                    $item['goods_return_item_id'],
                                )
                                ->where(
                                    'purchase_order_item_id',
                                    $poItem->id,
                                )
                                ->lockForUpdate()
                                ->firstOrFail();

                            /*
                        |--------------------------------------------------------------------------
                        | Total replacement yang sudah DRAFT atau POSTED
                        |--------------------------------------------------------------------------
                        */
                            $qtyReplacementUsed = (float) DB::table(
                                'goods_receive_items as gri',
                            )
                                ->join(
                                    'goods_receives as gr',
                                    'gr.id',
                                    '=',
                                    'gri.goods_receive_id',
                                )
                                ->where(
                                    'gr.source_goods_return_id',
                                    $goodsReturn->id,
                                )
                                ->where(
                                    'gri.purchase_order_item_id',
                                    $poItem->id,
                                )
                                ->whereRaw(
                                    'UPPER(TRIM(gr.status)) IN (?, ?)',
                                    [
                                        'DRAFT',
                                        'POSTED',
                                    ],
                                )
                                ->whereNull(
                                    'gr.deleted_at',
                                )
                                ->sum(
                                    'gri.qty_receive',
                                );

                            $qtyReturn = (float) (
                                $goodsReturnItem->qty_return
                                ?? 0
                            );

                            $qtyReplacementOutstanding = max(
                                $qtyReturn
                                    - $qtyReplacementUsed,
                                0,
                            );

                            if (
                                $qtyReceive
                                > (
                                    $qtyReplacementOutstanding
                                    + 0.0001
                                )
                            ) {
                                throw ValidationException::withMessages([
                                    "items.{$index}.qty_receive" => [
                                        'Qty replacement item '
                                            . ($poItem->nama_item ?? '-')
                                            . ' melebihi outstanding replacement. '
                                            . 'Maksimal '
                                            . $qtyReplacementOutstanding
                                            . '.',
                                    ],
                                ]);
                            }
                        } else {
                            /*
                        |--------------------------------------------------------------------------
                        | Alur GR normal
                        |--------------------------------------------------------------------------
                        | Hitung hanya GR normal. GR replacement tidak boleh
                        | menambah/mengurangi outstanding penerimaan normal.
                        |--------------------------------------------------------------------------
                        */
                            $qtyNormalUsed = (float) DB::table(
                                'goods_receive_items as gri',
                            )
                                ->join(
                                    'goods_receives as gr',
                                    'gr.id',
                                    '=',
                                    'gri.goods_receive_id',
                                )
                                ->where(
                                    'gri.purchase_order_item_id',
                                    $poItem->id,
                                )
                                ->whereNull(
                                    'gr.source_goods_return_id',
                                )
                                ->whereRaw(
                                    'UPPER(TRIM(gr.status)) IN (?, ?)',
                                    [
                                        'DRAFT',
                                        'POSTED',
                                    ],
                                )
                                ->whereNull(
                                    'gr.deleted_at',
                                )
                                ->sum(
                                    'gri.qty_receive',
                                );

                            $qtyOrdered = (float) (
                                $poItem->qty
                                ?? 0
                            );

                            $qtyNormalOutstanding = max(
                                $qtyOrdered
                                    - $qtyNormalUsed,
                                0,
                            );

                            if (
                                $qtyReceive
                                > (
                                    $qtyNormalOutstanding
                                    + 0.0001
                                )
                            ) {
                                throw ValidationException::withMessages([
                                    "items.{$index}.qty_receive" => [
                                        'Qty receive item '
                                            . ($poItem->nama_item ?? '-')
                                            . ' melebihi outstanding penerimaan normal. '
                                            . 'Maksimal '
                                            . $qtyNormalOutstanding
                                            . '.',
                                    ],
                                ]);
                            }
                        }

                        return [
                            'purchase_order_item_id'
                            => $poItem->id,

                            'qty_receive'
                            => $qtyReceive,

                            'notes'
                            => $item['notes'],
                        ];
                    })
                    ->values()
                    ->toArray();

                /*
            |--------------------------------------------------------------------------
            | Payload create draft
            |--------------------------------------------------------------------------
            */
                $nomorGr = $this->generateDraftGRNumber();

                $payload = [
                    'purchase_order_public_id'
                    => $validated['purchase_order_public_id'],

                    'purchase_order_id'
                    => $po->id,

                    /*
                |--------------------------------------------------------------------------
                | NULL untuk normal, ID return untuk replacement
                |--------------------------------------------------------------------------
                */
                    'source_goods_return_id'
                    => $isReplacement
                        ? $goodsReturn->id
                        : null,

                    'nomor_gr'
                    => $nomorGr,

                    'tanggal_gr'
                    => $validated['tanggal_gr'],

                    'cabang'
                    => $po->cabang,

                    'id_department'
                    => $po->id_department,

                    'nomor_surat_jalan'
                    => $validated['nomor_surat_jalan'] ?? null,

                    'notes'
                    => $validated['notes'] ?? null,

                    /*
                |--------------------------------------------------------------------------
                | Dipertahankan agar kompatibel dengan service existing
                |--------------------------------------------------------------------------
                */
                    'created_by'
                    => $user->name,

                    'items'
                    => $items,
                ];

                $gr = $this
                    ->goodsReceiveService
                    ->createDraftFromPurchaseOrder(
                        $po,
                        $payload,
                        $user->id,
                    );

                /*
            |--------------------------------------------------------------------------
            | Pastikan source Goods Return tersimpan
            |--------------------------------------------------------------------------
            | Bagian ini menjadi pengaman jika service existing belum memasukkan
            | source_goods_return_id saat create header.
            |--------------------------------------------------------------------------
            */
                if ($isReplacement) {
                    if (
                        (int) $gr->source_goods_return_id
                        !== (int) $goodsReturn->id
                    ) {
                        $gr->source_goods_return_id
                            = $goodsReturn->id;

                        $gr->save();
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Attachment
            |--------------------------------------------------------------------------
            */
                if ($request->hasFile('attachments')) {
                    $basePath =
                        "syopv4/uploads/goods_receipt/{$gr->id}";

                    foreach (
                        $request->file('attachments')
                        as $file
                    ) {
                        $originalName = $file
                            ->getClientOriginalName();

                        $extension = strtolower(
                            $file->getClientOriginalExtension(),
                        );

                        $safeOriginalName = pathinfo(
                            $originalName,
                            PATHINFO_FILENAME,
                        );

                        $safeOriginalName = preg_replace(
                            '/[^A-Za-z0-9_\-]/',
                            '_',
                            $safeOriginalName,
                        );

                        $fileName = now()->format(
                            'YmdHis',
                        )
                            . '_'
                            . uniqid()
                            . '_'
                            . $safeOriginalName
                            . '.'
                            . $extension;

                        $filePath = $file->storeAs(
                            $basePath,
                            $fileName,
                            'public',
                        );

                        $storedFilePaths[] = $filePath;

                        GoodsReceiveAttachment::query()
                            ->create([
                                'goods_receive_id'
                                => $gr->id,

                                'document_type'
                                => null,

                                'file_name'
                                => $fileName,

                                'file_original_name'
                                => $originalName,

                                'file_path'
                                => $filePath,

                                'file_mime_type'
                                => $file->getClientMimeType(),

                                'file_size'
                                => $file->getSize(),

                                'uploaded_by'
                                => $user->id,
                            ]);
                    }
                }

                return $gr;
            });

            $gr->refresh();

            return response()->json([
                'success' => true,

                'message' => $isReplacement
                    ? 'Goods Receipt replacement berhasil dibuat sebagai draft.'
                    : 'Goods Receipt berhasil dibuat sebagai draft.',

                'data' => [
                    'id'
                    => $gr->id,

                    'public_id'
                    => $gr->encrypted_id,

                    'nomor_gr'
                    => $gr->nomor_gr,

                    'status'
                    => $gr->status,

                    'tanggal_gr'
                    => $gr->tanggal_gr,

                    'is_replacement'
                    => $gr->source_goods_return_id !== null,

                    'source_goods_return_id'
                    => $gr->source_goods_return_id,

                    'goods_return_public_id'
                    => $gr->source_goods_return_id
                        ? Crypt::encryptString(
                            (string) $gr->source_goods_return_id,
                        )
                        : null,
                ],
            ], 201);
        } catch (ValidationException $e) {
            foreach ($storedFilePaths as $filePath) {
                Storage::disk('public')->delete(
                    $filePath,
                );
            }

            return response()->json([
                'success' => false,

                'message' => collect(
                    $e->errors(),
                )
                    ->flatten()
                    ->first()
                    ?? 'Data Goods Receipt tidak valid.',

                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            foreach ($storedFilePaths as $filePath) {
                Storage::disk('public')->delete(
                    $filePath,
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Purchase Order, Goods Return, atau item tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            foreach ($storedFilePaths as $filePath) {
                Storage::disk('public')->delete(
                    $filePath,
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'Purchase Order, Goods Return, atau item tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            foreach ($storedFilePaths as $filePath) {
                Storage::disk('public')->delete(
                    $filePath,
                );
            }

            Log::error(
                '[Goods Receipt] Store error',
                [
                    'user_id'
                    => $request->user()?->id,

                    'is_replacement'
                    => $request->filled(
                        'goods_return_public_id',
                    ),

                    'message'
                    => $e->getMessage(),

                    'file'
                    => $e->getFile(),

                    'line'
                    => $e->getLine(),

                    'request'
                    => $request->except([
                        'attachments',
                    ]),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Goods Receipt.',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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

        if (
            !$user
            || !$user->hasPermission('goods_receive.update')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Goods Receipt.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            /*
        |--------------------------------------------------------------------------
        | Normalisasi deleted attachment IDs
        |--------------------------------------------------------------------------
        | Karena upload file menggunakan FormData, nilainya dapat masuk
        | sebagai JSON string.
        |--------------------------------------------------------------------------
        */
            if (
                $request->has('deleted_attachment_ids')
                && is_string($request->deleted_attachment_ids)
            ) {
                $decodedDeletedAttachmentIds = json_decode(
                    $request->deleted_attachment_ids,
                    true,
                );

                if (
                    json_last_error()
                    === JSON_ERROR_NONE
                ) {
                    $request->merge([
                        'deleted_attachment_ids'
                        => $decodedDeletedAttachmentIds,
                    ]);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi request
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'tanggal_gr' => [
                    'required',
                    'date',
                ],

                'nomor_surat_jalan' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'notes' => [
                    'nullable',
                    'string',
                ],

                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'items.*.goods_receive_item_public_id' => [
                    'required',
                    'string',
                ],

                'items.*.purchase_order_item_public_id' => [
                    'required',
                    'string',
                ],

                'items.*.qty_receive' => [
                    'required',
                    'numeric',
                    'gt:0',
                ],

                'items.*.notes' => [
                    'nullable',
                    'string',
                ],

                /*
            |--------------------------------------------------------------------------
            | Attachment
            |--------------------------------------------------------------------------
            */
                'deleted_attachment_ids' => [
                    'nullable',
                    'array',
                ],

                'deleted_attachment_ids.*' => [
                    'string',
                ],

                'attachments' => [
                    'nullable',
                    'array',
                ],

                'attachments.*' => [
                    'file',
                    'max:5120',
                    'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx',
                ],

                'remove_all_attachments' => [
                    'nullable',
                    'boolean',
                ],
            ]);

            /*
        |--------------------------------------------------------------------------
        | Ambil dan lock Goods Receipt
        |--------------------------------------------------------------------------
        */
            $grId = Crypt::decrypt(
                urldecode($publicId),
            );

            /** @var GoodsReceive $gr */
            $gr = GoodsReceive::query()
                ->with([
                    'items',
                ])
                ->whereKey($grId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Hanya DRAFT yang dapat diubah
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $gr->status),
                ) !== 'DRAFT'
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receipt hanya dapat diubah jika status masih DRAFT.',
                ], 422);
            }

        /*
        |--------------------------------------------------------------------------
        | Lock Purchase Order
        |--------------------------------------------------------------------------
        */
            /** @var PurchaseOrder $purchaseOrder */
            $purchaseOrder = PurchaseOrder::query()
                ->select([
                    'id',
                    'vendor_id',
                    'cabang',
                    'id_department',
                ])
                ->whereKey(
                    $gr->purchase_order_id,
                )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                empty($purchaseOrder->cabang)
                || empty($purchaseOrder->id_department)
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cabang atau department pada Purchase Order belum lengkap.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Identifikasi GR normal atau GR replacement
        |--------------------------------------------------------------------------
        | Flow normal tidak diubah.
        |--------------------------------------------------------------------------
        */
            $isReplacement = (
                $gr->source_goods_return_id !== null
            );

            $sourceGoodsReturn = null;
            $sourceGoodsReturnId = null;

            /*
        |--------------------------------------------------------------------------
        | Validasi sumber Goods Return
        |--------------------------------------------------------------------------
        | Hanya dijalankan untuk GR replacement.
        |--------------------------------------------------------------------------
        */
            if ($isReplacement) {
                /** @var GoodsReturn $sourceGoodsReturn */
                $sourceGoodsReturn = GoodsReturn::query()
                    ->whereKey(
                        $gr->source_goods_return_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    strtoupper(
                        trim(
                            (string) $sourceGoodsReturn->status,
                        ),
                    ) !== GoodsReturn::STATUS_POSTED
                ) {
                    throw new \Exception(
                        'Goods Return sumber harus berstatus POSTED.',
                    );
                }

                if (
                    (int) $sourceGoodsReturn->purchase_order_id
                    !== (int) $gr->purchase_order_id
                ) {
                    throw new \Exception(
                        'Purchase Order Goods Receipt tidak sesuai dengan Goods Return sumber.',
                    );
                }

                $sourceGoodsReturnId = (int) $sourceGoodsReturn->id;
            }

            /*
        |--------------------------------------------------------------------------
        | Update header Goods Receipt
        |--------------------------------------------------------------------------
        | Purchase Order dan source Goods Return tidak dapat diganti.
        |--------------------------------------------------------------------------
        */
            $gr->update([
                'tanggal_gr'
                => $validated['tanggal_gr'],

                'nomor_surat_jalan'
                => $validated['nomor_surat_jalan']
                    ?? null,

                'notes'
                => $validated['notes']
                    ?? null,

                'cabang'
                => $purchaseOrder->cabang,

                'id_department'
                => $purchaseOrder->id_department,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Update item Goods Receipt
        |--------------------------------------------------------------------------
        */
            foreach (
                $validated['items'] as $itemPayload
            ) {
                $grItemId = Crypt::decryptString(
                    $itemPayload['goods_receive_item_public_id'],
                );

                $poItemId = Crypt::decryptString(
                    $itemPayload['purchase_order_item_public_id'],
                );

            /*
            |--------------------------------------------------------------------------
            | Pastikan item memang milik GR ini dan sesuai item PO
            |--------------------------------------------------------------------------
            */
                /** @var GoodsReceiveItem $grItem */
                $grItem = GoodsReceiveItem::query()
                    ->where(
                        'goods_receive_id',
                        $gr->id,
                    )
                    ->whereKey(
                        $grItemId,
                    )
                    ->where(
                        'purchase_order_item_id',
                        $poItemId,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Lock item Purchase Order
            |--------------------------------------------------------------------------
            */
                /** @var PurchaseOrderItem $poItem */
                $poItem = PurchaseOrderItem::query()
                    ->whereKey($poItemId)
                    ->where(
                        'purchase_order_id',
                        $gr->purchase_order_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyReceive = (float) (
                    $itemPayload['qty_receive']
                    ?? 0
                );

                /*
            |--------------------------------------------------------------------------
            | Validasi khusus GR replacement
            |--------------------------------------------------------------------------
            */
                if ($isReplacement) {
                    /*
                |--------------------------------------------------------------------------
                | Ambil item retur yang sesuai dengan item PO
                |--------------------------------------------------------------------------
                | Menggunakan collection agar tetap aman jika secara historis
                | terdapat lebih dari satu item Goods Return untuk item PO sama.
                |--------------------------------------------------------------------------
                */
                    $sourceReturnItems = GoodsReturnItem::query()
                        ->where(
                            'goods_return_id',
                            $sourceGoodsReturnId,
                        )
                        ->where(
                            'purchase_order_item_id',
                            $poItem->id,
                        )
                        ->lockForUpdate()
                        ->get();

                    if ($sourceReturnItems->isEmpty()) {
                        throw new \Exception(
                            "Item {$grItem->nama_item} tidak termasuk dalam Goods Return sumber.",
                        );
                    }

                    /*
                |--------------------------------------------------------------------------
                | Total qty yang harus diganti dari Goods Return
                |--------------------------------------------------------------------------
                */
                    $qtyReturn = (float) $sourceReturnItems
                        ->sum(function ($returnItem) {
                            return (float) (
                                $returnItem->qty_return
                                ?? 0
                            );
                        });

                    if ($qtyReturn <= 0) {
                        throw new \Exception(
                            "Qty return item {$grItem->nama_item} tidak tersedia.",
                        );
                    }

                    /*
                |--------------------------------------------------------------------------
                | Replacement lain yang sudah memakai qty retur
                |--------------------------------------------------------------------------
                | GR yang sedang diedit dikecualikan.
                | GR lain berstatus DRAFT dan POSTED tetap dihitung.
                |--------------------------------------------------------------------------
                */
                    $qtyReplacementOther = (float) DB::table(
                        'goods_receive_items as gri',
                    )
                        ->join(
                            'goods_receives as replacement_gr',
                            'replacement_gr.id',
                            '=',
                            'gri.goods_receive_id',
                        )
                        ->where(
                            'replacement_gr.source_goods_return_id',
                            $sourceGoodsReturnId,
                        )
                        ->where(
                            'gri.purchase_order_item_id',
                            $poItem->id,
                        )
                        ->where(
                            'replacement_gr.id',
                            '<>',
                            $gr->id,
                        )
                        ->whereRaw(
                            'UPPER(TRIM(replacement_gr.status)) IN (?, ?)',
                            [
                                'DRAFT',
                                'POSTED',
                            ],
                        )
                        ->whereNull(
                            'replacement_gr.deleted_at',
                        )
                        ->sum(
                            'gri.qty_receive',
                        );

                    /*
                |--------------------------------------------------------------------------
                | Maksimal qty replacement untuk GR yang sedang diedit
                |--------------------------------------------------------------------------
                */
                    $maxReceive = max(
                        $qtyReturn
                            - $qtyReplacementOther,
                        0,
                    );

                    if (
                        $qtyReceive
                        > ($maxReceive + 0.0001)
                    ) {
                        throw new \Exception(
                            "Qty replacement item {$grItem->nama_item} "
                                . "melebihi outstanding replacement. "
                                . "Maksimal {$maxReceive}.",
                        );
                    }

                    /*
                |--------------------------------------------------------------------------
                | Snapshot qty khusus replacement
                |--------------------------------------------------------------------------
                */
                    $qtyOrdered = $qtyReturn;

                    $qtyReceivedBefore
                        = $qtyReplacementOther;

                    $qtyReceivedAfter = (
                        $qtyReceivedBefore
                        + $qtyReceive
                    );

                    $qtyOutstanding = max(
                        $qtyOrdered
                            - $qtyReceivedAfter,
                        0,
                    );
                } else {
                    /*
                |--------------------------------------------------------------------------
                | GR normal
                |--------------------------------------------------------------------------
                | Logika existing dipertahankan.
                |--------------------------------------------------------------------------
                */
                    $qtyOrdered = (float) (
                        $grItem->qty_ordered
                        ?? $poItem->qty
                        ?? 0
                    );

                    $qtyReceivedBefore = (float) (
                        $grItem->qty_received_before
                        ?? 0
                    );

                    $maxReceive = max(
                        $qtyOrdered
                            - $qtyReceivedBefore,
                        0,
                    );

                    if ($qtyReceive > $maxReceive) {
                        throw new \Exception(
                            "Qty receipt item {$grItem->nama_item} melebihi qty yang tersedia.",
                        );
                    }

                    $qtyReceivedAfter = (
                        $qtyReceivedBefore
                        + $qtyReceive
                    );

                    $qtyOutstanding = max(
                        $qtyOrdered
                            - $qtyReceivedAfter,
                        0,
                    );
                }

                /*
            |--------------------------------------------------------------------------
            | Update detail Goods Receipt
            |--------------------------------------------------------------------------
            */
                $grItem->update([
                    'qty_receive'
                    => $qtyReceive,

                    'qty_received_before'
                    => $qtyReceivedBefore,

                    'qty_received_after'
                    => $qtyReceivedAfter,

                    'qty_outstanding'
                    => $qtyOutstanding,

                    'notes'
                    => $itemPayload['notes']
                        ?? null,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Hapus attachment lama yang dipilih frontend
        |--------------------------------------------------------------------------
        */
            $deletedAttachmentIds = collect(
                $validated['deleted_attachment_ids']
                    ?? [],
            )
                ->filter()
                ->map(function ($encryptedAttachmentId) {
                    try {
                        return Crypt::decrypt(
                            $encryptedAttachmentId,
                        );
                    } catch (\Throwable $e) {
                        return null;
                    }
                })
                ->filter()
                ->values()
                ->toArray();

            $removeAllAttachments = filter_var(
                $request->input(
                    'remove_all_attachments',
                    false,
                ),
                FILTER_VALIDATE_BOOLEAN,
            );

            if ($removeAllAttachments) {
                $attachmentsToDelete = GoodsReceiveAttachment::query()
                    ->where(
                        'goods_receive_id',
                        $gr->id,
                    )
                    ->get();
            } elseif (
                count($deletedAttachmentIds) > 0
            ) {
                $attachmentsToDelete = GoodsReceiveAttachment::query()
                    ->where(
                        'goods_receive_id',
                        $gr->id,
                    )
                    ->whereIn(
                        'id',
                        $deletedAttachmentIds,
                    )
                    ->get();
            } else {
                $attachmentsToDelete = collect();
            }

            foreach (
                $attachmentsToDelete as $attachment
            ) {
                if (
                    !empty($attachment->file_path)
                    && Storage::disk('public')
                    ->exists($attachment->file_path)
                ) {
                    Storage::disk('public')
                        ->delete($attachment->file_path);
                }

                $attachment->delete();
            }

            /*
        |--------------------------------------------------------------------------
        | Upload attachment baru
        |--------------------------------------------------------------------------
        | Logic existing tetap digunakan.
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('attachments')) {
                $basePath =
                    "syopv4/uploads/goods_receipt/{$gr->id}";

                foreach (
                    $request->file('attachments')
                    as $file
                ) {
                    $originalName = $file
                        ->getClientOriginalName();

                    $extension = $file
                        ->getClientOriginalExtension();

                    $safeOriginalName = pathinfo(
                        $originalName,
                        PATHINFO_FILENAME,
                    );

                    $safeOriginalName = preg_replace(
                        '/[^A-Za-z0-9_\-]/',
                        '_',
                        $safeOriginalName,
                    );

                    $fileName = now()->format(
                        'YmdHis',
                    )
                        . '_'
                        . uniqid()
                        . '_'
                        . $safeOriginalName
                        . '.'
                        . $extension;

                    $filePath = $file->storeAs(
                        $basePath,
                        $fileName,
                        'public',
                    );

                    GoodsReceiveAttachment::query()
                        ->create([
                            'goods_receive_id'
                            => $gr->id,

                            'document_type'
                            => null,

                            'file_name'
                            => $fileName,

                            'file_original_name'
                            => $originalName,

                            'file_path'
                            => $filePath,

                            'file_mime_type'
                            => $file->getClientMimeType(),

                            'file_size'
                            => $file->getSize(),

                            'uploaded_by'
                            => $user->id
                                ?? null,
                        ]);
                }
            }

            DB::commit();

            $gr->refresh();

            return response()->json([
                'success' => true,

                'message' => $isReplacement
                    ? 'Goods Receipt replacement berhasil diperbarui.'
                    : 'Goods Receipt berhasil diperbarui.',

                'data' => [
                    'id'
                    => $gr->id,

                    'public_id'
                    => Crypt::encryptString(
                        (string) $gr->id,
                    ),

                    'nomor_gr'
                    => $gr->nomor_gr,

                    'status'
                    => $gr->status,

                    'tanggal_gr'
                    => $gr->tanggal_gr,

                    'nomor_surat_jalan'
                    => $gr->nomor_surat_jalan,

                    /*
                |--------------------------------------------------------------------------
                | Informasi tambahan tanpa mengubah field existing
                |--------------------------------------------------------------------------
                */
                    'is_replacement'
                    => $gr->source_goods_return_id
                        !== null,

                    'goods_return_public_id'
                    => $gr->source_goods_return_id
                        ? Crypt::encryptString(
                            (string) $gr->source_goods_return_id,
                        )
                        : null,
                ],
            ], 200);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Goods Receive] Update error',
                [
                    'public_id'
                    => $publicId,

                    'user_id'
                    => $request->user()?->id,

                    'is_replacement'
                    => isset($gr)
                        && $gr->source_goods_return_id
                        !== null,

                    'message'
                    => $e->getMessage(),

                    'file'
                    => $e->getFile(),

                    'line'
                    => $e->getLine(),

                    'request'
                    => $request->except([
                        'attachments',
                    ]),
                ],
            );

            return response()->json([
                'success' => false,

                'message'
                => 'Gagal memperbarui Goods Receipt.',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
                    'sourceGoodsReturn:id,nomor_return,tanggal_return,status,purchase_order_id',
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

                        'is_replacement' => $gr->source_goods_return_id !== null,

                        'source_goods_return_id'
                        => $gr->source_goods_return_id,

                        'goods_return_public_id'
                        => $gr->source_goods_return_id
                            ? Crypt::encryptString(
                                (string) $gr->source_goods_return_id
                            )
                            : null,

                        'nomor_return'
                        => $gr->sourceGoodsReturn?->nomor_return,

                        'tanggal_return'
                        => $gr->sourceGoodsReturn?->tanggal_return,

                        'goods_return_status'
                        => $gr->sourceGoodsReturn?->status,

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
                            $qtyReceive = (float) (
                                $item->qty_receive
                                ?? 0
                            );

                            $isReplacement = (
                                $gr->source_goods_return_id !== null
                            );

                            if ($isReplacement) {
                                /*
                    |--------------------------------------------------------------------------
                    | GR Replacement
                    |--------------------------------------------------------------------------
                    | Batas qty berasal dari Goods Return, bukan dari qty PO normal.
                    |--------------------------------------------------------------------------
                    */
                                $qtyReturn = (float) DB::table(
                                    'goods_return_items',
                                )
                                    ->where(
                                        'goods_return_id',
                                        $gr->source_goods_return_id,
                                    )
                                    ->where(
                                        'purchase_order_item_id',
                                        $item->purchase_order_item_id,
                                    )
                                    ->sum('qty_return');

                                /*
                    |--------------------------------------------------------------------------
                    | Replacement lain, tidak termasuk GR yang sedang dibuka
                    |--------------------------------------------------------------------------
                    */
                                $qtyReplacementOther = (float) DB::table(
                                    'goods_receive_items as gri',
                                )
                                    ->join(
                                        'goods_receives as replacement_gr',
                                        'replacement_gr.id',
                                        '=',
                                        'gri.goods_receive_id',
                                    )
                                    ->where(
                                        'replacement_gr.source_goods_return_id',
                                        $gr->source_goods_return_id,
                                    )
                                    ->where(
                                        'gri.purchase_order_item_id',
                                        $item->purchase_order_item_id,
                                    )
                                    ->where(
                                        'replacement_gr.id',
                                        '<>',
                                        $gr->id,
                                    )
                                    ->whereRaw(
                                        'UPPER(TRIM(replacement_gr.status)) IN (?, ?)',
                                        [
                                            'DRAFT',
                                            'POSTED',
                                        ],
                                    )
                                    ->whereNull(
                                        'replacement_gr.deleted_at',
                                    )
                                    ->sum('gri.qty_receive');

                                /*
                    |--------------------------------------------------------------------------
                    | Untuk form edit replacement:
                    | qty_ordered menjadi batas qty yang diretur
                    |--------------------------------------------------------------------------
                    */
                                $qtyOrdered = $qtyReturn;

                                $qtyReceivedBefore = $qtyReplacementOther;

                                $qtyReceivedAfter = (
                                    $qtyReceivedBefore
                                    + $qtyReceive
                                );

                                $qtyOutstanding = max(
                                    $qtyReturn
                                        - $qtyReceivedAfter,
                                    0,
                                );

                                $qtyMaximumReceive = max(
                                    $qtyReturn
                                        - $qtyReplacementOther,
                                    0,
                                );
                            } else {
                                /*
                    |--------------------------------------------------------------------------
                    | GR normal
                    |--------------------------------------------------------------------------
                    | Kode existing dipertahankan.
                    |--------------------------------------------------------------------------
                    */
                                $qtyOrdered = (float) (
                                    $item->qty_ordered
                                    ?? 0
                                );

                                $qtyReceivedBefore = (float) DB::table(
                                    'goods_receive_items as gri',
                                )
                                    ->join(
                                        'goods_receives as gr',
                                        'gr.id',
                                        '=',
                                        'gri.goods_receive_id',
                                    )
                                    ->where(
                                        'gri.purchase_order_item_id',
                                        $item->purchase_order_item_id,
                                    )
                                    ->whereIn(
                                        'gr.status',
                                        [
                                            'DRAFT',
                                            'POSTED',
                                        ],
                                    )
                                    ->whereNull(
                                        'gr.deleted_at',
                                    )
                                    ->where(
                                        'gr.id',
                                        '<',
                                        $gr->id,
                                    )
                                    ->sum('gri.qty_receive');

                                $qtyReceivedAfter = (
                                    $qtyReceivedBefore
                                    + $qtyReceive
                                );

                                $qtyOutstanding = max(
                                    $qtyOrdered
                                        - $qtyReceivedAfter,
                                    0,
                                );

                                $qtyMaximumReceive = max(
                                    $qtyOrdered
                                        - $qtyReceivedBefore,
                                    0,
                                );
                            }

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

        if (
            !$user
            || !$user->hasPermission('goods_receive.delete')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Goods Receipt.',
            ], 403);
        }

        $attachmentFilePaths = [];

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString(
                urldecode($publicId),
            );

        /*
        |--------------------------------------------------------------------------
        | Lock Goods Receipt
        |--------------------------------------------------------------------------
        */
            /** @var GoodsReceive $gr */
            $gr = GoodsReceive::query()
                ->with([
                    'items',
                    'attachments',
                ])
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Hanya Goods Receipt DRAFT yang dapat dihapus
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $gr->status),
                ) !== 'DRAFT'
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receipt hanya dapat dihapus jika status masih DRAFT.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Simpan lokasi attachment sebelum record dihapus
        |--------------------------------------------------------------------------
        | File fisik baru dihapus setelah transaksi database berhasil.
        |--------------------------------------------------------------------------
        */
            $attachmentFilePaths = $gr
                ->attachments
                ->pluck('file_path')
                ->filter()
                ->values()
                ->all();

            /*
        |--------------------------------------------------------------------------
        | Hapus detail Goods Receipt
        |--------------------------------------------------------------------------
        | Berlaku sama untuk GR normal maupun GR replacement.
        |--------------------------------------------------------------------------
        */
            DB::table('goods_receive_items')
                ->where(
                    'goods_receive_id',
                    $gr->id,
                )
                ->delete();

            /*
        |--------------------------------------------------------------------------
        | Hapus record attachment
        |--------------------------------------------------------------------------
        */
            GoodsReceiveAttachment::query()
                ->where(
                    'goods_receive_id',
                    $gr->id,
                )
                ->delete();

            /*
        |--------------------------------------------------------------------------
        | Soft delete header Goods Receipt
        |--------------------------------------------------------------------------
        | source_goods_return_id tidak perlu diubah.
        |
        | Karena header sudah memiliki deleted_at, qty replacement draft ini
        | otomatis tidak lagi dihitung sebagai reservation.
        |--------------------------------------------------------------------------
        */
            $isReplacement = (
                $gr->source_goods_return_id !== null
            );

            $gr->delete();

            DB::commit();

        /*
        |--------------------------------------------------------------------------
        | Hapus file attachment setelah commit
        |--------------------------------------------------------------------------
        */
            /** @var FilesystemAdapter $publicDisk */
            $publicDisk = Storage::disk('public');

            foreach ($attachmentFilePaths as $filePath) {
                if ($publicDisk->exists($filePath)) {
                    $publicDisk->delete($filePath);
                }
            }

            return response()->json([
                'success' => true,

                'message' => $isReplacement
                    ? 'Draft Goods Receipt replacement berhasil dihapus.'
                    : 'Goods Receipt berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Goods Receipt] Destroy error',
                [
                    'public_id' => $publicId,

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
                'message' => 'Gagal menghapus Goods Receipt.',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
