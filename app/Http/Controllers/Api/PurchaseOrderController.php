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
use App\Services\NonTrade\PurchaseOrder\PurchaseOrderApprovalGeneratorService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    protected PurchaseOrderNotificationService $poNotificationService;
    protected PurchaseOrderMailService $poMailService;
    protected PurchaseOrderRollbackService $poRollbackService;
    protected PurchaseOrderApprovalService $poApprovalService;
    protected PurchaseOrderApprovalGeneratorService $poApprovalGeneratorService;

    public function __construct(
        PurchaseOrderNotificationService $poNotificationService,
        PurchaseOrderMailService $poMailService,
        PurchaseOrderRollbackService $poRollbackService,
        PurchaseOrderApprovalService $poApprovalService,
        PurchaseOrderApprovalGeneratorService $poApprovalGeneratorService,
    ) {
        $this->poNotificationService = $poNotificationService;
        $this->poMailService = $poMailService;
        $this->poRollbackService = $poRollbackService;
        $this->poApprovalService = $poApprovalService;
        $this->poApprovalGeneratorService = $poApprovalGeneratorService;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission Purchase Order
        |--------------------------------------------------------------------------
        | Sesuaikan slug permission ini dengan master permission project.
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission(
                'purchase_order.view',
            );

            $viewScope = strtoupper(
                trim(
                    (string) $user->getPermissionScope(
                        'purchase_order.view',
                    ),
                ),
            );

            $canCreate = $user->hasPermission(
                'purchase_order.create',
            );

            $canUpdate = $user->hasPermission(
                'purchase_order.update',
            );

            $canSubmit = $user->hasPermission(
                'purchase_order.submit',
            );

            $canDelete = $user->hasPermission(
                'purchase_order.delete',
            );

            /*
        |--------------------------------------------------------------------------
        | Normalisasi permission scope
        |--------------------------------------------------------------------------
        */
            $allowedScopes = [
                'NONE',
                'OWN_DATA',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'ALL',
            ];

            if (!in_array($viewScope, $allowedScopes, true)) {
                $viewScope = 'NONE';
            }

            /*
        |--------------------------------------------------------------------------
        | Department dan cabang user login
        |--------------------------------------------------------------------------
        */
            $userDepartmentId = (int) (
                $user->departemen_id
                ?? 0
            );

            $userCabangId = (int) (
                $user->cabang_id
                ?? 0
            );

            /*
        |--------------------------------------------------------------------------
        | Ambil seluruh role user
        |--------------------------------------------------------------------------
        */
            $userRoleIds = collect();

            /*
        |--------------------------------------------------------------------------
        | Compatibility: users.role_id
        |--------------------------------------------------------------------------
        */
            if (
                isset($user->role_id)
                && $user->role_id
            ) {
                $userRoleIds->push(
                    (int) $user->role_id,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Compatibility: role_user
        |--------------------------------------------------------------------------
        */
            if (Schema::hasTable('role_user')) {
                $pivotRoleIds = DB::table('role_user')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(
                        fn($id) => (int) $id,
                    );

                $userRoleIds = $userRoleIds
                    ->merge($pivotRoleIds);
            }

            /*
        |--------------------------------------------------------------------------
        | Struktur utama: user_roles
        |--------------------------------------------------------------------------
        */
            if (Schema::hasTable('user_roles')) {
                $pivotRoleIds = DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->pluck('role_id')
                    ->map(
                        fn($id) => (int) $id,
                    );

                $userRoleIds = $userRoleIds
                    ->merge($pivotRoleIds);
            }

            $userRoleIds = $userRoleIds
                ->filter(
                    fn($id) => (int) $id > 0,
                )
                ->map(
                    fn($id) => (int) $id,
                )
                ->unique()
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Query Purchase Order
        |--------------------------------------------------------------------------
        */
            $query = PurchaseOrder::query()
                ->with([
                    'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                    'cabangData:id,nama_cabang,inisial_cabang',

                    'departmentData:id,kode,nama',

                    'purchaseRequests:id,nomor_pr',

                    /*
                |--------------------------------------------------------------------------
                | Seluruh approval tetap dimuat
                |--------------------------------------------------------------------------
                | Dibutuhkan untuk:
                | - visibility approver;
                | - approval ANY/ALL;
                | - can_approve;
                | - riwayat approver.
                |--------------------------------------------------------------------------
                */
                    'approvals' => function ($approvalQuery) {
                        $approvalQuery
                            ->select([
                                'id',
                                'purchase_order_id',
                                'approval_flow_id',
                                'approval_flow_step_id',
                                'approver_type',
                                'approver_id',
                                'approver_name_snapshot',
                                'approval_mode',
                                'label',
                                'status',
                                'step_order',
                            ])
                            ->orderBy('step_order')
                            ->orderBy('id');
                    },
                ])
                ->orderByDesc('id');

            /*
        |--------------------------------------------------------------------------
        | Visibility berdasarkan permission dan keterlibatan approval
        |--------------------------------------------------------------------------
        |
        | Sumber visibility:
        | 1. Permission view scope.
        | 2. User pernah menjadi approver langsung.
        | 3. Role user pernah menjadi approver.
        |--------------------------------------------------------------------------
        */
            $query->where(function (
                $visibilityQuery
            ) use (
                $user,
                $userRoleIds,
                $canView,
                $viewScope,
                $userDepartmentId,
                $userCabangId,
            ) {
                /*
            |--------------------------------------------------------------------------
            | Visibility berdasarkan permission scope
            |--------------------------------------------------------------------------
            */
                $visibilityQuery->where(function (
                    $scopeQuery
                ) use (
                    $user,
                    $canView,
                    $viewScope,
                    $userDepartmentId,
                    $userCabangId,
                ) {
                    /*
                |--------------------------------------------------------------------------
                | Tidak memiliki permission view
                |--------------------------------------------------------------------------
                */
                    if (
                        !$canView
                        || $viewScope === 'NONE'
                    ) {
                        $scopeQuery->whereRaw('1 = 0');

                        return;
                    }

                    /*
                |--------------------------------------------------------------------------
                | Semua data
                |--------------------------------------------------------------------------
                */
                    if ($viewScope === 'ALL') {
                        $scopeQuery->whereRaw('1 = 1');

                        return;
                    }

                    /*
                |--------------------------------------------------------------------------
                | Data yang dibuat/request oleh user login
                |--------------------------------------------------------------------------
                */
                    if ($viewScope === 'OWN_DATA') {
                        $scopeQuery->where(function ($q) use ($user) {
                            $q
                                ->where(
                                    'created_by',
                                    $user->id,
                                )
                                ->orWhere(
                                    'requester_signed_by',
                                    $user->id,
                                );
                        });

                        return;
                    }

                    /*
                |--------------------------------------------------------------------------
                | Data department user login
                |--------------------------------------------------------------------------
                */
                    if ($viewScope === 'OWN_DEPARTMENT') {
                        if ($userDepartmentId <= 0) {
                            $scopeQuery->whereRaw('1 = 0');

                            return;
                        }

                        $scopeQuery->where(
                            'id_department',
                            $userDepartmentId,
                        );

                        return;
                    }

                    /*
                |--------------------------------------------------------------------------
                | Data cabang user login
                |--------------------------------------------------------------------------
                */
                    if ($viewScope === 'OWN_CABANG') {
                        if ($userCabangId <= 0) {
                            $scopeQuery->whereRaw('1 = 0');

                            return;
                        }

                        $scopeQuery->where(
                            'cabang',
                            $userCabangId,
                        );

                        return;
                    }

                    $scopeQuery->whereRaw('1 = 0');
                });

                /*
            |--------------------------------------------------------------------------
            | Approver langsung USER
            |--------------------------------------------------------------------------
            | Tetap dapat melihat PO tempat dia terlibat meskipun di luar scope.
            |--------------------------------------------------------------------------
            */
                $visibilityQuery->orWhereHas(
                    'approvals',
                    function ($q) use ($user) {
                        $q
                            ->whereRaw(
                                'UPPER(TRIM(approver_type)) = ?',
                                ['USER'],
                            )
                            ->where(
                                'approver_id',
                                $user->id,
                            );
                    },
                );

                /*
            |--------------------------------------------------------------------------
            | Approver berdasarkan ROLE
            |--------------------------------------------------------------------------
            */
                if ($userRoleIds->isNotEmpty()) {
                    $visibilityQuery->orWhereHas(
                        'approvals',
                        function ($q) use ($userRoleIds) {
                            $q
                                ->whereRaw(
                                    'UPPER(TRIM(approver_type)) = ?',
                                    ['ROLE'],
                                )
                                ->whereIn(
                                    'approver_id',
                                    $userRoleIds->all(),
                                );
                        },
                    );
                }
            });

            /*
        |--------------------------------------------------------------------------
        | Search nomor PO
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->search,
                );

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where(
                            'nomor_po',
                            'ILIKE',
                            "%{$search}%",
                        );
                    });
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status
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
        | Filter tanggal
        |--------------------------------------------------------------------------
        */
            if ($request->filled('tanggal_mulai')) {
                $query->whereDate(
                    'tanggal_po',
                    '>=',
                    $request->tanggal_mulai,
                );
            }

            if ($request->filled('tanggal_selesai')) {
                $query->whereDate(
                    'tanggal_po',
                    '<=',
                    $request->tanggal_selesai,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Filter tahun
        |--------------------------------------------------------------------------
        */
            $year = (int) (
                $request->year
                ?? now()->year
            );

            if ($year > 0) {
                $query->whereYear(
                    'tanggal_po',
                    $year,
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

            $pos = $query->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Transform response
        |--------------------------------------------------------------------------
        */
            $pos->getCollection()->transform(
                function ($po) use (
                    $user,
                    $userRoleIds,
                    $canUpdate,
                    $canDelete,
                    $canSubmit,
                    $userDepartmentId,
                ) {
                    /*
                |--------------------------------------------------------------------------
                | Ambil seluruh approval WAITING
                |--------------------------------------------------------------------------
                */
                    $waitingApprovals = $po->approvals
                        ->filter(function ($approval) {
                            return strtoupper(
                                trim(
                                    (string) $approval->status,
                                ),
                            ) === PurchaseOrderApproval::STATUS_WAITING;
                        })
                        ->values();

                    /*
                |--------------------------------------------------------------------------
                | Cari step aktif terkecil
                |--------------------------------------------------------------------------
                */
                    $currentStepOrder = $waitingApprovals
                        ->min('step_order');

                    /*
                |--------------------------------------------------------------------------
                | Semua kandidat approval pada step aktif
                |--------------------------------------------------------------------------
                */
                    $currentStepApprovals = $currentStepOrder !== null
                        ? $waitingApprovals
                        ->filter(function (
                            $approval
                        ) use (
                            $currentStepOrder,
                        ) {
                            return (int) $approval->step_order
                                === (int) $currentStepOrder;
                        })
                        ->sortBy('id')
                        ->values()
                        : collect();

                    /*
                |--------------------------------------------------------------------------
                | Cari approval aktif yang cocok dengan user login
                |--------------------------------------------------------------------------
                */
                    $userCurrentApproval = $currentStepApprovals
                        ->first(function (
                            $approval
                        ) use (
                            $user,
                            $userRoleIds,
                        ) {
                            $approverType = strtoupper(
                                trim(
                                    (string) $approval->approver_type,
                                ),
                            );

                            /*
                        |--------------------------------------------------------------------------
                        | Approver langsung USER
                        |--------------------------------------------------------------------------
                        */
                            if (
                                $approverType
                                === PurchaseOrderApproval::APPROVER_TYPE_USER
                            ) {
                                return (int) $approval->approver_id
                                    === (int) $user->id;
                            }

                            /*
                        |--------------------------------------------------------------------------
                        | Approver berdasarkan ROLE
                        |--------------------------------------------------------------------------
                        */
                            if (
                                $approverType
                                === PurchaseOrderApproval::APPROVER_TYPE_ROLE
                            ) {
                                return $userRoleIds->contains(
                                    (int) $approval->approver_id,
                                );
                            }

                            return false;
                        });

                    $poStatus = strtoupper(
                        trim((string) $po->status),
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Ownership PO
                    |--------------------------------------------------------------------------
                    */
                    $isCreator = (int) $po->created_by
                        === (int) $user->id;

                    $isSameDepartment = (
                        $userDepartmentId > 0
                        && (int) $po->id_department
                        === (int) $userDepartmentId
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Hak submit per row
                    |--------------------------------------------------------------------------
                    | Syarat:
                    | 1. Memiliki permission purchase_order.submit
                    | 2. Status PO masih DRAFT
                    | 3. Creator PO atau satu department
                    |--------------------------------------------------------------------------
                    */
                    $canSubmitRow = (
                        $canSubmit
                        && $poStatus === 'DRAFT'
                        && (
                            $isCreator
                            || $isSameDepartment
                        )
                    );

                    /*
                |--------------------------------------------------------------------------
                | Hak approve per row
                |--------------------------------------------------------------------------
                */
                    $canApprove = (
                        $poStatus === 'IN PROGRESS'
                        && $userCurrentApproval !== null
                    );

                    /*
                |--------------------------------------------------------------------------
                | Hak update dan delete per row
                |--------------------------------------------------------------------------
                | Hanya PO DRAFT yang dapat diedit/dihapus.
                |--------------------------------------------------------------------------
                */
                    $canUpdateRow = (
                        $canUpdate
                        && $poStatus === 'DRAFT'
                    );

                    $canDeleteRow = (
                        $canDelete
                        && $poStatus === 'DRAFT'
                    );

                    /*
                |--------------------------------------------------------------------------
                | Current approval untuk response
                |--------------------------------------------------------------------------
                | Prioritas:
                | 1. Approval yang cocok dengan user.
                | 2. Kandidat pertama pada step aktif.
                |--------------------------------------------------------------------------
                */
                    $currentApproval = $userCurrentApproval
                        ?? $currentStepApprovals->first();

                    return [
                        'id' => $po->id,

                        'public_id'
                        => $po->encrypted_id,

                        'nomor_po'
                        => $po->nomor_po,

                        'tanggal_po'
                        => $po->tanggal_po,

                        /*
                    |--------------------------------------------------------------------------
                    | Vendor
                    |--------------------------------------------------------------------------
                    */
                        'vendor_id'
                        => $po->vendor_id,

                        'vendor'
                        => $po->vendor?->nama_vendor
                            ?? '-',

                        'status_pkp'
                        => $po->vendor?->status_pkp
                            ?? 'NON_PKP',

                        'jenis_pembayaran'
                        => $po->vendor?->jenis_pembayaran
                            ?? '-',

                        'top'
                        => $po->vendor?->top,

                        /*
                    |--------------------------------------------------------------------------
                    | Cabang
                    |--------------------------------------------------------------------------
                    */
                        'cabang_id'
                        => $po->cabang,

                        'cabang'
                        => $po->cabangData?->nama_cabang
                            ?? '-',

                        'inisial_cabang'
                        => $po->cabangData?->inisial_cabang
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Department
                    |--------------------------------------------------------------------------
                    */
                        'department_id'
                        => $po->id_department,

                        'department'
                        => $po->departmentData?->kode
                            ?? '-',

                        'department_name'
                        => $po->departmentData?->nama
                            ?? '-',

                        /*
                    |--------------------------------------------------------------------------
                    | Nilai Purchase Order
                    |--------------------------------------------------------------------------
                    */
                        'dpp'
                        => $po->dpp,

                        'ppn'
                        => $po->ppn,

                        'total_nilai'
                        => $po->total_nilai,

                        /*
                    |--------------------------------------------------------------------------
                    | Status
                    |--------------------------------------------------------------------------
                    */
                        'status'
                        => $po->status,

                        'status_receive'
                        => $po->status_receive,

                        'notes'
                        => $po->notes,

                        /*
                    |--------------------------------------------------------------------------
                    | Abilities per row
                    |--------------------------------------------------------------------------
                    */
                        'can_submit'
                        => $canSubmitRow,

                        'can_approve'
                        => $canApprove,

                        'can_update'
                        => $canUpdateRow,

                        'can_delete'
                        => $canDeleteRow,

                        'is_owner'
                        => (int) $po->created_by
                            === (int) $user->id,

                        /*
                    |--------------------------------------------------------------------------
                    | Informasi approval aktif
                    |--------------------------------------------------------------------------
                    */
                        'current_step_order'
                        => $currentStepOrder !== null
                            ? (int) $currentStepOrder
                            : null,

                        'current_approval_candidates_count'
                        => $currentStepApprovals->count(),

                        'current_approval'
                        => $currentApproval
                            ? [
                                'id'
                                => $currentApproval->id,

                                'step_order'
                                => (int) $currentApproval
                                    ->step_order,

                                'approver_type'
                                => $currentApproval
                                    ->approver_type,

                                'approver_id'
                                => $currentApproval
                                    ->approver_id,

                                'approver_name_snapshot'
                                => $currentApproval
                                    ->approver_name_snapshot,

                                'approval_mode'
                                => strtoupper(
                                    trim(
                                        (string) (
                                            $currentApproval
                                            ->approval_mode
                                            ?: PurchaseOrderApproval::MODE_ANY
                                        ),
                                    ),
                                ),

                                'label'
                                => $currentApproval->label,

                                'status'
                                => $currentApproval->status,
                            ]
                            : null,

                        /*
                    |--------------------------------------------------------------------------
                    | Daftar Purchase Request
                    |--------------------------------------------------------------------------
                    */
                        'purchase_requests'
                        => $po->purchaseRequests
                            ->pluck('nomor_pr')
                            ->values(),
                    ];
                },
            );

            return response()->json([
                'success' => true,

                'message'
                => 'Data Purchase Order berhasil dimuat.',

                'data'
                => $pos->items(),

                'meta' => [
                    'current_page'
                    => $pos->currentPage(),

                    'last_page'
                    => $pos->lastPage(),

                    'per_page'
                    => $pos->perPage(),

                    'total'
                    => $pos->total(),
                ],

                /*
            |--------------------------------------------------------------------------
            | Global abilities module Purchase Order
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
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Purchase Order] Index error',
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
                => 'Gagal memuat data Purchase Order.',

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
        if (!$user || !$user->hasPermission('purchase_order.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Purchase Order.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $request->validate([
                'tanggal_po' => ['required', 'date_format:Y-m-d'],
                'vendor_id' => ['required', 'integer'],
                'cabang' => ['required'],
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

            $user = $request->user();

            $departmentId = (int) $request->id_department;

            if ($departmentId <= 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Department wajib dipilih.',
                    'errors' => [
                        'id_department' => [
                            'Department wajib dipilih.',
                        ],
                    ],
                ], 422);
            }

            $po = PurchaseOrder::create([
                'nomor_po' => $nomorPo,
                'tanggal_po' => $request->tanggal_po,
                'vendor_id' => (int) $request->vendor_id,
                'cabang' => $clean($request->cabang),
                'id_department' => $departmentId,
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

    public function edit($publicId, Request $request)
    {
        return $this->show($publicId, $request);
    }

    public function show($publicId, Request $request)
    {
        $user = $request->user();
        // if (!$user || !$user->hasPermission('purchase_order.update')) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Anda tidak memiliki akses untuk mengubah Purchase Order.',
        //     ], 403);
        // }

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
                    'tanggal_po' => $po->tanggal_po
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

    public function destroy($publicId, Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('purchase_order.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Purchase Order.',
            ], 403);
        }

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

    public function submit(
        Request $request,
        $publicId,
    ) {
        DB::beginTransaction();

        try {
            /*
        |--------------------------------------------------------------------------
        | Decrypt public ID
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString($publicId);

            /*
        |--------------------------------------------------------------------------
        | Lock PO selama proses submit
        |--------------------------------------------------------------------------
        | Mencegah dua request submit berjalan bersamaan.
        |--------------------------------------------------------------------------
        */
            $po = PurchaseOrder::query()
                ->with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            $items = $po->getRelation('items');

            /*
        |--------------------------------------------------------------------------
        | Validasi status
        |--------------------------------------------------------------------------
        */
            if (
                strtolower(trim((string) $po->status))
                !== 'draft'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Purchase Order hanya dapat disubmit jika status masih Draft.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi item
        |--------------------------------------------------------------------------
        */
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [
                        'Purchase Order tidak dapat disubmit karena item belum tersedia.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi total nilai
        |--------------------------------------------------------------------------
        */
            if ((float) ($po->total_nilai ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'total_nilai' => [
                        'Purchase Order tidak dapat disubmit karena total nilai masih 0.',
                    ],
                ]);
            }

            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Validasi tanda tangan requester
        |--------------------------------------------------------------------------
        */
            if (empty($user->signature_path)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'need_signature' => true,
                    'message' => 'Anda belum memiliki tanda tangan digital. Silakan registrasi tanda tangan terlebih dahulu.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Generate nomor PO resmi
        |--------------------------------------------------------------------------
        */
            if (
                str_starts_with(
                    (string) $po->nomor_po,
                    'DRAFT/',
                )
            ) {
                $po->nomor_po = generatePONumber($po);
            }

            /*
        |--------------------------------------------------------------------------
        | Update status dan snapshot tanda tangan requester
        |--------------------------------------------------------------------------
        */
            $po->status = 'IN PROGRESS';
            $po->submitted_at = now();

            $po->requester_signature_path
                = $user->signature_path;

            $po->requester_signed_at = now();
            $po->requester_signed_by = $user->id;

            $po->save();

            /*
        |--------------------------------------------------------------------------
        | Generate snapshot approval PO
        |--------------------------------------------------------------------------
        | Menggantikan:
        |
        | $this->generatePurchaseOrderApprovals($po);
        |--------------------------------------------------------------------------
        */
            $this->poApprovalGeneratorService->generate($po);

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notifikasi dan email dijalankan setelah commit
        |--------------------------------------------------------------------------
        | Jika email gagal, submit PO tetap dianggap berhasil.
        |--------------------------------------------------------------------------
        */
            try {
                $po->refresh();

                $this->poNotificationService
                    ->notifyApprovalRequest($po);

                $this->poMailService
                    ->sendApprovalRequest($po);
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Purchase Order] Notifikasi approver submit gagal dikirim',
                    [
                        'po_id' => $po->id,
                        'nomor_po' => $po->nomor_po,
                        'message'
                        => $notificationError->getMessage(),
                        'file'
                        => $notificationError->getFile(),
                        'line'
                        => $notificationError->getLine(),
                    ],
                );
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

                    'requester_signature_path'
                    => $po->requester_signature_path
                        ? asset(
                            'storage/'
                                . $po->requester_signature_path
                        )
                        : null,

                    'requester_signed_at'
                    => $po->requester_signed_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            /*
        |--------------------------------------------------------------------------
        | Ambil pesan validasi pertama
        |--------------------------------------------------------------------------
        */
            $errors = $e->errors();

            $firstMessage = collect($errors)
                ->flatten()
                ->first();

            return response()->json([
                'success' => false,
                'message' => $firstMessage
                    ?: 'Data Purchase Order tidak valid.',
                'errors' => $errors,
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error(
                '[Purchase Order] Submit error',
                [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Purchase Order.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function approve(
        Request $request,
        $publicId,
    ) {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $id = Crypt::decryptString($publicId);

            /*
        |--------------------------------------------------------------------------
        | Lock PO untuk mencegah approval bersamaan
        |--------------------------------------------------------------------------
        */
            $po = PurchaseOrder::query()
                ->with(['approvals'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtolower(trim((string) $po->status))
                !== 'in progress'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Purchase Order hanya dapat diapprove jika status masih In Progress.',
                    ],
                ]);
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

            /*
        |--------------------------------------------------------------------------
        | Ambil approval aktif yang benar-benar cocok dengan user
        |--------------------------------------------------------------------------
        |
        | Tidak lagi memakai getCurrentPendingApproval(), karena satu step dapat
        | mempunyai beberapa kandidat USER/ROLE.
        |--------------------------------------------------------------------------
        */
            $currentApproval = $this->poApprovalService
                ->getUserCurrentApproval(
                    $po,
                    $user,
                    true,
                );

            if (!$currentApproval) {
                $hasWaitingApproval = $po->approvals
                    ->contains(function ($approval) {
                        return strtoupper(
                            trim((string) $approval->status),
                        ) === 'WAITING';
                    });

                if ($hasWaitingApproval) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan approver pada tahap approval Purchase Order saat ini.',
                    ], 403);
                }

                throw ValidationException::withMessages([
                    'approval' => [
                        'Tidak ada approval yang sedang menunggu untuk Purchase Order ini.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Proses ANY / ALL
        |--------------------------------------------------------------------------
        |
        | Return:
        | step_completed
        | has_next_step
        | next_step_order
        | is_final_approved
        |--------------------------------------------------------------------------
        */
            $approvalResult = $this->poApprovalService
                ->approveCurrentStep(
                    $currentApproval,
                    $user,
                    $request->notes,
                );

            $stepCompleted = (bool) (
                $approvalResult['step_completed']
                ?? false
            );

            $hasNextStep = (bool) (
                $approvalResult['has_next_step']
                ?? false
            );

            $isFinalApproved = (bool) (
                $approvalResult['is_final_approved']
                ?? false
            );

            $processedApproval = $approvalResult['approval']
                ?? $currentApproval->fresh();

            /*
        |--------------------------------------------------------------------------
        | Final approval
        |--------------------------------------------------------------------------
        */
            if ($isFinalApproved) {
                $this->poApprovalService
                    ->markPurchaseOrderApproved(
                        $po,
                        $user,
                    );

                $po->refresh();
            }

            /*
        |--------------------------------------------------------------------------
        | Commit perubahan database dahulu
        |--------------------------------------------------------------------------
        |
        | Email dan notifikasi jangan dijalankan di dalam transaksi supaya
        | kegagalan email tidak menahan atau merusak transaksi approval.
        |--------------------------------------------------------------------------
        */
            DB::commit();

            try {
                /*
                |--------------------------------------------------------------------------
                | Status pending approval
                |--------------------------------------------------------------------------
                | Bernilai true selama PO belum final approved.
                |--------------------------------------------------------------------------
                */
                $hasPendingApproval = !$isFinalApproved;

                /*
                |--------------------------------------------------------------------------
                | Notifikasi aplikasi creator
                |--------------------------------------------------------------------------
                | Tetap dibuat pada setiap proses approval.
                |--------------------------------------------------------------------------
                */
                $this->poNotificationService
                    ->notifyApprovalStep(
                        $po,
                        $user,
                        $processedApproval,
                        $hasPendingApproval,
                    );

                /*
                |--------------------------------------------------------------------------
                | Email creator hanya saat final approved
                |--------------------------------------------------------------------------
                */
                if ($isFinalApproved) {
                    $this->poMailService
                        ->sendApprovalStep(
                            $po,
                            $user,
                            false,
                        );
                }
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Purchase Order] Notifikasi status approval gagal dikirim',
                    [
                        'po_id' => $po->id,
                        'nomor_po' => $po->nomor_po,
                        'approval_id' => $processedApproval?->id,
                        'is_final_approved' => $isFinalApproved,
                        'message' => $notificationError->getMessage(),
                        'file' => $notificationError->getFile(),
                        'line' => $notificationError->getLine(),
                    ],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Notifikasi approver berikutnya
        |--------------------------------------------------------------------------
        |
        | Hanya dilakukan apabila:
        | - step saat ini sudah selesai; dan
        | - memang ada step berikutnya.
        |
        | Pada mode ALL ketika baru satu kandidat approve, step_completed=false,
        | sehingga approver tahap berikutnya belum menerima notifikasi.
        |--------------------------------------------------------------------------
        */
            if ($stepCompleted && $hasNextStep) {
                try {
                    $po->refresh();

                    $this->poNotificationService
                        ->notifyApprovalRequest($po);

                    $this->poMailService
                        ->sendApprovalRequest($po);
                } catch (\Throwable $nextApproverError) {
                    Log::error(
                        '[Purchase Order] Notifikasi next approver gagal dikirim',
                        [
                            'po_id' => $po->id,
                            'nomor_po' => $po->nomor_po,
                            'next_step_order'
                            => $approvalResult['next_step_order']
                                ?? null,
                            'message'
                            => $nextApproverError->getMessage(),
                            'file'
                            => $nextApproverError->getFile(),
                            'line'
                            => $nextApproverError->getLine(),
                        ],
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Pesan response sesuai kondisi
        |--------------------------------------------------------------------------
        */
            if ($isFinalApproved) {
                $message = 'Purchase Order berhasil disetujui.';
            } elseif (!$stepCompleted) {
                $message = 'Approval berhasil diproses. Tahap ini masih menunggu persetujuan approver lainnya.';
            } else {
                $message = 'Approval Purchase Order berhasil diproses dan dilanjutkan ke tahap berikutnya.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'status' => $po->status,
                    'approved_at' => $po->approved_at,

                    'approval' => [
                        'id' => $processedApproval?->id,
                        'step_order'
                        => $processedApproval?->step_order,
                        'approval_mode'
                        => $processedApproval?->approval_mode,
                        'status'
                        => $processedApproval?->status,
                    ],

                    'step_completed' => $stepCompleted,
                    'has_next_step' => $hasNextStep,
                    'next_step_order'
                    => $approvalResult['next_step_order']
                        ?? null,
                    'is_final_approved' => $isFinalApproved,
                ],
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $errors = $e->errors();

            return response()->json([
                'success' => false,
                'message' => collect($errors)
                    ->flatten()
                    ->first()
                    ?? 'Data approval Purchase Order tidak valid.',
                'errors' => $errors,
            ], 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Purchase Order] Approve error',
                [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal approve Purchase Order.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function reject(
        Request $request,
        $publicId,
    ) {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $id = Crypt::decryptString($publicId);

            /*
        |--------------------------------------------------------------------------
        | Lock PO selama proses reject
        |--------------------------------------------------------------------------
        */
            $po = PurchaseOrder::query()
                ->with([
                    'approvals',
                    'items',
                    'purchaseRequests',
                ])
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtolower(trim((string) $po->status))
                !== 'in progress'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Purchase Order hanya dapat direject jika status masih In Progress.',
                    ],
                ]);
            }

            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Cari approval WAITING yang cocok dengan user login
        |--------------------------------------------------------------------------
        */
            $currentApproval = $this->poApprovalService
                ->getUserCurrentApproval(
                    $po,
                    $user,
                    true,
                );

            if (!$currentApproval) {
                $hasWaitingApproval = $po->approvals
                    ->contains(function ($approval) {
                        return strtoupper(
                            trim((string) $approval->status),
                        ) === 'WAITING';
                    });

                if ($hasWaitingApproval) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan approver pada tahap approval Purchase Order saat ini.',
                    ], 403);
                }

                throw ValidationException::withMessages([
                    'approval' => [
                        'Tidak ada approval yang sedang menunggu untuk Purchase Order ini.',
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Reject row approval user
        |--------------------------------------------------------------------------
        */
            $rejectedApproval = $this->poApprovalService
                ->rejectCurrentStep(
                    $currentApproval,
                    $user,
                    $request->notes,
                );

            /*
        |--------------------------------------------------------------------------
        | Hentikan seluruh flow
        |--------------------------------------------------------------------------
        |
        | Kandidat lain dalam step yang sama maupun step berikutnya menjadi
        | CANCELLED.
        |--------------------------------------------------------------------------
        */
            $this->poApprovalService
                ->cancelRemainingPendingApprovals($po);

            /*
        |--------------------------------------------------------------------------
        | Rollback alokasi item PR
        |--------------------------------------------------------------------------
        */
            $this->poRollbackService
                ->rollbackPurchaseRequestItems($po);

            /*
        |--------------------------------------------------------------------------
        | Tandai PO rejected
        |--------------------------------------------------------------------------
        */
            $this->poApprovalService
                ->markPurchaseOrderRejected($po);

            $po->refresh();
            $rejectedApproval->refresh();

            /*
        |--------------------------------------------------------------------------
        | Commit terlebih dahulu
        |--------------------------------------------------------------------------
        */
            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notifikasi requester setelah commit
        |--------------------------------------------------------------------------
        */
            try {
                $this->poNotificationService
                    ->notifyRejected(
                        $po,
                        $user,
                    );

                $this->poMailService
                    ->sendRejected(
                        $po,
                        $user,
                        $request->notes,
                    );
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Purchase Order] Notifikasi reject gagal dikirim',
                    [
                        'po_id' => $po->id,
                        'nomor_po' => $po->nomor_po,
                        'approval_id' => $rejectedApproval->id,
                        'message'
                        => $notificationError->getMessage(),
                        'file'
                        => $notificationError->getFile(),
                        'line'
                        => $notificationError->getLine(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil direject.',
                'data' => [
                    'id' => $po->id,
                    'public_id' => $po->encrypted_id,
                    'nomor_po' => $po->nomor_po,
                    'status' => $po->status,
                    'rejected_at'
                    => $rejectedApproval->rejected_at,
                    'rejected_by'
                    => $rejectedApproval
                        ->approver_name_snapshot,
                    'reject_notes'
                    => $rejectedApproval->notes,
                ],
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $errors = $e->errors();

            return response()->json([
                'success' => false,
                'message' => collect($errors)
                    ->flatten()
                    ->first()
                    ?? 'Data reject Purchase Order tidak valid.',
                'errors' => $errors,
            ], 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Purchase Order] Reject error',
                [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal reject Purchase Order.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
                    $q
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])->findOrFail($id);

            $terbilang = $this->terbilangRupiah(
                (float) $po->total_nilai,
            );

            $pdf = Pdf::loadView('pdf.purchase-order', [
                'po' => $po,
                'terbilang' => $terbilang,
            ])->setPaper('a4', 'portrait');

            $fileName = str_replace(
                ['/', '\\'],
                '-',
                $po->nomor_po,
            );

            return $pdf->stream(
                "PO-{$fileName}.pdf",
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Purchase Order.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function dropdownReceivable(Request $request)
    {
        try {
            /*
        |--------------------------------------------------------------------------
        | Department user login
        |--------------------------------------------------------------------------
        | Dropdown PO hanya menampilkan PO milik department user yang
        | sedang melakukan Goods Receipt.
        |--------------------------------------------------------------------------
        */
            $user = $request->user();

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
            | Outstanding penerimaan normal
            |--------------------------------------------------------------------------
            | GR yang memiliki source_goods_return_id tidak dihitung karena merupakan
            | penerimaan replacement dari Goods Return.
            |--------------------------------------------------------------------------
            */
            $remainingSql = "
            (
                GREATEST(
                    COALESCE(
                        purchase_order_items.qty,
                        0
                    )
                    -
                    COALESCE(
                        purchase_order_items.qty_received,
                        0
                    ),
                    0
                )

                -

                COALESCE(
                    (
                        SELECT SUM(
                            COALESCE(
                                gri.qty_receive,
                                0
                            )
                        )
                        FROM goods_receive_items AS gri
                        INNER JOIN goods_receives AS gr
                            ON gr.id = gri.goods_receive_id
                        WHERE gri.purchase_order_item_id
                            = purchase_order_items.id
                        AND UPPER(TRIM(gr.status)) = 'DRAFT'
                        AND gr.deleted_at IS NULL
                    ),
                    0
                )
            )
        ";

            $purchaseOrders = PurchaseOrder::query()
                ->with([
                    'vendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                    'cabangData:id,nama_cabang,inisial_cabang',

                    'departmentData:id,kode,nama',

                    'items' => function ($q) use ($remainingSql) {
                        $q
                            ->with('unit:id,kode,nama')
                            ->whereNull('deleted_at')
                            ->whereRaw(
                                "{$remainingSql} > 0"
                            );
                    },
                ])

                /*
            |--------------------------------------------------------------------------
            | Hanya PO final approved
            |--------------------------------------------------------------------------
            */
                ->whereRaw(
                    'UPPER(TRIM(status)) = ?',
                    ['APPROVED'],
                )

                /*
            |--------------------------------------------------------------------------
            | Filter department user login
            |--------------------------------------------------------------------------
            | Cabang tidak difilter.
            |--------------------------------------------------------------------------
            */
                ->where(
                    'id_department',
                    $departmentId,
                )

                /*
            |--------------------------------------------------------------------------
            | Status penerimaan
            |--------------------------------------------------------------------------
            */
                ->where(function ($q) {
                    $q
                        ->whereNull('status_receive')
                        ->orWhereRaw(
                            'UPPER(TRIM(status_receive)) IN (?, ?)',
                            [
                                'OPEN',
                                'PARTIAL',
                            ],
                        );
                })

                /*
            |--------------------------------------------------------------------------
            | Harus masih memiliki item outstanding
            |--------------------------------------------------------------------------
            */
                ->whereHas(
                    'items',
                    function ($q) use ($remainingSql) {
                        $q
                            ->whereNull('deleted_at')
                            ->whereRaw(
                                "{$remainingSql} > 0"
                            );
                    },
                )

                ->orderByDesc('id')
                ->get();

            $data = $purchaseOrders
                ->map(function ($po) {
                    $items = $po->items
                        ->map(function ($item) {
                            $qty = (float) (
                                $item->qty
                                ?? 0
                            );

                            $hargaUnit = (float) (
                                $item->harga_unit
                                ?? 0
                            );

                            /*
                    |--------------------------------------------------------------------------
                    | Total GR normal yang sudah POSTED
                    |--------------------------------------------------------------------------
                    | GR replacement tidak dihitung pada dropdown penerimaan normal.
                    |--------------------------------------------------------------------------
                    */
                            $qtyReceived = (float) (
                                $item->qty_received
                                ?? 0
                            );

                            /*
                    |--------------------------------------------------------------------------
                    | Total qty pada GR normal yang masih DRAFT
                    |--------------------------------------------------------------------------
                    */
                            $draftQty = (float) DB::table(
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
                                    $item->id,
                                )
                                ->whereRaw(
                                    'UPPER(TRIM(gr.status)) = ?',
                                    ['DRAFT'],
                                )
                                ->whereNull(
                                    'gr.deleted_at',
                                )
                                ->sum(
                                    'gri.qty_receive',
                                );

                            $baseOutstanding = (
                                $item->qty_outstanding_receive
                                !== null
                            )
                                ? (float) $item->qty_outstanding_receive
                                : max(
                                    $qty - $qtyReceived,
                                    0,
                                );

                            $qtyOutstanding = max(
                                $baseOutstanding - $draftQty,
                                0,
                            );

                            return [
                                'id' => $item->id,

                                'nama_item'
                                => $item->nama_item,

                                'qty'
                                => $qty,

                                'qty_received'
                                => $qtyReceived,

                                'qty_draft_receive'
                                => $draftQty,

                                'qty_outstanding_receive'
                                => $qtyOutstanding,

                                'satuan_id'
                                => $item->satuan,

                                'satuan'
                                => $item->unit?->nama
                                    ?? $item->unit?->kode
                                    ?? '-',

                                'unit'
                                => $item->unit?->nama
                                    ?? $item->unit?->kode
                                    ?? '-',

                                'harga_unit'
                                => $hargaUnit,

                                'subtotal'
                                => (float) (
                                    $item->subtotal
                                    ?? 0
                                ),

                                'subtotal_gr'
                                => $qtyReceived
                                    * $hargaUnit,

                                'subtotal_draft'
                                => $draftQty
                                    * $hargaUnit,

                                'subtotal_outstanding'
                                => $qtyOutstanding
                                    * $hargaUnit,

                                'keterangan'
                                => $item->keterangan,
                            ];
                        })
                        ->filter(function ($item) {
                            return (float) $item['qty_outstanding_receive'] > 0;
                        })
                        ->values();

                    $encryptedId = Crypt::encryptString(
                        (string) $po->id,
                    );

                    return [
                        'id' => $encryptedId,

                        'public_id' => $encryptedId,

                        'nomor_po' => $po->nomor_po,

                        'tanggal_po' => $po->tanggal_po,

                        'cabang_id' => $po->cabang,

                        'cabang' => $po->cabangData
                            ? (
                                $po->cabangData
                                ->inisial_cabang
                                ?? '-'
                            )
                            : '-',

                        'department_id'
                        => $po->id_department,

                        'department'
                        => $po->departmentData
                            ? (
                                $po->departmentData
                                ->kode
                                ?? '-'
                            )
                            : '-',

                        'status' => $po->status,

                        'status_receive'
                        => $po->status_receive,

                        'vendor_id'
                        => $po->vendor_id,

                        'vendor' => $po->vendor
                            ? [
                                'id'
                                => $po->vendor->id,

                                'nama_vendor'
                                => $po->vendor
                                    ->nama_vendor
                                    ?? '-',

                                'status_pkp'
                                => $po->vendor
                                    ->status_pkp
                                    ?? 'NON_PKP',

                                'jenis_pembayaran'
                                => $po->vendor
                                    ->jenis_pembayaran,

                                'top'
                                => $po->vendor->top,
                            ]
                            : null,

                        'items' => $items,
                    ];
                })
                ->filter(function ($po) {
                    return $po['items']->count() > 0;
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Order berhasil dimuat.',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Purchase Order] dropdownReceivable error',
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
                'message' => 'Gagal memuat Purchase Order.',
                'data' => [],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
