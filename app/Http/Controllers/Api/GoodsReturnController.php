<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceive;
use App\Models\GoodsReceiveItem;
use App\Models\GoodsReturn;
use App\Models\GoodsReturnAttachment;
use App\Models\GoodsReturnItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\GoodsReturnReason;
use App\Models\PurchaseOrderItem;
use App\Services\NonTrade\GoodsReturn\GoodsReturnCancellationService;
use App\Services\NonTrade\GoodsReturn\GoodsReturnPostingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GoodsReturnController extends Controller
{
    public function index(
        Request $request,
    ): JsonResponse {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission Goods Return
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission(
                'goods_return.view',
            );

            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_return.view',
                    ),
                ),
            );

            $canCreate = $user->hasPermission(
                'goods_return.create',
            );

            $canUpdate = $user->hasPermission(
                'goods_return.update',
            );

            $canDelete = $user->hasPermission(
                'goods_return.delete',
            );

            $canPost = $user->hasPermission(
                'goods_return.post',
            );

            $canCancel = $user->hasPermission(
                'goods_return.cancel',
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

            if (
                !in_array(
                    $viewScope,
                    $allowedScopes,
                    true,
                )
            ) {
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
        | Query Goods Return
        |--------------------------------------------------------------------------
        */
            $query = GoodsReturn::query()
                ->with([
                    'goodsReceive:id,nomor_gr,purchase_order_id',

                    'purchaseOrder:id,nomor_po,cabang,id_department',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'department:id,kode,nama',

                    'vendor:id,nama_vendor',

                    'creator:id,name',

                    'poster:id,name',

                    'canceller:id,name',
                ])
                ->withSum(
                    'items as total_qty',
                    'qty_return',
                )
                ->withCount([
                    /*
                |--------------------------------------------------------------------------
                | GR replacement aktif
                |--------------------------------------------------------------------------
                | Dipakai untuk menentukan apakah tombol cancel boleh muncul.
                |--------------------------------------------------------------------------
                */
                    'replacementGoodsReceives as active_replacement_gr_count'
                    => function ($replacementQuery) {
                        $replacementQuery->whereIn(
                            'status',
                            [
                                'DRAFT',
                                'POSTED',
                            ],
                        );
                    },
                ]);

            /*
        |--------------------------------------------------------------------------
        | Visibility berdasarkan scope
        |--------------------------------------------------------------------------
        */
            if (
                !$canView
                || $viewScope === 'NONE'
            ) {
                $query->whereRaw('1 = 0');
            } elseif ($viewScope === 'OWN_DATA') {
                $query->where(
                    'goods_returns.created_by',
                    $user->id,
                );
            } elseif ($viewScope === 'OWN_DEPARTMENT') {
                if ($departmentId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    /*
                |--------------------------------------------------------------------------
                | Gunakan snapshot department Goods Return
                |--------------------------------------------------------------------------
                */
                    $query->where(
                        'goods_returns.id_department',
                        $departmentId,
                    );
                }
            } elseif ($viewScope === 'OWN_CABANG') {
                if ($cabangId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    /*
                |--------------------------------------------------------------------------
                | Gunakan snapshot cabang Goods Return
                |--------------------------------------------------------------------------
                */
                    $query->where(
                        'goods_returns.cabang',
                        $cabangId,
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Scope ALL
        |--------------------------------------------------------------------------
        | Tidak memerlukan filter tambahan.
        |--------------------------------------------------------------------------
        */

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->input('search'),
                );

                if ($search !== '') {
                    $query->where(
                        function ($searchQuery) use ($search) {
                            $searchQuery
                                ->where(
                                    'goods_returns.nomor_return',
                                    'ILIKE',
                                    "%{$search}%",
                                )
                                ->orWhereHas(
                                    'goodsReceive',
                                    function ($goodsReceiveQuery) use ($search) {
                                        $goodsReceiveQuery->where(
                                            'nomor_gr',
                                            'ILIKE',
                                            "%{$search}%",
                                        );
                                    },
                                )
                                ->orWhereHas(
                                    'purchaseOrder',
                                    function ($purchaseOrderQuery) use ($search) {
                                        $purchaseOrderQuery->where(
                                            'nomor_po',
                                            'ILIKE',
                                            "%{$search}%",
                                        );
                                    },
                                )
                                ->orWhereHas(
                                    'vendor',
                                    function ($vendorQuery) use ($search) {
                                        $vendorQuery->where(
                                            'nama_vendor',
                                            'ILIKE',
                                            "%{$search}%",
                                        );
                                    },
                                );
                        },
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status
        |--------------------------------------------------------------------------
        */
            $status = strtoupper(
                trim(
                    (string) $request->input(
                        'status',
                        '',
                    ),
                ),
            );

            if (
                $status !== ''
                && $status !== 'ALL'
                && $status !== 'SEMUA'
            ) {
                $query->whereRaw(
                    'UPPER(TRIM(goods_returns.status)) = ?',
                    [
                        $status,
                    ],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Filter tanggal retur
        |--------------------------------------------------------------------------
        */
            $startDate = $request->input(
                'tanggal_mulai',
            ) ?? $request->input(
                'start_date',
            );

            $endDate = $request->input(
                'tanggal_selesai',
            ) ?? $request->input(
                'end_date',
            );

            if (!empty($startDate)) {
                $query->whereDate(
                    'goods_returns.tanggal_return',
                    '>=',
                    $startDate,
                );
            }

            if (!empty($endDate)) {
                $query->whereDate(
                    'goods_returns.tanggal_return',
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
                $request->input(
                    'per_page',
                    10,
                )
            );

            $perPage = $perPage > 0
                ? $perPage
                : 10;

            $goodsReturns = $query
                ->orderByDesc(
                    'goods_returns.id',
                )
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Transform response
        |--------------------------------------------------------------------------
        */
            $goodsReturns
                ->getCollection()
                ->transform(
                    function (GoodsReturn $goodsReturn) use (
                        $user,
                        $canUpdate,
                        $canDelete,
                        $canPost,
                        $canCancel,
                    ) {
                        $status = strtoupper(
                            trim(
                                (string) $goodsReturn->status,
                            ),
                        );

                        /*
                    |--------------------------------------------------------------------------
                    | Kemampuan action per row
                    |--------------------------------------------------------------------------
                    */
                        $canUpdateRow = (
                            $canUpdate
                            && $status === GoodsReturn::STATUS_DRAFT
                        );

                        $canDeleteRow = (
                            $canDelete
                            && $status === GoodsReturn::STATUS_DRAFT
                        );

                        $canPostRow = (
                            $canPost
                            && $status === GoodsReturn::STATUS_DRAFT
                        );

                        /*
                    |--------------------------------------------------------------------------
                    | Retur tidak dapat dibatalkan jika sudah ada GR replacement
                    |--------------------------------------------------------------------------
                    */
                        $hasActiveReplacementGr = (
                            (int) $goodsReturn
                                ->active_replacement_gr_count
                            > 0
                        );

                        $canCancelRow = (
                            $canCancel
                            && $status === GoodsReturn::STATUS_POSTED
                            && !$hasActiveReplacementGr
                        );

                        return [
                            'id'
                            => $goodsReturn->id,

                            'public_id'
                            => Crypt::encryptString(
                                (string) $goodsReturn->id,
                            ),

                            'nomor_return'
                            => $goodsReturn->nomor_return,

                            'tanggal_return'
                            => $goodsReturn->tanggal_return,

                            /*
                        |--------------------------------------------------------------------------
                        | Goods Receipt sumber
                        |--------------------------------------------------------------------------
                        */
                            'goods_receive_id'
                            => $goodsReturn->goods_receive_id,

                            'nomor_gr'
                            => $goodsReturn
                                ->goodsReceive
                                ?->nomor_gr
                                ?? '-',

                            /*
                        |--------------------------------------------------------------------------
                        | Purchase Order sumber
                        |--------------------------------------------------------------------------
                        */
                            'purchase_order_id'
                            => $goodsReturn->purchase_order_id,

                            'nomor_po'
                            => $goodsReturn
                                ->purchaseOrder
                                ?->nomor_po
                                ?? '-',

                            /*
                        |--------------------------------------------------------------------------
                        | Cabang
                        |--------------------------------------------------------------------------
                        */
                            'cabang_id'
                            => $goodsReturn->cabang,

                            'cabang'
                            => $goodsReturn
                                ->purchaseOrder
                                ?->cabangData
                                ?->nama_cabang
                                ?? '-',

                            'inisial_cabang'
                            => $goodsReturn
                                ->purchaseOrder
                                ?->cabangData
                                ?->inisial_cabang
                                ?? '-',

                            /*
                        |--------------------------------------------------------------------------
                        | Department
                        |--------------------------------------------------------------------------
                        */
                            'department_id'
                            => $goodsReturn->id_department,

                            'department'
                            => $goodsReturn
                                ->department
                                ?->kode
                                ?? '-',

                            'department_name'
                            => $goodsReturn
                                ->department
                                ?->nama
                                ?? '-',

                            /*
                        |--------------------------------------------------------------------------
                        | Vendor
                        |--------------------------------------------------------------------------
                        */
                            'vendor_id'
                            => $goodsReturn->vendor_id,

                            'vendor'
                            => $goodsReturn
                                ->vendor
                                ?->nama_vendor
                                ?? '-',

                            /*
                        |--------------------------------------------------------------------------
                        | Informasi retur
                        |--------------------------------------------------------------------------
                        */
                            'status'
                            => $goodsReturn->status,

                            'notes'
                            => $goodsReturn->notes,

                            'total_qty'
                            => (float) (
                                $goodsReturn->total_qty
                                ?? 0
                            ),

                            /*
                        |--------------------------------------------------------------------------
                        | Informasi replacement
                        |--------------------------------------------------------------------------
                        */
                            'active_replacement_gr_count'
                            => (int) (
                                $goodsReturn
                                ->active_replacement_gr_count
                                ?? 0
                            ),

                            'has_replacement_gr'
                            => $hasActiveReplacementGr,

                            /*
                        |--------------------------------------------------------------------------
                        | Audit creator
                        |--------------------------------------------------------------------------
                        */
                            'created_by_id'
                            => $goodsReturn->created_by,

                            'created_by'
                            => $goodsReturn
                                ->creator
                                ?->name
                                ?? $goodsReturn->created_by,

                            'created_at'
                            => $goodsReturn->created_at,

                            /*
                        |--------------------------------------------------------------------------
                        | Audit posting
                        |--------------------------------------------------------------------------
                        */
                            'posted_by_id'
                            => $goodsReturn->posted_by,

                            'posted_by'
                            => $goodsReturn
                                ->poster
                                ?->name,

                            'posted_at'
                            => $goodsReturn->posted_at,

                            /*
                        |--------------------------------------------------------------------------
                        | Audit pembatalan
                        |--------------------------------------------------------------------------
                        */
                            'cancelled_by_id'
                            => $goodsReturn->cancelled_by,

                            'cancelled_by'
                            => $goodsReturn
                                ->canceller
                                ?->name,

                            'cancelled_at'
                            => $goodsReturn->cancelled_at,

                            'cancel_notes'
                            => $goodsReturn->cancel_notes,

                            /*
                        |--------------------------------------------------------------------------
                        | Abilities per row
                        |--------------------------------------------------------------------------
                        */
                            'can_update'
                            => $canUpdateRow,

                            'can_delete'
                            => $canDeleteRow,

                            'can_post'
                            => $canPostRow,

                            'can_cancel'
                            => $canCancelRow,

                            'is_owner'
                            => (int) $goodsReturn->created_by
                                === (int) $user->id,
                        ];
                    },
                );

            return response()->json([
                'success' => true,

                'message'
                => 'Data Goods Return berhasil dimuat.',

                'data'
                => $goodsReturns->items(),

                /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */
                'pagination' => [
                    'current_page'
                    => $goodsReturns->currentPage(),

                    'last_page'
                    => $goodsReturns->lastPage(),

                    'per_page'
                    => $goodsReturns->perPage(),

                    'total'
                    => $goodsReturns->total(),
                ],

                /*
            |--------------------------------------------------------------------------
            | Meta
            |--------------------------------------------------------------------------
            */
                'meta' => [
                    'current_page'
                    => $goodsReturns->currentPage(),

                    'last_page'
                    => $goodsReturns->lastPage(),

                    'per_page'
                    => $goodsReturns->perPage(),

                    'total'
                    => $goodsReturns->total(),
                ],

                /*
            |--------------------------------------------------------------------------
            | Global abilities
            |--------------------------------------------------------------------------
            */
                'abilities' => [
                    'can_view'
                    => $canView,

                    'view_scope'
                    => $viewScope,

                    'can_create'
                    => $canCreate,

                    'can_update'
                    => $canUpdate,

                    'can_delete'
                    => $canDelete,

                    'can_post'
                    => $canPost,

                    'can_cancel'
                    => $canCancel,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Index error',
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
                => 'Gagal memuat data Goods Return.',

                'data' => [],

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function reasons(
        Request $request,
    ): JsonResponse {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission
        |--------------------------------------------------------------------------
        | Dropdown alasan digunakan pada halaman create dan edit retur.
        |--------------------------------------------------------------------------
        */
            $canAccess = $user
                && (
                    $user->hasPermission('goods_return.create')
                    || $user->hasPermission('goods_return.update')
                );

            if (!$canAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke master alasan retur.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil alasan retur aktif
        |--------------------------------------------------------------------------
        */
            $reasons = GoodsReturnReason::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'description',
                ])
                ->map(function (GoodsReturnReason $reason) {
                    return [
                        'id' => (int) $reason->id,
                        'code' => $reason->code,
                        'name' => $reason->name,
                        'description' => $reason->description,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data alasan retur berhasil diambil.',
                'data' => $reasons,
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Get return reasons error',
                [
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data alasan retur.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function createData(
        Request $request,
    ): JsonResponse {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission create Goods Return
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_return.create')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk membuat retur barang.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Query GR yang masih mempunyai qty untuk diretur
        |--------------------------------------------------------------------------
        |
        | Qty returnable:
        | qty_receive GR
        | dikurangi total qty Goods Return berstatus POSTED.
        |--------------------------------------------------------------------------
        */
            $goodsReceiveQuery = GoodsReceive::query()
                ->with([
                    'purchaseOrder:id,nomor_po,tanggal_po,vendor_id,cabang,id_department',

                    'purchaseOrder.vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'purchaseOrder.departmentData:id,kode,nama',
                ])
                ->whereRaw(
                    'UPPER(TRIM(goods_receives.status)) = ?',
                    ['POSTED'],
                )
                ->whereExists(function ($query) {
                    $query
                        ->selectRaw('1')
                        ->from('goods_receive_items as gr_item')
                        ->whereColumn(
                            'gr_item.goods_receive_id',
                            'goods_receives.id',
                        )
                        ->whereRaw(
                            '
                COALESCE(gr_item.qty_receive, 0)
                >
                COALESCE(
                    (
                        SELECT SUM(return_item.qty_return)
                        FROM goods_return_items AS return_item
                        INNER JOIN goods_returns AS return_header
                            ON return_header.id = return_item.goods_return_id
                        WHERE return_item.goods_receive_item_id = gr_item.id
                          AND UPPER(TRIM(return_header.status)) = ?
                          AND return_header.deleted_at IS NULL
                    ),
                    0
                )
                ',
                            [
                                GoodsReturn::STATUS_POSTED,
                            ],
                        );
                });

            /*
        |--------------------------------------------------------------------------
        | Daftar GR untuk dropdown
        |--------------------------------------------------------------------------
        */
            $goodsReceives = (clone $goodsReceiveQuery)
                ->orderByDesc('goods_receives.tanggal_gr')
                ->orderByDesc('goods_receives.id')
                ->get()
                ->map(function (GoodsReceive $goodsReceive) {
                    $purchaseOrder = $goodsReceive->purchaseOrder;
                    return [
                        'public_id'
                        => Crypt::encryptString(
                            (string) $goodsReceive->id,
                        ),

                        'nomor_gr'
                        => $goodsReceive->nomor_gr,

                        'tanggal_gr'
                        => $goodsReceive->tanggal_gr,

                        'purchase_order_id'
                        => $purchaseOrder?->id,

                        'purchase_order_public_id'
                        => $purchaseOrder
                            ? Crypt::encryptString(
                                (string) $purchaseOrder->id,
                            )
                            : null,

                        'nomor_po'
                        => $purchaseOrder?->nomor_po
                            ?? '-',

                        'tanggal_po'
                        => $purchaseOrder?->tanggal_po,

                        'vendor_id'
                        => $purchaseOrder?->vendor_id,

                        'vendor'
                        => $purchaseOrder?->vendor?->nama_vendor
                            ?? '-',

                        'nama_vendor'
                        => $purchaseOrder?->vendor?->nama_vendor
                            ?? '-',

                        'cabang_id'
                        => $purchaseOrder?->cabang,

                        'cabang'
                        => $purchaseOrder?->cabangData?->inisial_cabang
                            ?? '-',

                        'nama_cabang'
                        => $purchaseOrder?->cabangData?->nama_cabang
                            ?? '-',

                        'department_id'
                        => $purchaseOrder?->id_department,

                        'department'
                        => $purchaseOrder?->departmentData?->kode
                            ?? '-',

                        'department_code'
                        => $purchaseOrder?->departmentData?->kode
                            ?? '-',

                        'department_name'
                        => $purchaseOrder?->departmentData?->nama
                            ?? '-',

                        'label'
                        => trim(
                            ($goodsReceive->nomor_gr ?? '-')
                                . ' | PO '
                                . ($purchaseOrder?->nomor_po ?? '-')
                                . ' | '
                                . (
                                    $purchaseOrder?->vendor?->nama_vendor
                                    ?? '-'
                                ),
                        ),
                    ];
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Detail GR terpilih
        |--------------------------------------------------------------------------
        | Parameter ini dikirim setelah user memilih GR.
        |--------------------------------------------------------------------------
        */
            $selectedGoodsReceive = null;

            $goodsReceivePublicId = $request->query(
                'goods_receive_public_id',
            );

            if (filled($goodsReceivePublicId)) {
                $goodsReceiveId = Crypt::decryptString(
                    urldecode(
                        (string) $goodsReceivePublicId,
                    ),
                );

                /*
            |--------------------------------------------------------------------------
            | Pastikan GR terpilih memang POSTED dan masih bisa diretur
            |--------------------------------------------------------------------------
            */
                $goodsReceive = (clone $goodsReceiveQuery)
                    ->where(
                        'goods_receives.id',
                        $goodsReceiveId,
                    )
                    ->first();

                if (!$goodsReceive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Goods Receipt tidak ditemukan atau seluruh qty barang sudah diretur.',
                    ], 422);
                }

                /*
            |--------------------------------------------------------------------------
            | Subquery total retur POSTED per item GR
            |--------------------------------------------------------------------------
            */
                $postedReturnSubquery = DB::table(
                    'goods_return_items as return_item',
                )
                    ->join(
                        'goods_returns as return_header',
                        'return_header.id',
                        '=',
                        'return_item.goods_return_id',
                    )
                    ->whereRaw(
                        'UPPER(TRIM(return_header.status)) = ?',
                        [
                            GoodsReturn::STATUS_POSTED,
                        ],
                    )
                    ->whereNull(
                        'return_header.deleted_at',
                    )
                    ->groupBy(
                        'return_item.goods_receive_item_id',
                    )
                    ->select([
                        'return_item.goods_receive_item_id',
                    ])
                    ->selectRaw(
                        '
                        SUM(return_item.qty_return)
                            AS qty_returned
                        ',
                    );

                $items = DB::table(
                    'goods_receive_items as gr_item',
                )
                    ->leftJoin(
                        'purchase_order_items as po_item',
                        'po_item.id',
                        '=',
                        'gr_item.purchase_order_item_id',
                    )

                    /*
                |--------------------------------------------------------------------------
                | Unit item
                |--------------------------------------------------------------------------
                | Gunakan unit snapshot Goods Receipt.
                | Jika kosong, fallback ke satuan pada item Purchase Order.
                |--------------------------------------------------------------------------
                */
                    ->leftJoin(
                        'units as unit',
                        function ($join) {
                            $join->whereRaw(
                                "
                                unit.id::text = COALESCE(
                                    NULLIF(TRIM(gr_item.unit), ''),
                                    po_item.satuan::text
                                )
                                ",
                            );
                        },
                    )

                    ->leftJoinSub(
                        $postedReturnSubquery,
                        'posted_return',
                        function ($join) {
                            $join->on(
                                'posted_return.goods_receive_item_id',
                                '=',
                                'gr_item.id',
                            );
                        },
                    )

                    ->where(
                        'gr_item.goods_receive_id',
                        $goodsReceive->id,
                    )

                    ->orderBy(
                        'gr_item.id',
                    )

                    ->select([
                        'gr_item.id',

                        'gr_item.purchase_order_item_id',

                        'gr_item.nama_item',

                        'gr_item.qty_receive',

                        'unit.nama as unit_name',

                        'unit.kode as unit_code',
                    ])

                    ->selectRaw(
                        "
                        COALESCE(
                            NULLIF(TRIM(gr_item.unit), ''),
                            po_item.satuan::text
                        ) AS unit_id
                        ",
                    )

                    ->selectRaw(
                        '
                    COALESCE(
                        posted_return.qty_returned,
                        0
                    ) AS qty_returned
                    ',
                    )

                    ->get()

                    ->map(function ($item) {
                        $qtyReceived = (float) (
                            $item->qty_receive
                            ?? 0
                        );

                        $qtyReturnedBefore = (float) (
                            $item->qty_returned
                            ?? 0
                        );

                        $qtyReturnable = max(
                            $qtyReceived
                                - $qtyReturnedBefore,
                            0,
                        );

                        return [
                            'goods_receive_item_public_id'
                            => Crypt::encryptString(
                                (string) $item->id,
                            ),

                            'purchase_order_item_public_id'
                            => Crypt::encryptString(
                                (string) $item->purchase_order_item_id,
                            ),

                            'nama_item'
                            => $item->nama_item,

                            'unit_id'
                            => is_numeric($item->unit_id)
                                ? (int) $item->unit_id
                                : null,

                            'unit'
                            => $item->unit_name
                                ?? $item->unit_code
                                ?? '-',

                            'unit_name'
                            => $item->unit_name
                                ?? $item->unit_code
                                ?? '-',

                            'qty_received'
                            => $qtyReceived,

                            'qty_returned_before'
                            => $qtyReturnedBefore,

                            'qty_returnable'
                            => $qtyReturnable,

                            'qty_return'
                            => null,

                            'reason_id'
                            => null,

                            'reason_notes'
                            => null,
                        ];
                    })

                    ->filter(function ($item) {
                        return (
                            (float) $item['qty_returnable']
                            > 0.0001
                        );
                    })

                    ->values();

                $purchaseOrder = $goodsReceive->purchaseOrder;

                if (!$purchaseOrder) {
                    throw new \Exception(
                        'Purchase Order sumber Goods Receipt tidak ditemukan.',
                    );
                }

                $selectedGoodsReceive = [
                    'public_id'
                    => Crypt::encryptString(
                        (string) $goodsReceive->id,
                    ),

                    'nomor_gr'
                    => $goodsReceive->nomor_gr,

                    'tanggal_gr'
                    => $goodsReceive->tanggal_gr,

                    'purchase_order_id'
                    => $purchaseOrder->id,

                    'purchase_order_public_id'
                    => Crypt::encryptString(
                        (string) $purchaseOrder->id,
                    ),

                    'nomor_po'
                    => $purchaseOrder->nomor_po,

                    'tanggal_po'
                    => $purchaseOrder->tanggal_po,

                    'vendor_id'
                    => $purchaseOrder->vendor_id,

                    'vendor'
                    => $purchaseOrder->vendor?->nama_vendor
                        ?? '-',

                    'nama_vendor'
                    => $purchaseOrder->vendor?->nama_vendor
                        ?? '-',

                    'cabang_id'
                    => $purchaseOrder->cabang,

                    'cabang'
                    => $purchaseOrder->cabangData?->inisial_cabang
                        ?? '-',

                    'nama_cabang'
                    => $purchaseOrder->cabangData?->nama_cabang
                        ?? '-',

                    'department_id'
                    => $purchaseOrder->id_department,

                    'department'
                    => $purchaseOrder->departmentData?->kode
                        ?? '-',

                    'department_code'
                    => $purchaseOrder->departmentData?->kode
                        ?? '-',

                    'department_name'
                    => $purchaseOrder->departmentData?->nama
                        ?? '-',

                    'items'
                    => $items,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Data form retur barang berhasil diambil.',
                'data' => [
                    'goods_receives' => $goodsReceives,
                    'selected_goods_receive'
                    => $selectedGoodsReceive,
                ],
            ], 200);
        } catch (DecryptException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ID Goods Receipt tidak valid.',
            ], 422);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Get create data error',
                [
                    'user_id' => $request->user()?->id,
                    'goods_receive_public_id'
                    => $request->query(
                        'goods_receive_public_id',
                    ),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data form retur barang.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function store(
        Request $request,
    ): JsonResponse {
        $storedFilePaths = [];

        DB::beginTransaction();

        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_return.create')
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk membuat retur barang.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi request
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'goods_receive_public_id' => [
                    'required',
                    'string',
                ],

                'tanggal_return' => [
                    'required',
                    'date',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],

                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'items.*.goods_receive_item_public_id' => [
                    'required',
                    'string',
                    'distinct',
                ],

                'items.*.purchase_order_item_public_id' => [
                    'required',
                    'string',
                ],

                'items.*.qty_return' => [
                    'required',
                    'numeric',
                    'gt:0',
                ],

                'items.*.reason_id' => [
                    'required',
                    'integer',
                    'exists:goods_return_reasons,id',
                ],

                'items.*.reason_notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],

                'attachments' => [
                    'nullable',
                    'array',
                ],

                'attachments.*' => [
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:3072',
                ],
            ], [
                'goods_receive_public_id.required'
                => 'Goods Receipt wajib dipilih.',

                'tanggal_return.required'
                => 'Tanggal retur wajib diisi.',

                'items.required'
                => 'Item retur wajib diisi.',

                'items.min'
                => 'Minimal harus ada satu item retur.',

                'items.*.qty_return.required'
                => 'Qty retur wajib diisi.',

                'items.*.qty_return.gt'
                => 'Qty retur harus lebih besar dari nol.',

                'items.*.reason_id.required'
                => 'Alasan retur wajib dipilih.',
            ]);

            /*
        |--------------------------------------------------------------------------
        | Decrypt Goods Receipt
        |--------------------------------------------------------------------------
        */
            $goodsReceiveId = Crypt::decryptString(
                urldecode(
                    (string) $validated['goods_receive_public_id'],
                ),
            );

            /*
        |--------------------------------------------------------------------------
        | Lock Goods Receipt asal
        |--------------------------------------------------------------------------
        */
            $goodsReceive = GoodsReceive::query()
                ->lockForUpdate()
                ->findOrFail($goodsReceiveId);

            if (
                strtoupper(
                    trim((string) $goodsReceive->status),
                ) !== 'POSTED'
            ) {
                throw ValidationException::withMessages([
                    'goods_receive_public_id' => [
                        'Retur hanya dapat dibuat dari Goods Receipt yang sudah POSTED.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi snapshot organisasi GR
        |--------------------------------------------------------------------------
        */
            if (
                empty($goodsReceive->purchase_order_id)
                || empty($goodsReceive->cabang)
                || empty($goodsReceive->id_department)
            ) {
                throw ValidationException::withMessages([
                    'goods_receive_public_id' => [
                        'Data Purchase Order, cabang, atau department pada Goods Receipt belum lengkap.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Buat header Goods Return
        |--------------------------------------------------------------------------
        | Nomor retur dibuat nanti saat posting.
        |--------------------------------------------------------------------------
        */
            $nomorReturn = $this->generateDraftGoodsReturnNumber();
            $goodsReturn = GoodsReturn::query()->create([
                'nomor_return' => $nomorReturn,

                'goods_receive_id'
                => $goodsReceive->id,

                'purchase_order_id'
                => $goodsReceive->purchase_order_id,

                'vendor_id'
                => $goodsReceive->vendor_id,

                'cabang'
                => $goodsReceive->cabang,

                'id_department'
                => $goodsReceive->id_department,

                'tanggal_return'
                => $validated['tanggal_return'],

                'status'
                => GoodsReturn::STATUS_DRAFT,

                'notes'
                => $validated['notes'] ?? null,

                'created_by'
                => $user->id,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Simpan detail item retur
        |--------------------------------------------------------------------------
        */
            foreach ($validated['items'] as $index => $itemPayload) {
                $goodsReceiveItemId = Crypt::decryptString(
                    urldecode(
                        (string) $itemPayload['goods_receive_item_public_id'],
                    ),
                );

                $purchaseOrderItemId = Crypt::decryptString(
                    urldecode(
                        (string) $itemPayload['purchase_order_item_public_id'],
                    ),
                );

                /*
            |--------------------------------------------------------------------------
            | Pastikan item benar-benar berasal dari GR terpilih
            |--------------------------------------------------------------------------
            */
                $goodsReceiveItem = GoodsReceiveItem::query()
                    ->where(
                        'goods_receive_id',
                        $goodsReceive->id,
                    )
                    ->whereKey($goodsReceiveItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
            |--------------------------------------------------------------------------
            | Pastikan item PO sesuai dengan item GR
            |--------------------------------------------------------------------------
            */
                if (
                    (int) $goodsReceiveItem->purchase_order_item_id
                    !== (int) $purchaseOrderItemId
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.purchase_order_item_public_id" => [
                            'Item Purchase Order tidak sesuai dengan item Goods Receipt.',
                        ],
                    ]);
                }

                $purchaseOrderItem = PurchaseOrderItem::query()
                    ->whereKey($purchaseOrderItemId)
                    ->where(
                        'purchase_order_id',
                        $goodsReceive->purchase_order_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
            |--------------------------------------------------------------------------
            | Pastikan alasan retur masih aktif
            |--------------------------------------------------------------------------
            */
                $reason = GoodsReturnReason::query()
                    ->whereKey(
                        (int) $itemPayload['reason_id'],
                    )
                    ->where(
                        'is_active',
                        true,
                    )
                    ->first();

                if (!$reason) {
                    throw ValidationException::withMessages([
                        "items.{$index}.reason_id" => [
                            'Alasan retur tidak ditemukan atau sudah tidak aktif.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Hitung total qty retur yang sudah POSTED
            |--------------------------------------------------------------------------
            | Retur DRAFT tidak dihitung sebagai qty yang sudah dikembalikan.
            |--------------------------------------------------------------------------
            */
                $qtyReturnedBefore = (float) GoodsReturnItem::query()
                    ->join(
                        'goods_returns',
                        'goods_returns.id',
                        '=',
                        'goods_return_items.goods_return_id',
                    )
                    ->where(
                        'goods_return_items.goods_receive_item_id',
                        $goodsReceiveItem->id,
                    )
                    ->where(
                        'goods_returns.status',
                        GoodsReturn::STATUS_POSTED,
                    )
                    ->whereNull(
                        'goods_returns.deleted_at',
                    )
                    ->sum(
                        'goods_return_items.qty_return',
                    );

                $qtyReceived = (float) (
                    $goodsReceiveItem->qty_receive
                    ?? 0
                );

                $qtyReturn = (float) (
                    $itemPayload['qty_return']
                    ?? 0
                );

                $qtyReturnable = max(
                    $qtyReceived - $qtyReturnedBefore,
                    0,
                );

                /*
            |--------------------------------------------------------------------------
            | Validasi qty retur
            |--------------------------------------------------------------------------
            */
                if ($qtyReceived <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_return" => [
                            'Qty penerimaan item tidak valid.',
                        ],
                    ]);
                }

                if ($qtyReturn > $qtyReturnable) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_return" => [
                            'Qty retur item '
                                . ($goodsReceiveItem->nama_item ?? '-')
                                . ' melebihi qty yang masih dapat diretur. '
                                . 'Maksimal: '
                                . rtrim(
                                    rtrim(
                                        number_format(
                                            $qtyReturnable,
                                            4,
                                            ',',
                                            '.',
                                        ),
                                        '0',
                                    ),
                                    ',',
                                )
                                . '.',
                        ],
                    ]);
                }

                $qtyReturnedAfter = (
                    $qtyReturnedBefore
                    + $qtyReturn
                );

                $qtyReturnableAfter = max(
                    $qtyReceived - $qtyReturnedAfter,
                    0,
                );

                /*
            |--------------------------------------------------------------------------
            | Resolve unit
            |--------------------------------------------------------------------------
            | goods_receive_items.unit bertipe varchar tetapi menyimpan ID unit.
            | Jika unit snapshot GR kosong, gunakan purchase_order_items.satuan.
            |--------------------------------------------------------------------------
            */
                $goodsReceiveUnit = trim(
                    (string) (
                        $goodsReceiveItem->unit
                        ?? ''
                    ),
                );

                $purchaseOrderUnit = trim(
                    (string) (
                        $purchaseOrderItem->satuan
                        ?? ''
                    ),
                );

                $unitId = null;

                if (
                    $goodsReceiveUnit !== ''
                    && is_numeric($goodsReceiveUnit)
                ) {
                    $unitId = (int) $goodsReceiveUnit;
                } elseif (
                    $purchaseOrderUnit !== ''
                    && is_numeric($purchaseOrderUnit)
                ) {
                    $unitId = (int) $purchaseOrderUnit;
                }

                if ($unitId === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}.goods_receive_item_public_id" => [
                            'Unit item '
                                . ($goodsReceiveItem->nama_item ?? '-')
                                . ' tidak ditemukan pada Goods Receipt maupun Purchase Order.',
                        ],
                    ]);
                }

                GoodsReturnItem::query()->create([
                    'goods_return_id'
                    => $goodsReturn->id,

                    'goods_receive_item_id'
                    => $goodsReceiveItem->id,

                    'purchase_order_item_id'
                    => $purchaseOrderItem->id,

                    'nama_item'
                    => $goodsReceiveItem->nama_item
                        ?? $purchaseOrderItem->nama_item
                        ?? '-',

                    'unit_id'
                    => $unitId,

                    'qty_received'
                    => $qtyReceived,

                    'qty_returned_before'
                    => $qtyReturnedBefore,

                    'qty_return'
                    => $qtyReturn,

                    'qty_returned_after'
                    => $qtyReturnedAfter,

                    'qty_returnable_after'
                    => $qtyReturnableAfter,

                    'reason_id'
                    => $reason->id,

                    'reason_notes'
                    => $itemPayload['reason_notes']
                        ?? null,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Upload attachment
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('attachments')) {
                $basePath = 'syopv4/uploads/goods_return/'
                    . $goodsReturn->id;

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

                    $fileName = now()->format('YmdHis')
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

                    GoodsReturnAttachment::query()->create([
                        'goods_return_id'
                        => $goodsReturn->id,

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

            DB::commit();

            $goodsReturn->load([
                'items.reason',
                'attachments',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Draft retur barang berhasil dibuat.',

                'data' => [
                    'id'
                    => $goodsReturn->id,

                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'goods_receive_id'
                    => $goodsReturn->goods_receive_id,

                    'purchase_order_id'
                    => $goodsReturn->purchase_order_id,

                    'vendor_id'
                    => $goodsReturn->vendor_id,

                    'cabang'
                    => $goodsReturn->cabang,

                    'id_department'
                    => $goodsReturn->id_department,

                    'tanggal_return'
                    => $goodsReturn->tanggal_return,

                    'status'
                    => $goodsReturn->status,

                    'notes'
                    => $goodsReturn->notes,
                ],
            ], 201);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($storedFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,

                'message' => collect(
                    $e->errors(),
                )
                    ->flatten()
                    ->first()
                    ?? 'Data retur barang tidak valid.',

                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($storedFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Goods Receipt atau item tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($storedFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Goods Receipt atau item sumber tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($storedFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            Log::error(
                '[Goods Return] Store error',
                [
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->except([
                        'attachments',
                    ]),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat draft retur barang.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function show(
        Request $request,
        string $publicId,
    ): JsonResponse {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission view
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission(
                'goods_return.view',
            );

            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_return.view',
                    ),
                ),
            );

            $allowedScopes = [
                'ALL',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'OWN_DATA',
                'NONE',
            ];

            if (
                !in_array(
                    $viewScope,
                    $allowedScopes,
                    true,
                )
            ) {
                $viewScope = 'NONE';
            }

            if (
                !$canView
                || $viewScope === 'NONE'
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat Goods Return.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt public ID
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString(
                urldecode($publicId),
            );

            /*
        |--------------------------------------------------------------------------
        | Query detail Goods Return
        |--------------------------------------------------------------------------
        */
            $query = GoodsReturn::query()
                ->with([
                    'goodsReceive:id,nomor_gr,purchase_order_id,tanggal_gr,status',

                    'purchaseOrder:id,nomor_po,cabang,id_department,status_receive',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'department:id,kode,nama',

                    'vendor:id,nama_vendor',

                    'creator:id,name',

                    'poster:id,name',

                    'canceller:id,name',

                    'items' => function ($itemQuery) {
                        $itemQuery->orderBy('id');
                    },

                    'items.reason:id,code,name,description',

                    'items.unit:id,kode,nama',

                    'attachments' => function ($attachmentQuery) {
                        $attachmentQuery->orderBy('id');
                    },

                    'attachments.uploader:id,name',

                    'replacementGoodsReceives' => function ($replacementQuery) {
                        $replacementQuery
                            ->select([
                                'id',
                                'nomor_gr',
                                'source_goods_return_id',
                                'tanggal_gr',
                                'status',
                                'created_by',
                                'posted_by',
                                'posted_at',
                            ])
                            ->orderByDesc('id');
                    },

                    'replacementGoodsReceives.creator:id,name',

                    'replacementGoodsReceives.poster:id,name',
                ]);

            /*
        |--------------------------------------------------------------------------
        | Filter berdasarkan scope
        |--------------------------------------------------------------------------
        */
            if ($viewScope === 'OWN_DATA') {
                $query->where(
                    'goods_returns.created_by',
                    $user->id,
                );
            } elseif ($viewScope === 'OWN_DEPARTMENT') {
                $departmentId = (int) (
                    $user->departemen_id
                    ?? 0
                );

                if ($departmentId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(
                        'goods_returns.id_department',
                        $departmentId,
                    );
                }
            } elseif ($viewScope === 'OWN_CABANG') {
                $cabangId = (int) (
                    $user->cabang_id
                    ?? 0
                );

                if ($cabangId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(
                        'goods_returns.cabang',
                        $cabangId,
                    );
                }
            }

            /** @var GoodsReturn $goodsReturn */
            $goodsReturn = $query
                ->whereKey($id)
                ->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Status dan abilities
        |--------------------------------------------------------------------------
        */
            $status = strtoupper(
                trim(
                    (string) $goodsReturn->status,
                ),
            );

            $hasActiveReplacementGr = $goodsReturn
                ->replacementGoodsReceives
                ->contains(function ($replacementGr) {
                    return in_array(
                        strtoupper(
                            trim(
                                (string) $replacementGr->status,
                            ),
                        ),
                        [
                            'DRAFT',
                            'POSTED',
                        ],
                        true,
                    );
                });

            $canUpdate = (
                $user->hasPermission('goods_return.update')
                && $status === GoodsReturn::STATUS_DRAFT
            );

            $canDelete = (
                $user->hasPermission('goods_return.delete')
                && $status === GoodsReturn::STATUS_DRAFT
            );

            $canPost = (
                $user->hasPermission('goods_return.post')
                && $status === GoodsReturn::STATUS_DRAFT
            );

            $canCancel = (
                $user->hasPermission('goods_return.cancel')
                && $status === GoodsReturn::STATUS_POSTED
                && !$hasActiveReplacementGr
            );

            /*
        |--------------------------------------------------------------------------
        | Transform item
        |--------------------------------------------------------------------------
        */
            $items = $goodsReturn->items
                ->map(function ($item) {
                    $unitName = $item->unit?->nama
                        ?? $item->unit?->kode
                        ?? '-';
                    return [
                        'id'
                        => $item->id,

                        'public_id'
                        => Crypt::encryptString(
                            (string) $item->id,
                        ),

                        'goods_receive_item_public_id'
                        => Crypt::encryptString(
                            (string) $item->goods_receive_item_id,
                        ),

                        'purchase_order_item_public_id'
                        => Crypt::encryptString(
                            (string) $item->purchase_order_item_id,
                        ),

                        'nama_item'
                        => $item->nama_item,

                        'unit_id'
                        => $item->unit_id,

                        'unit_name' => $unitName,

                        'unit' => $unitName,

                        'qty_received'
                        => (float) $item->qty_received,

                        'qty_returned_before'
                        => (float) $item->qty_returned_before,

                        'qty_return'
                        => (float) $item->qty_return,

                        'qty_returned_after'
                        => (float) $item->qty_returned_after,

                        'qty_returnable_after'
                        => (float) $item->qty_returnable_after,

                        'reason_id'
                        => $item->reason_id,

                        'reason_code'
                        => $item->reason?->code,

                        'reason_name'
                        => $item->reason?->name
                            ?? '-',

                        'reason_description'
                        => $item->reason?->description,

                        'reason_notes'
                        => $item->reason_notes,
                    ];
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Transform attachment
        |--------------------------------------------------------------------------
        */
            $attachments = $goodsReturn->attachments
                ->map(function ($attachment) {
                    return [
                        'id'
                        => $attachment->id,

                        'public_id'
                        => $attachment->encrypted_id,

                        'document_type'
                        => $attachment->document_type,

                        'file_name'
                        => $attachment->file_name,

                        'file_original_name'
                        => $attachment->file_original_name,

                        'file_path'
                        => $attachment->file_path,

                        'file_url'
                        => asset('storage/' . $attachment->file_path),

                        'file_mime_type'
                        => $attachment->file_mime_type,

                        'file_size'
                        => $attachment->file_size,

                        'uploaded_by'
                        => $attachment->uploaded_by,

                        'uploaded_by_name'
                        => $attachment->uploader?->name,

                        'created_at'
                        => $attachment->created_at,
                    ];
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Transform GR replacement
        |--------------------------------------------------------------------------
        */
            $replacementGoodsReceives = $goodsReturn
                ->replacementGoodsReceives
                ->map(function ($replacementGr) {
                    return [
                        'id'
                        => $replacementGr->id,

                        'public_id'
                        => Crypt::encryptString(
                            (string) $replacementGr->id,
                        ),

                        'nomor_gr'
                        => $replacementGr->nomor_gr,

                        'tanggal_gr'
                        => $replacementGr->tanggal_gr,

                        'status'
                        => $replacementGr->status,

                        'created_by'
                        => $replacementGr->created_by,

                        'created_by_name'
                        => $replacementGr->creator?->name,

                        'posted_by'
                        => $replacementGr->posted_by,

                        'posted_by_name'
                        => $replacementGr->poster?->name,

                        'posted_at'
                        => $replacementGr->posted_at,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Detail Goods Return berhasil dimuat.',

                'data' => [
                    'id'
                    => $goodsReturn->id,

                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'tanggal_return'
                    => $goodsReturn->tanggal_return,

                    'status'
                    => $goodsReturn->status,

                    'notes'
                    => $goodsReturn->notes,

                    /*
                |--------------------------------------------------------------------------
                | GR sumber
                |--------------------------------------------------------------------------
                */
                    'goods_receive_id'
                    => $goodsReturn->goods_receive_id,

                    'goods_receive_public_id'
                    => Crypt::encryptString(
                        (string) $goodsReturn->goods_receive_id,
                    ),

                    'nomor_gr'
                    => $goodsReturn->goodsReceive?->nomor_gr
                        ?? '-',

                    'tanggal_gr'
                    => $goodsReturn->goodsReceive?->tanggal_gr,

                    /*
                |--------------------------------------------------------------------------
                | PO sumber
                |--------------------------------------------------------------------------
                */
                    'purchase_order_id'
                    => $goodsReturn->purchase_order_id,

                    'purchase_order_public_id'
                    => Crypt::encryptString(
                        (string) $goodsReturn->purchase_order_id,
                    ),

                    'nomor_po'
                    => $goodsReturn->purchaseOrder?->nomor_po
                        ?? '-',

                    'po_status_receive'
                    => $goodsReturn->purchaseOrder?->status_receive,

                    /*
                |--------------------------------------------------------------------------
                | Vendor
                |--------------------------------------------------------------------------
                */
                    'vendor_id'
                    => $goodsReturn->vendor_id,

                    'vendor'
                    => $goodsReturn->vendor?->nama_vendor
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Cabang
                |--------------------------------------------------------------------------
                */
                    'cabang_id'
                    => $goodsReturn->cabang,

                    'cabang'
                    => $goodsReturn
                        ->purchaseOrder
                        ?->cabangData
                        ?->nama_cabang
                        ?? '-',

                    'inisial_cabang'
                    => $goodsReturn
                        ->purchaseOrder
                        ?->cabangData
                        ?->inisial_cabang
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Department
                |--------------------------------------------------------------------------
                */
                    'department_id'
                    => $goodsReturn->id_department,

                    'department'
                    => $goodsReturn->department?->kode
                        ?? '-',

                    'department_name'
                    => $goodsReturn->department?->nama
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Detail
                |--------------------------------------------------------------------------
                */
                    'items'
                    => $items,

                    'total_qty'
                    => (float) $items->sum(
                        'qty_return',
                    ),

                    'attachments'
                    => $attachments,

                    /*
                |--------------------------------------------------------------------------
                | Replacement
                |--------------------------------------------------------------------------
                */
                    'replacement_goods_receives'
                    => $replacementGoodsReceives,

                    'has_replacement_gr'
                    => $hasActiveReplacementGr,

                    /*
                |--------------------------------------------------------------------------
                | Audit
                |--------------------------------------------------------------------------
                */
                    'created_by'
                    => $goodsReturn->created_by,

                    'created_by_name'
                    => $goodsReturn->creator?->name,

                    'created_at'
                    => $goodsReturn->created_at,

                    'posted_by'
                    => $goodsReturn->posted_by,

                    'posted_by_name'
                    => $goodsReturn->poster?->name,

                    'posted_at'
                    => $goodsReturn->posted_at,

                    'cancelled_by'
                    => $goodsReturn->cancelled_by,

                    'cancelled_by_name'
                    => $goodsReturn->canceller?->name,

                    'cancelled_at'
                    => $goodsReturn->cancelled_at,

                    'cancel_notes'
                    => $goodsReturn->cancel_notes,

                    /*
                |--------------------------------------------------------------------------
                | Abilities
                |--------------------------------------------------------------------------
                */
                    'can_update'
                    => $canUpdate,

                    'can_delete'
                    => $canDelete,

                    'can_post'
                    => $canPost,

                    'can_cancel'
                    => $canCancel,

                    'is_owner'
                    => (int) $goodsReturn->created_by
                        === (int) $user->id,
                ],
            ], 200);
        } catch (DecryptException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Goods Return tidak ditemukan atau tidak dapat Anda akses.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Show error',
                [
                    'public_id' => $publicId,
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Goods Return.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function edit(
        Request $request,
        string $publicId,
    ): JsonResponse {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission update
        |--------------------------------------------------------------------------
        */
            if (!$user->hasPermission('goods_return.update')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah Goods Return.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission view dan scope
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission(
                'goods_return.view',
            );

            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_return.view',
                    ),
                ),
            );

            $allowedScopes = [
                'ALL',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'OWN_DATA',
                'NONE',
            ];

            if (
                !in_array(
                    $viewScope,
                    $allowedScopes,
                    true,
                )
            ) {
                $viewScope = 'NONE';
            }

            if (
                !$canView
                || $viewScope === 'NONE'
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat Goods Return.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt public ID
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString(
                urldecode($publicId),
            );

            /*
        |--------------------------------------------------------------------------
        | Query Goods Return
        |--------------------------------------------------------------------------
        */
            $query = GoodsReturn::query()
                ->with([
                    'goodsReceive:id,nomor_gr,purchase_order_id,vendor_id,tanggal_gr,status,cabang,id_department',

                    'purchaseOrder:id,nomor_po,cabang,id_department',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'department:id,kode,nama',

                    'vendor:id,nama_vendor',

                    'items' => function ($itemQuery) {
                        $itemQuery->orderBy('id');
                    },

                    'items.goodsReceiveItem:id,goods_receive_id,purchase_order_item_id,nama_item,qty_receive',

                    'items.reason:id,code,name,description,is_active',

                    'items.unit:id,nama',

                    'attachments' => function ($attachmentQuery) {
                        $attachmentQuery->orderBy('id');
                    },
                ]);

            /*
        |--------------------------------------------------------------------------
        | Filter berdasarkan scope view
        |--------------------------------------------------------------------------
        */
            if ($viewScope === 'OWN_DATA') {
                $query->where(
                    'goods_returns.created_by',
                    $user->id,
                );
            } elseif ($viewScope === 'OWN_DEPARTMENT') {
                $departmentId = (int) (
                    $user->departemen_id
                    ?? 0
                );

                if ($departmentId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(
                        'goods_returns.id_department',
                        $departmentId,
                    );
                }
            } elseif ($viewScope === 'OWN_CABANG') {
                $cabangId = (int) (
                    $user->cabang_id
                    ?? 0
                );

                if ($cabangId <= 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(
                        'goods_returns.cabang',
                        $cabangId,
                    );
                }
            }

            /** @var GoodsReturn $goodsReturn */
            $goodsReturn = $query
                ->whereKey($id)
                ->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Hanya DRAFT yang dapat diedit
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $goodsReturn->status),
                ) !== GoodsReturn::STATUS_DRAFT
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Goods Return hanya dapat diubah jika status masih DRAFT.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil ID item GR sumber
        |--------------------------------------------------------------------------
        */
            $goodsReceiveItemIds = $goodsReturn
                ->items
                ->pluck('goods_receive_item_id')
                ->filter()
                ->map(
                    fn($itemId): int => (int) $itemId,
                )
                ->unique()
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Total retur POSTED per item GR
        |--------------------------------------------------------------------------
        | Goods Return saat ini masih DRAFT, sehingga tidak ikut dihitung.
        |--------------------------------------------------------------------------
        */
            $postedReturnQuantities = collect();

            if ($goodsReceiveItemIds->isNotEmpty()) {
                $postedReturnQuantities = GoodsReturnItem::query()
                    ->join(
                        'goods_returns as return_header',
                        'return_header.id',
                        '=',
                        'goods_return_items.goods_return_id',
                    )
                    ->whereIn(
                        'goods_return_items.goods_receive_item_id',
                        $goodsReceiveItemIds->all(),
                    )
                    ->where(
                        'return_header.status',
                        GoodsReturn::STATUS_POSTED,
                    )
                    ->whereNull(
                        'return_header.deleted_at',
                    )
                    ->groupBy(
                        'goods_return_items.goods_receive_item_id',
                    )
                    ->selectRaw(
                        '
                    goods_return_items.goods_receive_item_id,
                    SUM(goods_return_items.qty_return) AS qty_returned
                    ',
                    )
                    ->pluck(
                        'qty_returned',
                        'goods_receive_item_id',
                    );
            }

            /*
        |--------------------------------------------------------------------------
        | Transform item edit
        |--------------------------------------------------------------------------
        */
            $items = $goodsReturn->items
                ->map(function ($item) use (
                    $postedReturnQuantities,
                ) {
                    $qtyReceived = (float) (
                        $item->goodsReceiveItem?->qty_receive
                        ?? $item->qty_received
                        ?? 0
                    );

                    $qtyReturnedPosted = (float) (
                        $postedReturnQuantities->get(
                            $item->goods_receive_item_id,
                            0,
                        )
                    );

                    $qtyReturnable = max(
                        $qtyReceived - $qtyReturnedPosted,
                        0,
                    );

                    $qtyReturn = (float) (
                        $item->qty_return
                        ?? 0
                    );

                    return [
                        'public_id'
                        => Crypt::encryptString(
                            (string) $item->id,
                        ),

                        'goods_receive_item_public_id'
                        => Crypt::encryptString(
                            (string) $item->goods_receive_item_id,
                        ),

                        'purchase_order_item_public_id'
                        => Crypt::encryptString(
                            (string) $item->purchase_order_item_id,
                        ),

                        'nama_item'
                        => $item->nama_item,

                        'unit_id'
                        => $item->unit_id,

                        'unit_name'
                        => $item->unit?->nama
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Informasi qty terbaru
                    |--------------------------------------------------------------------------
                    */
                        'qty_received'
                        => $qtyReceived,

                        'qty_returned_before'
                        => $qtyReturnedPosted,

                        'qty_returnable'
                        => $qtyReturnable,

                        'qty_return'
                        => $qtyReturn,

                        /*
                    |--------------------------------------------------------------------------
                    | Indikator jika draft lama sudah tidak valid
                    |--------------------------------------------------------------------------
                    | Bisa terjadi jika retur lain diposting setelah draft ini dibuat.
                    |--------------------------------------------------------------------------
                    */
                        'qty_return_is_valid'
                        => $qtyReturn > 0
                            && $qtyReturn
                            <= ($qtyReturnable + 0.0001),

                        /*
                    |--------------------------------------------------------------------------
                    | Alasan retur
                    |--------------------------------------------------------------------------
                    */
                        'reason_id'
                        => $item->reason_id,

                        'reason_code'
                        => $item->reason?->code,

                        'reason_name'
                        => $item->reason?->name,

                        'reason_notes'
                        => $item->reason_notes,
                    ];
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Master alasan retur
        |--------------------------------------------------------------------------
        | Alasan aktif ditampilkan.
        |
        | Jika alasan yang sudah dipilih kemudian dinonaktifkan, alasan tersebut
        | tetap ikut response agar nilai lama tidak hilang dari form edit.
        |--------------------------------------------------------------------------
        */
            $selectedReasonIds = $goodsReturn
                ->items
                ->pluck('reason_id')
                ->filter()
                ->map(
                    fn($reasonId): int => (int) $reasonId,
                )
                ->unique()
                ->values()
                ->all();

            $reasons = GoodsReturnReason::query()
                ->where(function ($reasonQuery) use (
                    $selectedReasonIds,
                ) {
                    $reasonQuery->where(
                        'is_active',
                        true,
                    );

                    if (!empty($selectedReasonIds)) {
                        $reasonQuery->orWhereIn(
                            'id',
                            $selectedReasonIds,
                        );
                    }
                })
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'description',
                    'is_active',
                ])
                ->map(function (GoodsReturnReason $reason) {
                    return [
                        'id'
                        => (int) $reason->id,

                        'code'
                        => $reason->code,

                        'name'
                        => $reason->name,

                        'description'
                        => $reason->description,

                        'is_active'
                        => (bool) $reason->is_active,
                    ];
                })
                ->values();

        /*
        |--------------------------------------------------------------------------
        | Attachment
        |--------------------------------------------------------------------------
        */
            /** @var \Illuminate\Filesystem\FilesystemAdapter $publicDisk */
            $publicDisk = Storage::disk('public');

            $attachments = $goodsReturn->attachments
                ->map(function ($attachment) use (
                    $publicDisk,
                ) {
                    return [
                        'public_id'
                        => $attachment->encrypted_id,

                        'document_type'
                        => $attachment->document_type,

                        'file_name'
                        => $attachment->file_name,

                        'file_original_name'
                        => $attachment->file_original_name,

                        'file_path'
                        => $attachment->file_path,

                        'file_url'
                        => asset('storage/' . $attachment->file_path),

                        'file_mime_type'
                        => $attachment->file_mime_type,

                        'file_size'
                        => $attachment->file_size,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data edit Goods Return berhasil dimuat.',

                'data' => [
                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'tanggal_return'
                    => $goodsReturn->tanggal_return,

                    'status'
                    => $goodsReturn->status,

                    'notes'
                    => $goodsReturn->notes,

                    /*
                |--------------------------------------------------------------------------
                | GR sumber
                |--------------------------------------------------------------------------
                */
                    'goods_receive_public_id'
                    => Crypt::encryptString(
                        (string) $goodsReturn->goods_receive_id,
                    ),

                    'nomor_gr'
                    => $goodsReturn->goodsReceive?->nomor_gr
                        ?? '-',

                    'tanggal_gr'
                    => $goodsReturn->goodsReceive?->tanggal_gr,

                    /*
                |--------------------------------------------------------------------------
                | PO sumber
                |--------------------------------------------------------------------------
                */
                    'purchase_order_public_id'
                    => Crypt::encryptString(
                        (string) $goodsReturn->purchase_order_id,
                    ),

                    'nomor_po'
                    => $goodsReturn->purchaseOrder?->nomor_po
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Vendor
                |--------------------------------------------------------------------------
                */
                    'vendor_id'
                    => $goodsReturn->vendor_id,

                    'vendor'
                    => $goodsReturn->vendor?->nama_vendor
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Cabang
                |--------------------------------------------------------------------------
                */
                    'cabang_id'
                    => $goodsReturn->cabang,

                    'cabang'
                    => $goodsReturn
                        ->purchaseOrder
                        ?->cabangData
                        ?->nama_cabang
                        ?? '-',

                    'inisial_cabang'
                    => $goodsReturn
                        ->purchaseOrder
                        ?->cabangData
                        ?->inisial_cabang
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Department
                |--------------------------------------------------------------------------
                */
                    'department_id'
                    => $goodsReturn->id_department,

                    'department'
                    => $goodsReturn->department?->kode
                        ?? '-',

                    'department_name'
                    => $goodsReturn->department?->nama
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Form data
                |--------------------------------------------------------------------------
                */
                    'items'
                    => $items,

                    'attachments'
                    => $attachments,

                    'reasons'
                    => $reasons,

                    'can_update'
                    => true,
                ],
            ], 200);
        } catch (DecryptException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Goods Return tidak ditemukan atau tidak dapat Anda akses.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Edit error',
                [
                    'public_id'
                    => $publicId,

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
                'message' => 'Gagal memuat data edit Goods Return.',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function update(
        Request $request,
        string $publicId,
    ): JsonResponse {
        $newStoredFilePaths = [];
        $oldFilePathsToDelete = [];

        DB::beginTransaction();

        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_return.update')
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah Goods Return.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Normalisasi FormData
        |--------------------------------------------------------------------------
        | items dan deleted_attachment_ids dapat dikirim sebagai JSON string.
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

            if (
                $request->has('deleted_attachment_ids')
                && is_string(
                    $request->input('deleted_attachment_ids'),
                )
            ) {
                $decodedAttachmentIds = json_decode(
                    (string) $request->input(
                        'deleted_attachment_ids',
                    ),
                    true,
                );

                if (
                    json_last_error()
                    === JSON_ERROR_NONE
                ) {
                    $request->merge([
                        'deleted_attachment_ids'
                        => $decodedAttachmentIds,
                    ]);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi request
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'tanggal_return' => [
                    'required',
                    'date',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],

                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'items.*.goods_return_item_public_id' => [
                    'required',
                    'string',
                    'distinct',
                ],

                'items.*.goods_receive_item_public_id' => [
                    'required',
                    'string',
                    'distinct',
                ],

                'items.*.purchase_order_item_public_id' => [
                    'required',
                    'string',
                ],

                'items.*.qty_return' => [
                    'required',
                    'numeric',
                    'gt:0',
                ],

                'items.*.reason_id' => [
                    'required',
                    'integer',
                    'exists:goods_return_reasons,id',
                ],

                'items.*.reason_notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],

                'deleted_attachment_ids' => [
                    'nullable',
                    'array',
                ],

                'deleted_attachment_ids.*' => [
                    'string',
                ],

                'remove_all_attachments' => [
                    'nullable',
                    'boolean',
                ],

                'attachments' => [
                    'nullable',
                    'array',
                ],

                'attachments.*' => [
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:3072',
                ],
            ], [
                'tanggal_return.required'
                => 'Tanggal retur wajib diisi.',

                'items.required'
                => 'Item retur wajib diisi.',

                'items.min'
                => 'Minimal harus ada satu item retur.',

                'items.*.qty_return.required'
                => 'Qty retur wajib diisi.',

                'items.*.qty_return.gt'
                => 'Qty retur harus lebih besar dari nol.',

                'items.*.reason_id.required'
                => 'Alasan retur wajib dipilih.',
            ]);

            /*
        |--------------------------------------------------------------------------
        | Decrypt dan lock Goods Return
        |--------------------------------------------------------------------------
        */
            $goodsReturnId = Crypt::decryptString(
                urldecode($publicId),
            );

            /** @var GoodsReturn $goodsReturn */
            $goodsReturn = GoodsReturn::query()
                ->with([
                    'items',
                    'attachments',
                ])
                ->lockForUpdate()
                ->findOrFail($goodsReturnId);

            /*
        |--------------------------------------------------------------------------
        | Hanya DRAFT yang dapat diubah
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $goodsReturn->status),
                ) !== GoodsReturn::STATUS_DRAFT
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Return hanya dapat diubah jika status masih DRAFT.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi scope akses
        |--------------------------------------------------------------------------
        */
            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_return.view',
                    ),
                ),
            );

            $canAccessRow = match ($viewScope) {
                'ALL' => true,

                'OWN_DATA' =>
                (int) $goodsReturn->created_by
                    === (int) $user->id,

                'OWN_DEPARTMENT' =>
                (int) $goodsReturn->id_department
                    === (int) ($user->departemen_id ?? 0),

                'OWN_CABANG' =>
                (int) $goodsReturn->cabang
                    === (int) ($user->cabang_id ?? 0),

                default => false,
            };

            if (!$canAccessRow) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Return tidak ditemukan atau tidak dapat Anda akses.',
                ], 404);
            }

            /*
        |--------------------------------------------------------------------------
        | Lock GR sumber
        |--------------------------------------------------------------------------
        */
            $sourceGoodsReceive = GoodsReceive::query()
                ->whereKey(
                    $goodsReturn->goods_receive_id,
                )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                strtoupper(
                    trim((string) $sourceGoodsReceive->status),
                ) !== 'POSTED'
            ) {
                throw ValidationException::withMessages([
                    'goods_receive_id' => [
                        'Goods Receipt sumber harus berstatus POSTED.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Update header
        |--------------------------------------------------------------------------
        */
            $goodsReturn->tanggal_return
                = $validated['tanggal_return'];

            $goodsReturn->notes
                = $validated['notes'] ?? null;

            $goodsReturn->save();

            /*
        |--------------------------------------------------------------------------
        | Sinkronisasi item retur
        |--------------------------------------------------------------------------
        */
            $submittedGoodsReturnItemIds = [];

            foreach (
                $validated['items'] as $index => $itemPayload
            ) {
                $goodsReturnItemId = Crypt::decryptString(
                    urldecode(
                        (string) $itemPayload['goods_return_item_public_id'],
                    ),
                );

                $goodsReceiveItemId = Crypt::decryptString(
                    urldecode(
                        (string) $itemPayload['goods_receive_item_public_id'],
                    ),
                );

                $purchaseOrderItemId = Crypt::decryptString(
                    urldecode(
                        (string) $itemPayload['purchase_order_item_public_id'],
                    ),
                );

            /*
            |--------------------------------------------------------------------------
            | Lock detail retur
            |--------------------------------------------------------------------------
            */
                /** @var GoodsReturnItem $goodsReturnItem */
                $goodsReturnItem = GoodsReturnItem::query()
                    ->where(
                        'goods_return_id',
                        $goodsReturn->id,
                    )
                    ->whereKey(
                        $goodsReturnItemId,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
            |--------------------------------------------------------------------------
            | Pastikan referensi item tidak dimanipulasi
            |--------------------------------------------------------------------------
            */
                if (
                    (int) $goodsReturnItem->goods_receive_item_id
                    !== (int) $goodsReceiveItemId
                    || (int) $goodsReturnItem->purchase_order_item_id
                    !== (int) $purchaseOrderItemId
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => [
                            'Referensi item retur tidak sesuai.',
                        ],
                    ]);
                }

            /*
            |--------------------------------------------------------------------------
            | Lock item GR sumber
            |--------------------------------------------------------------------------
            */
                /** @var GoodsReceiveItem $goodsReceiveItem */
                $goodsReceiveItem = GoodsReceiveItem::query()
                    ->whereKey(
                        $goodsReceiveItemId,
                    )
                    ->where(
                        'goods_receive_id',
                        $sourceGoodsReceive->id,
                    )
                    ->where(
                        'purchase_order_item_id',
                        $purchaseOrderItemId,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
            |--------------------------------------------------------------------------
            | Validasi alasan retur
            |--------------------------------------------------------------------------
            | Alasan nonaktif masih boleh dipertahankan apabila memang sudah
            | digunakan pada item ini sebelumnya.
            |--------------------------------------------------------------------------
            */
                $reasonId = (int) $itemPayload['reason_id'];

                $reason = GoodsReturnReason::query()
                    ->whereKey($reasonId)
                    ->where(function ($reasonQuery) use (
                        $goodsReturnItem,
                    ) {
                        $reasonQuery
                            ->where('is_active', true)
                            ->orWhere(
                                'id',
                                $goodsReturnItem->reason_id,
                            );
                    })
                    ->first();

                if (!$reason) {
                    throw ValidationException::withMessages([
                        "items.{$index}.reason_id" => [
                            'Alasan retur tidak ditemukan atau sudah tidak aktif.',
                        ],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | Hitung total retur POSTED
            |--------------------------------------------------------------------------
            */
                $qtyReturnedBefore = (float) GoodsReturnItem::query()
                    ->join(
                        'goods_returns as return_header',
                        'return_header.id',
                        '=',
                        'goods_return_items.goods_return_id',
                    )
                    ->where(
                        'goods_return_items.goods_receive_item_id',
                        $goodsReceiveItem->id,
                    )
                    ->where(
                        'return_header.status',
                        GoodsReturn::STATUS_POSTED,
                    )
                    ->whereNull(
                        'return_header.deleted_at',
                    )
                    ->sum(
                        'goods_return_items.qty_return',
                    );

                $qtyReceived = (float) (
                    $goodsReceiveItem->qty_receive
                    ?? 0
                );

                $qtyReturn = (float) (
                    $itemPayload['qty_return']
                    ?? 0
                );

                $qtyReturnable = max(
                    $qtyReceived - $qtyReturnedBefore,
                    0,
                );

                if ($qtyReturn <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_return" => [
                            'Qty retur harus lebih besar dari nol.',
                        ],
                    ]);
                }

                if (
                    $qtyReturn
                    > ($qtyReturnable + 0.0001)
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty_return" => [
                            'Qty retur item '
                                . ($goodsReceiveItem->nama_item ?? '-')
                                . ' melebihi qty yang masih dapat diretur. '
                                . 'Maksimal '
                                . rtrim(
                                    rtrim(
                                        number_format(
                                            $qtyReturnable,
                                            4,
                                            ',',
                                            '.',
                                        ),
                                        '0',
                                    ),
                                    ',',
                                )
                                . '.',
                        ],
                    ]);
                }

                $qtyReturnedAfter = (
                    $qtyReturnedBefore
                    + $qtyReturn
                );

                $qtyReturnableAfter = max(
                    $qtyReceived
                        - $qtyReturnedAfter,
                    0,
                );

                /*
            |--------------------------------------------------------------------------
            | Update item
            |--------------------------------------------------------------------------
            */
                $goodsReturnItem->update([
                    'qty_received'
                    => $qtyReceived,

                    'qty_returned_before'
                    => $qtyReturnedBefore,

                    'qty_return'
                    => $qtyReturn,

                    'qty_returned_after'
                    => $qtyReturnedAfter,

                    'qty_returnable_after'
                    => $qtyReturnableAfter,

                    'reason_id'
                    => $reason->id,

                    'reason_notes'
                    => $itemPayload['reason_notes']
                        ?? null,
                ]);

                $submittedGoodsReturnItemIds[]
                    = $goodsReturnItem->id;
            }

            /*
        |--------------------------------------------------------------------------
        | Hapus item yang tidak lagi dikirim frontend
        |--------------------------------------------------------------------------
        */
            GoodsReturnItem::query()
                ->where(
                    'goods_return_id',
                    $goodsReturn->id,
                )
                ->whereNotIn(
                    'id',
                    $submittedGoodsReturnItemIds,
                )
                ->delete();

            /*
        |--------------------------------------------------------------------------
        | Hapus attachment lama
        |--------------------------------------------------------------------------
        | File fisik baru dihapus setelah transaksi database berhasil commit.
        |--------------------------------------------------------------------------
        */
            $removeAllAttachments = filter_var(
                $request->input(
                    'remove_all_attachments',
                    false,
                ),
                FILTER_VALIDATE_BOOLEAN,
            );

            $deletedAttachmentIds = collect(
                $validated['deleted_attachment_ids']
                    ?? [],
            )
                ->map(function ($encryptedId) {
                    try {
                        return (int) Crypt::decryptString(
                            urldecode(
                                (string) $encryptedId,
                            ),
                        );
                    } catch (\Throwable) {
                        return null;
                    }
                })
                ->filter(
                    fn($id): bool => (int) $id > 0,
                )
                ->values();

            if ($removeAllAttachments) {
                $attachmentsToDelete
                    = GoodsReturnAttachment::query()
                    ->where(
                        'goods_return_id',
                        $goodsReturn->id,
                    )
                    ->get();
            } elseif ($deletedAttachmentIds->isNotEmpty()) {
                $attachmentsToDelete
                    = GoodsReturnAttachment::query()
                    ->where(
                        'goods_return_id',
                        $goodsReturn->id,
                    )
                    ->whereIn(
                        'id',
                        $deletedAttachmentIds->all(),
                    )
                    ->get();
            } else {
                $attachmentsToDelete = collect();
            }

            foreach (
                $attachmentsToDelete as $attachment
            ) {
                if (!empty($attachment->file_path)) {
                    $oldFilePathsToDelete[]
                        = $attachment->file_path;
                }

                $attachment->delete();
            }

            /*
        |--------------------------------------------------------------------------
        | Upload attachment baru
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('attachments')) {
                $basePath =
                    'syopv4/uploads/goods_return/'
                    . $goodsReturn->id;

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

                    $newStoredFilePaths[] = $filePath;

                    GoodsReturnAttachment::query()
                        ->create([
                            'goods_return_id'
                            => $goodsReturn->id,

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

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Hapus file lama setelah commit
        |--------------------------------------------------------------------------
        */
            foreach ($oldFilePathsToDelete as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            $goodsReturn->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Draft Goods Return berhasil diperbarui.',

                'data' => [
                    'id'
                    => $goodsReturn->id,

                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'tanggal_return'
                    => $goodsReturn->tanggal_return,

                    'status'
                    => $goodsReturn->status,

                    'notes'
                    => $goodsReturn->notes,
                ],
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($newStoredFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,

                'message' => collect(
                    $e->errors(),
                )
                    ->flatten()
                    ->first()
                    ?? 'Data Goods Return tidak valid.',

                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($newStoredFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return atau item tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($newStoredFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Goods Return atau item sumber tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($newStoredFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            Log::error(
                '[Goods Return] Update error',
                [
                    'public_id'
                    => $publicId,

                    'user_id'
                    => $request->user()?->id,

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
                'message' => 'Gagal memperbarui Goods Return.',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function destroy(
        Request $request,
        string $publicId,
    ): JsonResponse {
        $attachmentFilePaths = [];

        DB::beginTransaction();

        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission delete
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_return.delete')
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus Goods Return.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt public ID
        |--------------------------------------------------------------------------
        */
            $goodsReturnId = Crypt::decryptString(
                urldecode($publicId),
            );

        /*
        |--------------------------------------------------------------------------
        | Lock Goods Return
        |--------------------------------------------------------------------------
        */
            /** @var GoodsReturn $goodsReturn */
            $goodsReturn = GoodsReturn::query()
                ->with([
                    'items',
                    'attachments',
                ])
                ->lockForUpdate()
                ->findOrFail($goodsReturnId);

            /*
        |--------------------------------------------------------------------------
        | Validasi scope akses
        |--------------------------------------------------------------------------
        */
            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'goods_return.view',
                    ),
                ),
            );

            $canAccessRow = match ($viewScope) {
                'ALL' => true,

                'OWN_DATA' =>
                (int) $goodsReturn->created_by
                    === (int) $user->id,

                'OWN_DEPARTMENT' =>
                (int) $goodsReturn->id_department
                    === (int) ($user->departemen_id ?? 0),

                'OWN_CABANG' =>
                (int) $goodsReturn->cabang
                    === (int) ($user->cabang_id ?? 0),

                default => false,
            };

            if (!$canAccessRow) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Return tidak ditemukan atau tidak dapat Anda akses.',
                ], 404);
            }

            /*
        |--------------------------------------------------------------------------
        | Hanya DRAFT yang dapat dihapus
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $goodsReturn->status),
                ) !== GoodsReturn::STATUS_DRAFT
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Return hanya dapat dihapus jika status masih DRAFT.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Simpan lokasi file sebelum data attachment dihapus
        |--------------------------------------------------------------------------
        */
            $attachmentFilePaths = $goodsReturn
                ->attachments
                ->pluck('file_path')
                ->filter()
                ->values()
                ->all();

            /*
        |--------------------------------------------------------------------------
        | Hapus detail item
        |--------------------------------------------------------------------------
        | Header memakai soft delete sehingga cascade database tidak otomatis
        | berjalan. Detail dihapus secara eksplisit.
        |--------------------------------------------------------------------------
        */
            $goodsReturn->items()->delete();

            /*
        |--------------------------------------------------------------------------
        | Hapus data attachment
        |--------------------------------------------------------------------------
        */
            $goodsReturn->attachments()->delete();

            /*
        |--------------------------------------------------------------------------
        | Soft delete header Goods Return
        |--------------------------------------------------------------------------
        */
            $goodsReturn->delete();

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Hapus file fisik setelah transaksi berhasil
        |--------------------------------------------------------------------------
        */
            foreach ($attachmentFilePaths as $filePath) {
                if (
                    Storage::disk('public')
                    ->exists($filePath)
                ) {
                    Storage::disk('public')
                        ->delete($filePath);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Draft Goods Return berhasil dihapus.',
            ], 200);
        } catch (DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Goods Return tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Goods Return] Delete error',
                [
                    'public_id' => $publicId,
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Goods Return.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function post(
        Request $request,
        string $publicId,
        GoodsReturnPostingService $postingService,
    ): JsonResponse {
        try {
            $user = $request->user();

            if (
                !$user
                || !$user->hasPermission('goods_return.post')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk memposting retur barang.',
                ], 403);
            }

            $id = Crypt::decryptString(
                urldecode($publicId),
            );

            $goodsReturn = GoodsReturn::query()
                ->findOrFail($id);

            $postingService->post(
                $goodsReturn,
                $user,
            );

            $goodsReturn->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Goods Return berhasil diposting.',

                'data' => [
                    'id' => $goodsReturn->id,

                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'status'
                    => $goodsReturn->status,

                    'posted_at'
                    => $goodsReturn->posted_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,

                'message' => collect(
                    $e->errors(),
                )
                    ->flatten()
                    ->first()
                    ?? 'Goods Return tidak dapat diposting.',

                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Goods Return atau data sumber tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Post error',
                [
                    'public_id' => $publicId,
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memposting Goods Return.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function cancel(
        Request $request,
        string $publicId,
        GoodsReturnCancellationService $cancellationService,
    ): JsonResponse {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_return.cancel')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk membatalkan retur barang.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi alasan pembatalan
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'cancel_notes' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ], [
                'cancel_notes.required'
                => 'Alasan pembatalan wajib diisi.',

                'cancel_notes.string'
                => 'Alasan pembatalan harus berupa teks.',

                'cancel_notes.max'
                => 'Alasan pembatalan maksimal 2000 karakter.',
            ]);

            /*
        |--------------------------------------------------------------------------
        | Decrypt Goods Return
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString(
                urldecode($publicId),
            );

            $goodsReturn = GoodsReturn::query()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Jalankan cancellation service
        |--------------------------------------------------------------------------
        */
            $cancellationService->cancel(
                goodsReturn: $goodsReturn,
                user: $user,
                notes: trim(
                    (string) $validated['cancel_notes'],
                ),
            );

            $goodsReturn->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Goods Return berhasil dibatalkan.',

                'data' => [
                    'id'
                    => $goodsReturn->id,

                    'public_id'
                    => $goodsReturn->encrypted_id,

                    'nomor_return'
                    => $goodsReturn->nomor_return,

                    'status'
                    => $goodsReturn->status,

                    'cancelled_by'
                    => $goodsReturn->cancelled_by,

                    'cancelled_at'
                    => $goodsReturn->cancelled_at,

                    'cancel_notes'
                    => $goodsReturn->cancel_notes,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,

                'message' => collect(
                    $e->errors(),
                )
                    ->flatten()
                    ->first()
                    ?? 'Goods Return tidak dapat dibatalkan.',

                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ID Goods Return tidak valid.',
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Goods Return atau data sumber tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Cancel error',
                [
                    'public_id'
                    => $publicId,

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
                'message' => 'Gagal membatalkan Goods Return.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function replacementReceivable(
        Request $request,
    ): JsonResponse {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission Goods Receipt
        |--------------------------------------------------------------------------
        | Endpoint ini digunakan ketika membuat GR replacement.
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission('goods_receive.create')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk membuat Goods Receipt.',
                    'data' => [],
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Department user
        |--------------------------------------------------------------------------
        */
            $departmentId = (int) (
                $user->departemen_id
                ?? 0
            );

            if ($departmentId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Department akun Anda belum tersedia.',
                    'data' => [],
                    'errors' => [
                        'department_id' => [
                            'Department akun login tidak ditemukan. Silakan hubungi administrator.',
                        ],
                    ],
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Goods Return yang dapat menerima replacement
        |--------------------------------------------------------------------------
        */
            $goodsReturns = GoodsReturn::query()
                ->with([
                    'goodsReceive:id,nomor_gr,tanggal_gr,status',

                    'purchaseOrder:id,nomor_po,tanggal_po,cabang,id_department,vendor_id,status_receive',

                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',

                    'department:id,kode,nama',

                    'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                    'items' => function ($itemQuery) {
                        $itemQuery
                            ->with([
                                'unit:id,kode,nama',

                                'reason:id,code,name',
                            ])
                            ->orderBy('id');
                    },
                ])

                /*
            |--------------------------------------------------------------------------
            | Hanya Goods Return POSTED
            |--------------------------------------------------------------------------
            */
                ->whereRaw(
                    'UPPER(TRIM(goods_returns.status)) = ?',
                    [
                        GoodsReturn::STATUS_POSTED,
                    ],
                )

                /*
            |--------------------------------------------------------------------------
            | Department penerima
            |--------------------------------------------------------------------------
            */
                ->where(
                    'goods_returns.id_department',
                    $departmentId,
                )

                ->orderByDesc(
                    'goods_returns.id',
                )
                ->get();

            if ($goodsReturns->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tidak ada Goods Return yang membutuhkan replacement.',
                    'data' => [],
                ], 200);
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil seluruh ID Goods Return
        |--------------------------------------------------------------------------
        */
            $goodsReturnIds = $goodsReturns
                ->pluck('id')
                ->map(
                    fn($id): int => (int) $id,
                )
                ->all();

            /*
        |--------------------------------------------------------------------------
        | Rekap GR replacement DRAFT dan POSTED
        |--------------------------------------------------------------------------
        | Key:
        | source_goods_return_id + purchase_order_item_id
        |--------------------------------------------------------------------------
        */
            $replacementQuantities = DB::table(
                'goods_receive_items as replacement_item',
            )
                ->join(
                    'goods_receives as replacement_gr',
                    'replacement_gr.id',
                    '=',
                    'replacement_item.goods_receive_id',
                )
                ->whereIn(
                    'replacement_gr.source_goods_return_id',
                    $goodsReturnIds,
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
                ->groupBy(
                    'replacement_gr.source_goods_return_id',
                    'replacement_item.purchase_order_item_id',
                )
                ->selectRaw(
                    "
                replacement_gr.source_goods_return_id AS goods_return_id,
                replacement_item.purchase_order_item_id,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(replacement_gr.status)) = 'DRAFT'
                        THEN replacement_item.qty_receive
                        ELSE 0
                    END
                ) AS qty_replacement_draft,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(replacement_gr.status)) = 'POSTED'
                        THEN replacement_item.qty_receive
                        ELSE 0
                    END
                ) AS qty_replacement_posted,

                SUM(replacement_item.qty_receive)
                    AS qty_replacement_total
                ",
                )
                ->get()
                ->keyBy(function ($row) {
                    return (int) $row->goods_return_id
                        . ':'
                        . (int) $row->purchase_order_item_id;
                });

            /*
        |--------------------------------------------------------------------------
        | Transform response
        |--------------------------------------------------------------------------
        */
            $data = $goodsReturns
                ->map(function (GoodsReturn $goodsReturn) use (
                    $replacementQuantities,
                ) {
                    $items = $goodsReturn->items
                        ->map(function ($item) use (
                            $goodsReturn,
                            $replacementQuantities,
                        ) {
                            $replacementKey =
                                (int) $goodsReturn->id
                                . ':'
                                . (int) $item->purchase_order_item_id;

                            $replacementSummary = $replacementQuantities
                                ->get($replacementKey);

                            $qtyReturn = (float) (
                                $item->qty_return
                                ?? 0
                            );

                            $qtyReplacementDraft = (float) (
                                $replacementSummary
                                ?->qty_replacement_draft
                                ?? 0
                            );

                            $qtyReplacementPosted = (float) (
                                $replacementSummary
                                ?->qty_replacement_posted
                                ?? 0
                            );

                            $qtyReplacementTotal = (
                                $qtyReplacementDraft
                                + $qtyReplacementPosted
                            );

                            $qtyReplacementOutstanding = max(
                                $qtyReturn
                                    - $qtyReplacementTotal,
                                0,
                            );

                            return [
                                'goods_return_item_public_id'
                                => Crypt::encryptString(
                                    (string) $item->id,
                                ),

                                'purchase_order_item_public_id'
                                => Crypt::encryptString(
                                    (string) $item->purchase_order_item_id,
                                ),

                                'goods_receive_item_public_id'
                                => Crypt::encryptString(
                                    (string) $item->goods_receive_item_id,
                                ),

                                'nama_item'
                                => $item->nama_item,

                                'unit_id'
                                => $item->unit_id,

                                'unit'
                                => $item->unit?->nama
                                    ?? $item->unit?->kode
                                    ?? '-',

                                /*
                            |--------------------------------------------------------------------------
                            | Qty return
                            |--------------------------------------------------------------------------
                            */
                                'qty_return'
                                => $qtyReturn,

                                /*
                            |--------------------------------------------------------------------------
                            | Qty replacement
                            |--------------------------------------------------------------------------
                            */
                                'qty_replacement_draft'
                                => $qtyReplacementDraft,

                                'qty_replacement_posted'
                                => $qtyReplacementPosted,

                                'qty_replacement_total'
                                => $qtyReplacementTotal,

                                'qty_replacement_outstanding'
                                => $qtyReplacementOutstanding,

                                /*
                            |--------------------------------------------------------------------------
                            | Nilai awal form GR replacement
                            |--------------------------------------------------------------------------
                            */
                                'qty_receive'
                                => null,

                                /*
                            |--------------------------------------------------------------------------
                            | Alasan retur
                            |--------------------------------------------------------------------------
                            */
                                'reason_id'
                                => $item->reason_id,

                                'reason_code'
                                => $item->reason?->code,

                                'reason_name'
                                => $item->reason?->name,

                                'reason_notes'
                                => $item->reason_notes,
                            ];
                        })

                        /*
                    |--------------------------------------------------------------------------
                    | Hanya item yang belum selesai diganti
                    |--------------------------------------------------------------------------
                    */
                        ->filter(function ($item) {
                            return (
                                (float) $item['qty_replacement_outstanding']
                                > 0.0001
                            );
                        })
                        ->values();

                    $encryptedReturnId = Crypt::encryptString(
                        (string) $goodsReturn->id,
                    );

                    return [
                        'id'
                        => $encryptedReturnId,

                        'public_id'
                        => $encryptedReturnId,

                        'nomor_return'
                        => $goodsReturn->nomor_return,

                        'tanggal_return'
                        => $goodsReturn->tanggal_return,

                        'status'
                        => $goodsReturn->status,

                        'notes'
                        => $goodsReturn->notes,

                        /*
                    |--------------------------------------------------------------------------
                    | GR sumber retur
                    |--------------------------------------------------------------------------
                    */
                        'goods_receive_id'
                        => $goodsReturn->goods_receive_id,

                        'goods_receive_public_id'
                        => Crypt::encryptString(
                            (string) $goodsReturn->goods_receive_id,
                        ),

                        'nomor_gr_sumber'
                        => $goodsReturn->goodsReceive?->nomor_gr
                            ?? '-',

                        'tanggal_gr_sumber'
                        => $goodsReturn->goodsReceive?->tanggal_gr,

                        /*
                    |--------------------------------------------------------------------------
                    | Purchase Order
                    |--------------------------------------------------------------------------
                    */
                        'purchase_order_id'
                        => $goodsReturn->purchase_order_id,

                        'purchase_order_public_id'
                        => Crypt::encryptString(
                            (string) $goodsReturn->purchase_order_id,
                        ),

                        'nomor_po'
                        => $goodsReturn->purchaseOrder?->nomor_po
                            ?? '-',

                        'tanggal_po'
                        => $goodsReturn->purchaseOrder?->tanggal_po,

                        'status_receive'
                        => $goodsReturn->purchaseOrder?->status_receive,

                        /*
                    |--------------------------------------------------------------------------
                    | Cabang
                    |--------------------------------------------------------------------------
                    */
                        'cabang_id'
                        => $goodsReturn->cabang,

                        'cabang'
                        => $goodsReturn
                            ->purchaseOrder
                            ?->cabangData
                            ?->inisial_cabang
                            ?? '-',

                        'nama_cabang'
                        => $goodsReturn
                            ->purchaseOrder
                            ?->cabangData
                            ?->nama_cabang
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Department
                    |--------------------------------------------------------------------------
                    */
                        'department_id'
                        => $goodsReturn->id_department,

                        'department'
                        => $goodsReturn->department?->kode
                            ?? '-',

                        'department_name'
                        => $goodsReturn->department?->nama
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Vendor
                    |--------------------------------------------------------------------------
                    */
                        'vendor_id'
                        => $goodsReturn->vendor_id,

                        'vendor'
                        => $goodsReturn->vendor
                            ? [
                                'id'
                                => $goodsReturn->vendor->id,

                                'nama_vendor'
                                => $goodsReturn
                                    ->vendor
                                    ->nama_vendor
                                    ?? '-',

                                'status_pkp'
                                => $goodsReturn
                                    ->vendor
                                    ->status_pkp
                                    ?? 'NON_PKP',

                                'jenis_pembayaran'
                                => $goodsReturn
                                    ->vendor
                                    ->jenis_pembayaran,

                                'top'
                                => $goodsReturn
                                    ->vendor
                                    ->top,
                            ]
                            : null,

                        'items'
                        => $items,

                        'total_qty_replacement_outstanding'
                        => (float) $items->sum(
                            'qty_replacement_outstanding',
                        ),
                    ];
                })

                /*
            |--------------------------------------------------------------------------
            | Hanya Goods Return yang masih memiliki outstanding replacement
            |--------------------------------------------------------------------------
            */
                ->filter(function ($goodsReturn) {
                    return $goodsReturn['items']->count() > 0;
                })
                ->values();

            return response()->json([
                'success' => true,

                'message'
                => 'Goods Return replacement berhasil dimuat.',

                'data'
                => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Goods Return] Replacement receivable error',
                [
                    'user_id'
                    => $request->user()?->id,

                    'department_id'
                    => $request->user()?->departemen_id,

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
                => 'Gagal memuat Goods Return replacement.',

                'data' => [],

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function generateDraftGoodsReturnNumber(): string
    {
        $year = now()->format('Y');

        $lastGoodsReturn = GoodsReturn::withTrashed()
            ->whereYear(
                'created_at',
                $year,
            )
            ->where(
                'nomor_return',
                'ILIKE',
                "DRAFT/RETURN/{$year}/%",
            )
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;

        if ($lastGoodsReturn) {
            $lastNumber = (int) substr(
                (string) $lastGoodsReturn->nomor_return,
                -4,
            );

            $nextNumber = $lastNumber + 1;
        }

        return 'DRAFT/RETURN/'
            . $year
            . '/'
            . str_pad(
                (string) $nextNumber,
                4,
                '0',
                STR_PAD_LEFT,
            );
    }
}
