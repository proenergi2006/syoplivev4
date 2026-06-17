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
use App\Models\PurchaseRequestApproval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestApprovalGeneratorService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestApprovalService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestNotificationService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestMailService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Barryvdh\DomPDF\Facade\Pdf;

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

    public function index(
        Request $request,
        PurchaseRequestApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 0 ? $perPage : 10;

            /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                    'abilities' => [
                        'can_view' => false,
                        'view_scope' => 'NONE',
                        'can_create' => false,
                        'can_update' => false,
                        'can_delete' => false,
                    ],
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission Scope: Purchase Requisition View
        |--------------------------------------------------------------------------
        | NONE           = tidak boleh melihat data umum
        | OWN_DATA       = hanya PR yang dibuat user login
        | OWN_DEPARTMENT = hanya PR department user login
        | OWN_CABANG     = hanya PR cabang user login
        | ALL            = semua PR
        |
        | Catatan:
        | PR yang sedang menunggu approval user tetap dapat ditampilkan,
        | meskipun berada di luar scope view biasa.
        |--------------------------------------------------------------------------
        */
            $scope = strtoupper(
                trim(
                    (string) (
                        $user->getPermissionScope(
                            'purchase_request.view',
                        ) ?? 'NONE'
                    ),
                ),
            );

            $allowedScopes = [
                'NONE',
                'OWN_DATA',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'ALL',
            ];

            if (!in_array($scope, $allowedScopes, true)) {
                $scope = 'NONE';
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil seluruh role ID user
        |--------------------------------------------------------------------------
        | Struktur utama project menggunakan user_roles.
        |--------------------------------------------------------------------------
        */
            $userRoleIds = collect();

            if ($user->getAttribute('role_id')) {
                $userRoleIds->push(
                    (int) $user->getAttribute('role_id'),
                );
            }

            $activeRoleId = $user->getActiveRoleId();

            if ($activeRoleId) {
                $userRoleIds->push(
                    (int) $activeRoleId,
                );
            }

            $pivotRoleIds = DB::table('user_roles')
                ->where('user_id', $user->id)
                ->pluck('role_id');

            $userRoleIds = $userRoleIds
                ->merge($pivotRoleIds)
                ->filter(
                    fn($roleId) =>
                    $roleId !== null
                        && (int) $roleId > 0,
                )
                ->map(
                    fn($roleId) => (int) $roleId,
                )
                ->unique()
                ->values();

            Log::info('[PR INDEX PERMISSION DEBUG]', [
                'user_id' => $user->id,
                'raw_role_id' => $user->getAttribute('role_id'),
                'active_role_id' => $activeRoleId,
                'all_role_ids' => $userRoleIds->all(),
                'permission' => 'purchase_request.view',
                'has_permission' => $user->hasPermission(
                    'purchase_request.view',
                ),
                'scope' => $scope,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Abilities
        |--------------------------------------------------------------------------
        | Tetap dikirim walaupun data kosong atau scope view NONE.
        |--------------------------------------------------------------------------
        */
            $abilities = [
                'can_view' => $user->hasPermission(
                    'purchase_request.view',
                ),

                'view_scope' => $scope,

                'can_create' => $user->hasPermission(
                    'purchase_request.create',
                ),

                'can_update' => $user->hasPermission(
                    'purchase_request.update',
                ),

                'can_delete' => $user->hasPermission(
                    'purchase_request.delete',
                ),
            ];

            /*
        |--------------------------------------------------------------------------
        | Base query
        |--------------------------------------------------------------------------
        */
            $query = PurchaseRequest::query()
                ->with([
                    'cabangData',
                    'departmentData',
                    'recommendedVendor',
                    'items',

                    /*
                |--------------------------------------------------------------------------
                | Hanya approval aktif yang dibutuhkan pada halaman index
                |--------------------------------------------------------------------------
                */
                    'approvals' => function ($approvalQuery) {
                        $approvalQuery
                            ->orderBy('step_order')
                            ->orderBy('id');
                    },
                ]);

            /*
        |--------------------------------------------------------------------------
        | Apply Visibility Scope
        |--------------------------------------------------------------------------
        | Data yang dapat dilihat:
        |
        | 1. Data berdasarkan permission scope; ATAU
        | 2. Data yang sedang menunggu approval user login.
        |--------------------------------------------------------------------------
        */
            if ($scope !== 'ALL') {
                $query->where(function ($visibilityQuery) use (
                    $scope,
                    $user,
                    $userRoleIds,
                ) {
                    /*
                |--------------------------------------------------------------------------
                | Scope data normal
                |--------------------------------------------------------------------------
                */
                    if ($scope === 'OWN_DATA') {
                        if ($user->id) {
                            $visibilityQuery->where(
                                'purchase_requests.created_by',
                                $user->id,
                            );
                        } else {
                            $visibilityQuery->whereRaw('1 = 0');
                        }
                    } elseif ($scope === 'OWN_DEPARTMENT') {
                        if ($user->departemen_id) {
                            $visibilityQuery->where(
                                'purchase_requests.id_department',
                                $user->departemen_id,
                            );
                        } else {
                            $visibilityQuery->whereRaw('1 = 0');
                        }
                    } elseif ($scope === 'OWN_CABANG') {
                        if ($user->cabang_id) {
                            $visibilityQuery->where(
                                'purchase_requests.cabang',
                                $user->cabang_id,
                            );
                        } else {
                            $visibilityQuery->whereRaw('1 = 0');
                        }
                    } else {
                        /*
                    |--------------------------------------------------------------------------
                    | Scope NONE
                    |--------------------------------------------------------------------------
                    | Tidak boleh melihat data umum, tetapi masih boleh melihat
                    | dokumen yang memang membutuhkan approval dirinya.
                    |--------------------------------------------------------------------------
                    */
                        $visibilityQuery->whereRaw('1 = 0');
                    }

                    /*
                |--------------------------------------------------------------------------
                | Dokumen yang menunggu approval user
                |--------------------------------------------------------------------------
                */
                    $visibilityQuery->orWhereHas(
                        'approvals',
                        function ($approvalQuery) use (
                            $user,
                            $userRoleIds,
                        ) {
                            $approvalQuery->where(function ($approverQuery) use (
                                $user,
                                $userRoleIds,
                            ) {
                                $approverQuery->where(function ($userQuery) use ($user) {
                                    $userQuery
                                        ->where(
                                            'purchase_request_approvals.approver_type',
                                            PurchaseRequestApproval::APPROVER_TYPE_USER,
                                        )
                                        ->where(
                                            'purchase_request_approvals.approver_id',
                                            $user->id,
                                        );
                                });
                                if ($userRoleIds->isNotEmpty()) {
                                    $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                                        $roleQuery
                                            ->where(
                                                'purchase_request_approvals.approver_type',
                                                PurchaseRequestApproval::APPROVER_TYPE_ROLE,
                                            )
                                            ->whereIn(
                                                'purchase_request_approvals.approver_id',
                                                $userRoleIds->all(),
                                            );
                                    });
                                }
                            });
                        },
                    );
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Search
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->input('search'),
                );

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'purchase_requests.nomor_pr',
                        'ILIKE',
                        "%{$search}%",
                    )
                        ->orWhere(
                            'purchase_requests.kategori',
                            'ILIKE',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'purchase_requests.notes',
                            'ILIKE',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'purchase_requests.requested_by',
                            'ILIKE',
                            "%{$search}%",
                        )
                        ->orWhereHas(
                            'departmentData',
                            function ($departmentQuery) use ($search) {
                                $departmentQuery
                                    ->where(
                                        'kode',
                                        'ILIKE',
                                        "%{$search}%",
                                    )
                                    ->orWhere(
                                        'nama',
                                        'ILIKE',
                                        "%{$search}%",
                                    );
                            },
                        )
                        ->orWhereHas(
                            'cabangData',
                            function ($cabangQuery) use ($search) {
                                $cabangQuery
                                    ->where(
                                        'nama_cabang',
                                        'ILIKE',
                                        "%{$search}%",
                                    )
                                    ->orWhere(
                                        'inisial_cabang',
                                        'ILIKE',
                                        "%{$search}%",
                                    );
                            },
                        );
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Tanggal
        |--------------------------------------------------------------------------
        */
            if ($request->filled('tanggal_mulai')) {
                $query->whereDate(
                    'purchase_requests.tanggal_pr',
                    '>=',
                    $request->input('tanggal_mulai'),
                );
            }

            if ($request->filled('tanggal_selesai')) {
                $query->whereDate(
                    'purchase_requests.tanggal_pr',
                    '<=',
                    $request->input('tanggal_selesai'),
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Filter Status
        |--------------------------------------------------------------------------
        */
            if ($request->filled('status')) {
                $query->where(
                    'purchase_requests.status',
                    $request->input('status'),
                );
            }

            if ($request->filled('status_po')) {
                $query->where(
                    'purchase_requests.status_po',
                    $request->input('status_po'),
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Optional filter department/cabang dari FE
        |--------------------------------------------------------------------------
        | Scope user sudah diterapkan sebelum filter tambahan ini.
        |--------------------------------------------------------------------------
        */
            if ($request->filled('id_department')) {
                $query->where(
                    'purchase_requests.id_department',
                    (int) $request->input('id_department'),
                );
            }

            if ($request->filled('cabang')) {
                $query->where(
                    'purchase_requests.cabang',
                    (int) $request->input('cabang'),
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
            $prs = $query
                ->orderByDesc('purchase_requests.id')
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Transform Response
        |--------------------------------------------------------------------------
        */
            $prs->through(
                function (PurchaseRequest $pr) use (
                    $user,
                    $approvalService,
                ): array {
                    /*
                |--------------------------------------------------------------------------
                | Cari approval aktif yang sesuai user login
                |--------------------------------------------------------------------------
                | Menggunakan relation approvals yang sudah di-eager-load.
                |--------------------------------------------------------------------------
                */
                    $currentApproval = $pr->approvals
                        ->first(
                            function (
                                PurchaseRequestApproval $approval,
                            ) use (
                                $approvalService,
                                $user,
                            ): bool {
                                return strtoupper(
                                    (string) $approval->status,
                                ) === PurchaseRequestApproval::STATUS_WAITING
                                    && $approvalService->userCanApprove(
                                        $approval,
                                        $user,
                                    );
                            },
                        );

                    return [
                        'id' => $pr->id,
                        'public_id' => $pr->encrypted_id,
                        'nomor_pr' => $pr->nomor_pr,
                        'tanggal_pr' => $pr->tanggal_pr,

                        'cabang' => $pr->cabangData?->nama_cabang
                            ?? '-',

                        'cabang_id' => $pr->cabang,

                        'department' => $pr->departmentData?->kode
                            ?? '-',

                        'department_name' => $pr->departmentData?->nama
                            ?? '-',

                        'department_id' => $pr->id_department,

                        'kategori' => $pr->kategori,

                        /*
                    |--------------------------------------------------------------------------
                    | Field tipe PR existing
                    |--------------------------------------------------------------------------
                    */
                        'pr_type' => $pr->pr_type,

                        'notes' => $pr->notes,
                        'status' => $pr->status,
                        'status_po' => $pr->status_po,
                        'requested_by' => $pr->requested_by,

                        /*
                    |--------------------------------------------------------------------------
                    | Approval Ability untuk FE
                    |--------------------------------------------------------------------------
                    */
                        'can_approve' => $currentApproval !== null,

                        'approval_id' => $currentApproval?->id,

                        'approval_step_order' => $currentApproval
                            ? (int) $currentApproval->step_order
                            : null,

                        'approval_label' => $currentApproval?->label,

                        'approval_mode' => $currentApproval?->approval_mode,

                        /*
                    |--------------------------------------------------------------------------
                    | Vendor Recommendation
                    |--------------------------------------------------------------------------
                    */
                        'recommended_vendor_id'
                        => $pr->recommended_vendor_id,

                        'recommended_vendor'
                        => $pr->recommendedVendor
                            ? [
                                'id' => $pr->recommendedVendor->id,

                                'nama_vendor'
                                => $pr->recommendedVendor
                                    ->nama_vendor
                                    ?? '-',

                                'status_pkp'
                                => $pr->recommendedVendor
                                    ->status_pkp
                                    ?? '-',
                            ]
                            : null,

                        /*
                    |--------------------------------------------------------------------------
                    | Total Amount
                    |--------------------------------------------------------------------------
                    */
                        'total_amount' => $pr->total_amount
                            ?? $pr->items->sum(
                                fn($item) => (float) (
                                    $item->subtotal ?? 0
                                ),
                            ),

                        /*
                    |--------------------------------------------------------------------------
                    | Audit
                    |--------------------------------------------------------------------------
                    */
                        'created_at' => $pr->created_at,
                        'created_by' => $pr->created_by,
                        'created_by_name' => $pr->created_by_name
                            ?? null,

                        'submitted_at' => $pr->submitted_at,
                        'submitted_by' => $pr->submitted_by,
                        'submitted_by_name' => $pr->submitted_by_name
                            ?? null,

                        /*
                    |--------------------------------------------------------------------------
                    | Items
                    |--------------------------------------------------------------------------
                    */
                        'items' => $pr->items
                            ->map(function ($item): array {
                                return [
                                    'id' => $item->id,
                                    'nama_item' => $item->nama_item,
                                    'qty' => $item->qty,
                                    'satuan' => $item->satuan,
                                    'spesifikasi' => $item->spesifikasi,
                                    'keterangan' => $item->keterangan,
                                    'harga_unit' => $item->harga_unit,
                                    'subtotal' => $item->subtotal,
                                ];
                            })
                            ->values(),
                    ];
                },
            );

            return response()->json([
                'success' => true,
                'message' => 'Data Purchase Requisition berhasil dimuat.',
                'data' => $prs->items(),
                'meta' => [
                    'current_page' => $prs->currentPage(),
                    'last_page' => $prs->lastPage(),
                    'per_page' => $prs->perPage(),
                    'total' => $prs->total(),
                ],
                'abilities' => $abilities,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Purchase Requisition.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $request->input(
                        'per_page',
                        10,
                    ),
                    'total' => 0,
                ],
                'abilities' => [
                    'can_view' => false,
                    'view_scope' => 'NONE',
                    'can_create' => false,
                    'can_update' => false,
                    'can_delete' => false,
                ],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * POST /api/purchase-request
     * Simpan data baru dari form (axios.post)
     */
    public function store(Request $request)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Purchase Requisition.',
            ], 403);
        }

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
                'pr_type'                => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],
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
            $user = $request->user();
            $pr = PurchaseRequest::create([
                'nomor_pr'              => $nomorPr,
                'tanggal_pr'            => $clean($request->tanggal_pr),
                'cabang'                => $clean($request->cabang),
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $request->filled('recommended_vendor_id')
                    ? (int) $request->recommended_vendor_id
                    : null,
                'kategori'              => $clean($request->kategori),
                'pr_type'               => $clean($request->pr_type),
                'notes'                 => $clean($request->notes),
                'status'                => PurchaseRequest::STATUS_DRAFT,
                'total_amount'          => $totalAmount,
                'created_by'            => $user?->id,
                'updated_by'            => $user?->id,
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
                'message'  => 'Purchase Requisition berhasil disimpan.',
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
                'message' => 'Gagal menyimpan Purchase Requisition. Silakan periksa data atau hubungi IT.',
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
                'creator',
                'submitter',

                /*
            |--------------------------------------------------------------------------
            | Snapshot approval Purchase Requisition
            |--------------------------------------------------------------------------
            | Semua status dimuat untuk kebutuhan History Approval:
            | WAITING, PENDING, APPROVED, REJECTED, SKIPPED, CANCELLED.
            |--------------------------------------------------------------------------
            */
                'approvals' => function ($approvalQuery) {
                    $approvalQuery
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])->findOrFail($id);

            $items = $pr->getRelation('items');

            $totalPo = $items->sum(function ($item) {
                return (float) ($item->qty_po ?? 0)
                    * (float) ($item->harga_unit ?? 0);
            });

            $totalOutstanding = $items->sum(function ($item) {
                return (float) ($item->qty_outstanding ?? 0)
                    * (float) ($item->harga_unit ?? 0);
            });

            return response()->json([
                'success' => true,
                'message' => 'Detail Purchase Requisition berhasil dimuat.',

                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr,

                    'cabang_id' => $pr->cabang,

                    'cabang' => $pr->cabangData
                        ? trim(
                            ($pr->cabangData->inisial_cabang ?? '-')
                                . ' - '
                                . ($pr->cabangData->nama_cabang ?? '-')
                        )
                        : '-',

                    'department_id' => $pr->id_department,

                    'department' => $pr->departmentData
                        ? trim(
                            ($pr->departmentData->kode ?? '-')
                                . ' - '
                                . ($pr->departmentData->nama ?? '-')
                        )
                        : '-',

                    'recommended_vendor_id' => $pr->recommended_vendor_id,

                    'recommended_vendor' => $pr->recommendedVendor
                        ? [
                            'id' => $pr->recommendedVendor->id,

                            'nama_vendor' => $pr->recommendedVendor
                                ->nama_vendor
                                ?? '-',

                            'status_pkp' => $pr->recommendedVendor
                                ->status_pkp
                                ?? 'NON_PKP',

                            'jenis_pembayaran' => $pr->recommendedVendor
                                ->jenis_pembayaran
                                ?? null,

                            'top' => $pr->recommendedVendor
                                ->top
                                ?? null,
                        ]
                        : null,

                    'kategori' => $pr->kategori,
                    'pr_type' => $pr->pr_type,
                    'notes' => $pr->notes,
                    'status' => $pr->status,
                    'status_po' => $pr->status_po,

                    /*
                |--------------------------------------------------------------------------
                | Purchase Order terkait
                |--------------------------------------------------------------------------
                */
                    'purchase_orders' => $pr->purchaseOrders
                        ->filter(function ($po) {
                            return !in_array(
                                strtoupper((string) $po->status),
                                [
                                    'REJECTED',
                                    'CANCELLED',
                                ],
                                true,
                            );
                        })
                        ->values()
                        ->map(function ($po) {
                            return [
                                'id' => $po->id,
                                'public_id' => $po->encrypted_id,
                                'nomor_po' => $po->nomor_po,
                                'tanggal_po' => $po->tanggal_po,

                                'total_nilai' => (float) (
                                    $po->total_nilai ?? 0
                                ),

                                'status' => $po->status,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Audit
                |--------------------------------------------------------------------------
                */
                    'created_at' => $pr->created_at,
                    'created_by' => $pr->created_by,

                    'created_by_name' => $pr->creator?->name
                        ?? '-',

                    'submitted_at' => $pr->submitted_at,
                    'submitted_by' => $pr->submitted_by,

                    'submitted_by_name' => $pr->submitter?->name
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Nilai
                |--------------------------------------------------------------------------
                */
                    'total_amount' => (float) (
                        $pr->total_amount ?? 0
                    ),

                    'total_po' => $totalPo,
                    'total_outstanding' => $totalOutstanding,

                    /*
                |--------------------------------------------------------------------------
                | Items
                |--------------------------------------------------------------------------
                */
                    'items' => $items
                        ->map(function ($item) {
                            $qty = (float) ($item->qty ?? 0);
                            $qtyPo = (float) ($item->qty_po ?? 0);

                            $qtyOutstanding = (float) (
                                $item->qty_outstanding ?? 0
                            );

                            $hargaUnit = (float) (
                                $item->harga_unit ?? 0
                            );

                            return [
                                'id' => $item->id,
                                'nama_item' => $item->nama_item,
                                'qty' => $item->qty,
                                'qty_po' => $item->qty_po,
                                'qty_outstanding' => $item->qty_outstanding,

                                'satuan_id' => $item->satuan,

                                'satuan' => [
                                    'id' => $item->unit?->id,
                                    'kode' => $item->unit?->kode ?? '-',
                                    'nama' => $item->unit?->nama ?? '-',
                                ],

                                'spesifikasi' => $item->spesifikasi,
                                'harga_unit' => $item->harga_unit,
                                'subtotal' => $item->subtotal,

                                'subtotal_po' => $qtyPo
                                    * $hargaUnit,

                                'subtotal_outstanding' => $qtyOutstanding
                                    * $hargaUnit,

                                'keterangan' => $item->keterangan,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Attachments
                |--------------------------------------------------------------------------
                */
                    'attachments' => $pr->attachments
                        ->map(function ($attachment) {
                            return [
                                'id' => $attachment->id,
                                'filename' => $attachment->filename,

                                'filepath' => asset(
                                    'storage/' . $attachment->filepath
                                ),

                                'file_size' => $attachment->file_size,
                                'mime_type' => $attachment->mime_type,

                                'original_filename' => $attachment
                                    ->original_filename,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Approval Histories existing
                |--------------------------------------------------------------------------
                | Tetap dipertahankan agar tidak merusak fitur existing.
                |--------------------------------------------------------------------------
                */
                    'approval_histories' => $pr->approvalHistories,

                    /*
                |--------------------------------------------------------------------------
                | Snapshot History Approval
                |--------------------------------------------------------------------------
                | Digunakan ApprovalHistoryPRDialog pada index.
                |
                | Jangan di-unique berdasarkan step_order karena mode ANY dapat
                | memiliki beberapa calon approver pada tahap yang sama.
                |--------------------------------------------------------------------------
                */
                    'approvals' => $pr->approvals
                        ->filter(function ($approval) {
                            return strtoupper(
                                trim((string) $approval->status)
                            ) !== PurchaseRequestApproval::STATUS_SKIPPED;
                        })
                        ->sortBy(function ($approval) {
                            return sprintf(
                                '%010d-%010d',
                                (int) $approval->step_order,
                                (int) $approval->id,
                            );
                        })
                        ->unique(function ($approval) {
                            $status = strtoupper(
                                trim((string) $approval->status)
                            );

                            if (in_array($status, [
                                PurchaseRequestApproval::STATUS_WAITING,
                                PurchaseRequestApproval::STATUS_PENDING,
                            ], true)) {
                                return 'STEP-' . (int) $approval->step_order;
                            }

                            return 'ROW-' . (int) $approval->id;
                        })

                        ->values()
                        ->map(function ($approval) {
                            return [
                                'id' => $approval->id,

                                'approval_flow_id' => $approval
                                    ->approval_flow_id,

                                'approval_flow_step_id' => $approval
                                    ->approval_flow_step_id,

                                'step_order' => (int) (
                                    $approval->step_order ?? 0
                                ),

                                'label' => $approval->label,

                                'approver_type' => $approval
                                    ->approver_type,

                                'approver_id' => $approval
                                    ->approver_id,

                                'approver_name_snapshot' => $approval
                                    ->approver_name_snapshot,

                                'approval_mode' => $approval
                                    ->approval_mode,

                                'status' => strtoupper(
                                    (string) $approval->status
                                ),

                                'signature_path' => $approval
                                    ->signature_path,

                                'signed_at' => $approval
                                    ->signed_at,

                                'approved_at' => $approval
                                    ->approved_at,

                                'rejected_at' => $approval
                                    ->rejected_at,

                                'notes' => $approval->notes,

                                'created_at' => $approval
                                    ->created_at,

                                'updated_at' => $approval
                                    ->updated_at,
                            ];
                        })
                        ->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Purchase Requisition.',
                'data' => null,

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }


    /**
     * PUT /api/purchase-request/{id}
     * Update PR
     */
    public function update(string $publicId, Request $request)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Purchase Requisition.',
            ], 403);
        }

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
                'pr_type'                => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],
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
                    'message' => 'Purchase Requisition sudah diapprove. Tidak dapat diperbarui.',
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
            $user = $request->user();
            $pr->update([
                'tanggal_pr'            => $clean($request->tanggal_pr),
                'cabang'                => $clean($request->cabang),
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $request->filled('recommended_vendor_id')
                    ? (int) $request->recommended_vendor_id
                    : null,
                'kategori'              => $clean($request->kategori),
                'pr_type'               => $clean($request->pr_type),
                'notes'                 => $clean($request->notes),
                'total_amount'          => $totalAmount,
                'updated_by'            => $user?->id,
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
                    'qty_outstanding'     => $qty,
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
                'message' => 'Purchase Requisition berhasil diperbarui.',
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
                'message' => 'Gagal update Purchase Requisition.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
                'line'    => config('app.debug') ? $e->getLine() : null,
            ], 500);
        }
    }

    /**
     * DELETE /api/purchase-request/{id}
     * Soft delete data
     */
    public function destroy(Request $request, $publicId)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Purchase Requisition.',
            ], 403);
        }

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
                    'message' => 'Purchase Requisition tidak ditemukan.',
                ], 404);
            }

            if (strtolower($pr->status) !== 'draft') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition hanya dapat dihapus jika status masih Draft.',
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
                'message' => 'Purchase Requisition berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Requisition] Delete error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Purchase Requisition.',
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

    public function edit(Request $request, $publicId)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Purchase Requisition.',
            ], 403);
        }

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
                    'message' => 'Purchase Requisition tidak ditemukan.',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data edit Purchase Requisition berhasil dimuat.',
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr
                        ? \Carbon\Carbon::parse($pr->tanggal_pr)->format('Y-m-d')
                        : null,

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
                    'pr_type' => $pr->pr_type,
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
            Log::error('[Purchase Requisition] Edit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data edit Purchase Requisition.',
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
                    'message' => 'Purchase Requisition tidak ditemukan.'
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

    public function approve(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalService $approvalService,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $purchaseRequest = PurchaseRequest::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $purchaseRequest->status)
                !== PurchaseRequest::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition tidak sedang dalam proses approval.',
                ], 422);
            }

            $result = $approvalService->approveCurrentStep(
                $purchaseRequest,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $purchaseRequest->refresh();

            /*
        |--------------------------------------------------------------------------
        | Setelah commit: notifikasi dan email
        |--------------------------------------------------------------------------
        */
            try {
                $notificationService->notifyApprovalStep(
                    $purchaseRequest,
                    $user,
                    $result['approval'],
                    $result['has_pending_approval'],
                );

                $mailService->sendApprovalStep(
                    $purchaseRequest,
                    $user,
                    $result['has_pending_approval'],
                );
            } catch (\Throwable $notifyError) {
                Log::error(
                    '[Purchase Request] Notify approval result gagal',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'message' => $notifyError->getMessage(),
                    ],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Jika step berikutnya aktif, kirim ke approver berikutnya
        |--------------------------------------------------------------------------
        */
            if (
                $result['step_completed']
                && $result['has_pending_approval']
                && $result['next_step_order'] !== null
            ) {
                try {
                    $notificationService->notifyApprovalRequest(
                        $purchaseRequest,
                    );

                    $mailService->sendApprovalRequest(
                        $purchaseRequest,
                    );
                } catch (\Throwable $nextApproverError) {
                    Log::error(
                        '[Purchase Request] Notify next approver gagal',
                        [
                            'purchase_request_id' => $purchaseRequest->id,
                            'next_step_order' => $result['next_step_order'],
                            'message' => $nextApproverError->getMessage(),
                        ],
                    );
                }
            }

            return response()->json([
                'success' => true,

                'message' => $result['is_final_approved']
                    ? 'Purchase Requisition berhasil disetujui secara final.'
                    : (
                        $result['step_completed']
                        ? 'Tahap approval Purchase Requisition berhasil disetujui.'
                        : 'Approval Anda berhasil disimpan dan masih menunggu approver lain pada tahap yang sama.'
                    ),

                'data' => [
                    'id' => $purchaseRequest->id,
                    'public_id' => $purchaseRequest->encrypted_id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'status' => $purchaseRequest->status,
                    'status_po' => $purchaseRequest->status_po,
                    'step_completed' => $result['step_completed'],
                    'has_pending_approval' => $result['has_pending_approval'],
                    'is_final_approved' => $result['is_final_approved'],
                    'next_step_order' => $result['next_step_order'],
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Approval tidak dapat diproses.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Request] Approve error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal approve Purchase Requisition.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function reject(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalService $approvalService,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $purchaseRequest = PurchaseRequest::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $purchaseRequest->status)
                !== PurchaseRequest::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition tidak sedang dalam proses approval.',
                ], 422);
            }

            $approval = $approvalService->rejectCurrentStep(
                $purchaseRequest,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $purchaseRequest->refresh();

            try {
                $notificationService->notifyRejected(
                    $purchaseRequest,
                    $user,
                );

                $mailService->sendRejected(
                    $purchaseRequest,
                    $user,
                    $validated['notes'] ?? null,
                );
            } catch (\Throwable $notifyError) {
                Log::error(
                    '[Purchase Request] Notify reject gagal',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'message' => $notifyError->getMessage(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase Requisition berhasil ditolak.',
                'data' => [
                    'id' => $purchaseRequest->id,
                    'public_id' => $purchaseRequest->encrypted_id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'status' => $purchaseRequest->status,
                    'approval_id' => $approval->id,
                    'rejected_at' => $approval->rejected_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Reject tidak dapat diproses.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Request] Reject error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal reject Purchase Requisition.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function submit(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalGeneratorService $approvalGenerator,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'items',
            ])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($pr->status !== PurchaseRequest::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition hanya bisa disubmit dari status Draft.',
                ], 422);
            }

            if ($pr->items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition tidak dapat disubmit karena item belum tersedia.',
                ], 422);
            }

            if (str_starts_with((string) $pr->nomor_pr, 'DRAFT/')) {
                $pr->nomor_pr = generatePRNumber($pr);
            }

            /*
        |--------------------------------------------------------------------------
        | Generate snapshot approval PR
        |--------------------------------------------------------------------------
        */
            $approvalGenerator->generate($pr);

            /*
        |--------------------------------------------------------------------------
        | Update status PR
        |--------------------------------------------------------------------------
        | Tidak menggunakan current_level lagi.
        |--------------------------------------------------------------------------
        */
            $pr->status = PurchaseRequest::STATUS_IN_PROGRESS;
            $pr->submitted_at = now();
            $pr->submitted_by = $user->id;
            $pr->save();

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Notifikasi aplikasi setelah transaksi berhasil
            |--------------------------------------------------------------------------
            */
            $pr->refresh();
            $pr->loadMissing('items');

            try {
                $notificationService->notifyApprovalRequest($pr);
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Purchase Requisition] Notifikasi approver gagal dibuat',
                    [
                        'purchase_request_id' => $pr->id,
                        'nomor_pr' => $pr->nomor_pr,
                        'message' => $notificationError->getMessage(),
                    ],
                );
            }

            try {
                $mailService->sendApprovalRequest($pr);
            } catch (\Throwable $mailError) {
                Log::error(
                    '[Purchase Requisition] Email approver gagal dikirim',
                    [
                        'purchase_request_id' => $pr->id,
                        'nomor_pr' => $pr->nomor_pr,
                        'message' => $mailError->getMessage(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase Requisition berhasil disubmit.',
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id
                        ?? Crypt::encryptString((string) $pr->id),
                    'nomor_pr' => $pr->nomor_pr,
                    'status' => $pr->status,
                    'submitted_at' => $pr->submitted_at,
                    'submitted_by' => $pr->submitted_by,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Konfigurasi approval tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Requisition] Submit error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Purchase Requisition.',
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

            $pr = PurchaseRequest::with([
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',

                'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                'items',
                'items.unit:id,kode,nama',

                'creator:id,name',
                'submitter:id,name',

                'approvals' => function ($query) {
                    $query
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])->findOrFail($id);

            $items = $pr->getRelation('items');

            if (
                strtoupper(trim((string) $pr->status))
                !== PurchaseRequest::STATUS_APPROVED
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase Requisition hanya dapat dicetak setelah final approval.',
                ], 422);
            }

            $totalAmount = (float) (
                $pr->total_amount
                ?? $items->sum(function ($item) {
                    $subtotal = (float) ($item->subtotal ?? 0);

                    if ($subtotal > 0) {
                        return $subtotal;
                    }

                    return (float) ($item->qty ?? 0)
                        * (float) ($item->harga_unit ?? 0);
                })
            );
            $terbilang = $this->terbilangRupiah($totalAmount);

            $approvedApprovals = $pr->approvals
                ->filter(function ($approval) {
                    return strtoupper(
                        trim((string) $approval->status)
                    ) === PurchaseRequestApproval::STATUS_APPROVED;
                })
                ->sortBy(function ($approval) {
                    return sprintf(
                        '%010d-%010d',
                        (int) $approval->step_order,
                        (int) $approval->id,
                    );
                })
                ->values();

            $requesterSigner = collect([
                (object) [
                    'type' => 'REQUESTER',
                    'label' => 'Requester',
                    'name' => $pr->submitter?->name
                        ?? $pr->creator?->name
                        ?? '-',
                    'signature_path' => $pr->requester_signature_path,
                    'signed_at' => $pr->requester_signed_at
                        ?? $pr->submitted_at,
                ],
            ]);

            $approvalSigners = $approvedApprovals
                ->map(function ($approval) {
                    return (object) [
                        'type' => 'APPROVER',
                        'label' => $approval->label
                            ?? 'Approver',
                        'name' => $approval->approver_name_snapshot
                            ?? '-',
                        'signature_path' => $approval->signature_path,
                        'signed_at' => $approval->approved_at
                            ?? $approval->signed_at,
                    ];
                });

            $signers = $requesterSigner
                ->concat($approvalSigners)
                ->values();

            $pdf = Pdf::loadView('pdf.purchase-request', [
                'pr' => $pr,
                'totalAmount' => $totalAmount,
                'terbilang' => $terbilang,
                'approvedApprovals' => $approvedApprovals,
                'signers' => $signers,
            ])->setPaper('a4', 'portrait');

            $fileName = str_replace(
                ['/', '\\'],
                '-',
                (string) $pr->nomor_pr,
            );

            return $pdf->stream(
                "PR-{$fileName}.pdf",
            );
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Print error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Purchase Requisition.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function terbilangRupiah(float $angka): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->terbilang($angka))) . ' Rupiah';
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
                'message' => 'Purchase Requisition berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] dropdownApproved error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat Purchase Requisition.',
                'data'    => [],
                'debug'   => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
