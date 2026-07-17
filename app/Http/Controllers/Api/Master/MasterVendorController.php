<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\MasterVendor;
use App\Models\VendorBank;
use App\Models\VendorDokumenPendukung;
use App\Models\VendorTransaksi;
use App\Models\MasterDokumenPendukung;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\ApprovalFlow;
use App\Models\MasterVendorApproval;
use App\Models\User;
use App\Models\Notification;
use App\Mail\MasterVendorApprovalMail;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use App\Services\MasterVendor\MasterVendorApprovalService;
use Illuminate\Support\Facades\Schema;
use App\Services\MasterVendor\MasterVendorApprovalGeneratorService;
use App\Services\MasterVendor\MasterVendorApprovalNotificationService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class MasterVendorController extends Controller
{

    protected MasterVendorApprovalService $vendorApprovalService;
    protected MasterVendorApprovalGeneratorService $vendorApprovalGeneratorService;
    protected MasterVendorApprovalNotificationService $vendorApprovalNotificationService;

    public function __construct(MasterVendorApprovalService $vendorApprovalService, MasterVendorApprovalGeneratorService $vendorApprovalGeneratorService, MasterVendorApprovalNotificationService $vendorApprovalNotificationService)
    {
        $this->vendorApprovalService = $vendorApprovalService;
        $this->vendorApprovalGeneratorService = $vendorApprovalGeneratorService;
        $this->vendorApprovalNotificationService = $vendorApprovalNotificationService;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User login tidak ditemukan.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Permission Master Vendor
        |--------------------------------------------------------------------------
        */
            $canView = $user->hasPermission('vendor.view');
            $canCreate = $user->hasPermission('vendor.create');
            $canUpdate = $user->hasPermission('vendor.update');
            $canSubmit = $user->hasPermission('vendor.submit');
            $canDelete = $user->hasPermission('vendor.delete');

            $viewScope = $canView
                ? strtoupper(
                    trim(
                        (string) (
                            $user->getPermissionScope('vendor.view')
                            ?? 'NONE'
                        ),
                    ),
                )
                : 'NONE';

            /*
        |--------------------------------------------------------------------------
        | Normalisasi scope
        |--------------------------------------------------------------------------
        */
            if (!in_array(
                $viewScope,
                [
                    'NONE',
                    'OWN_DATA',
                    'OWN_DEPARTMENT',
                    'OWN_CABANG',
                    'ALL',
                ],
                true,
            )) {
                $viewScope = 'NONE';
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil seluruh role user
        |--------------------------------------------------------------------------
        */
            $userRoleIds = collect();

            if (method_exists($user, 'roles')) {
                $userRoleIds = $user->roles()
                    ->pluck('roles.id')
                    ->map(
                        fn($id) => (int) $id,
                    );
            }

            /*
        |--------------------------------------------------------------------------
        | Compatibility jika user mempunyai role_id langsung
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

            $userRoleIds = $userRoleIds
                ->filter(
                    fn($id) => (int) $id > 0,
                )
                ->unique()
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Subquery Vendor yang melibatkan user sebagai approver USER
        |--------------------------------------------------------------------------
        */
            $userApprovalVendorIds = DB::table(
                'master_vendor_approvals',
            )
                ->whereRaw(
                    'UPPER(TRIM(approver_type)) = ?',
                    ['USER'],
                )
                ->where(
                    'approver_id',
                    $user->id,
                )
                ->select('vendor_id');

            /*
        |--------------------------------------------------------------------------
        | Subquery Vendor yang melibatkan role user sebagai approver
        |--------------------------------------------------------------------------
        */
            $roleApprovalVendorIds = null;

            if ($userRoleIds->isNotEmpty()) {
                $roleApprovalVendorIds = DB::table(
                    'master_vendor_approvals',
                )
                    ->whereRaw(
                        'UPPER(TRIM(approver_type)) = ?',
                        ['ROLE'],
                    )
                    ->whereIn(
                        'approver_id',
                        $userRoleIds->all(),
                    )
                    ->select('vendor_id');
            }

            /*
        |--------------------------------------------------------------------------
        | Query utama
        |--------------------------------------------------------------------------
        */
            $query = MasterVendor::query();

            /*
        |--------------------------------------------------------------------------
        | Visibility Vendor
        |--------------------------------------------------------------------------
        |
        | Vendor dapat terlihat apabila:
        |
        | 1. Memenuhi permission scope vendor.view; ATAU
        | 2. User terlibat sebagai approver USER/ROLE.
        |
        | Keterlibatan approval hanya memberikan akses melihat dan memproses
        | approval. Tidak otomatis memberikan akses edit/submit/delete.
        |--------------------------------------------------------------------------
        */
            $query->where(function ($visibilityQuery) use (
                $user,
                $viewScope,
                $userApprovalVendorIds,
                $roleApprovalVendorIds,
            ) {
                /*
            |--------------------------------------------------------------------------
            | Visibility berdasarkan view scope
            |--------------------------------------------------------------------------
            */
                $visibilityQuery->where(function ($scopeQuery) use (
                    $user,
                    $viewScope,
                ) {
                    switch ($viewScope) {
                        case 'ALL':
                            $scopeQuery->whereRaw('1 = 1');
                            break;

                        case 'OWN_DATA':
                            $scopeQuery->where(
                                'created_by',
                                $user->id,
                            );
                            break;

                        case 'OWN_DEPARTMENT':
                            if (!empty($user->department_id)) {
                                $scopeQuery->where(
                                    'id_department',
                                    $user->department_id,
                                );
                            } else {
                                $scopeQuery->whereRaw('1 = 0');
                            }

                            break;

                        /*
                    |--------------------------------------------------------------------------
                    | Master Vendor belum menggunakan scope cabang
                    |--------------------------------------------------------------------------
                    */
                        case 'OWN_CABANG':
                        case 'NONE':
                        default:
                            $scopeQuery->whereRaw('1 = 0');
                            break;
                    }
                });

                /*
            |--------------------------------------------------------------------------
            | Tetap tampilkan Vendor yang melibatkan user sebagai approver
            |--------------------------------------------------------------------------
            */
                $visibilityQuery->orWhereIn(
                    'id',
                    $userApprovalVendorIds,
                );

                if ($roleApprovalVendorIds !== null) {
                    $visibilityQuery->orWhereIn(
                        'id',
                        $roleApprovalVendorIds,
                    );
                }
            });

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
            $search = trim(
                (string) $request->get(
                    'search',
                    '',
                ),
            );

            if ($search !== '') {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where(
                            'kode_vendor',
                            'ILIKE',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'nama_vendor',
                            'ILIKE',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'inisial_vendor',
                            'ILIKE',
                            "%{$search}%",
                        );
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status aktif
        |--------------------------------------------------------------------------
        */
            $isActiveParam = $request->get('is_active');

            if (
                $isActiveParam !== null
                && $isActiveParam !== ''
                && strtolower(
                    trim((string) $isActiveParam),
                ) !== 'all'
            ) {
                $isActive = filter_var(
                    $isActiveParam,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );

                if ($isActive !== null) {
                    $query->where(
                        'is_active',
                        $isActive,
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Filter status approval
        |--------------------------------------------------------------------------
        */
            $statusApproval = strtoupper(
                trim(
                    (string) $request->get(
                        'status_approval',
                        '',
                    ),
                ),
            );

            if (
                $statusApproval !== ''
                && $statusApproval !== 'ALL'
                && $statusApproval !== 'SEMUA'
            ) {
                $query->whereRaw(
                    'UPPER(TRIM(status_approval)) = ?',
                    [$statusApproval],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
            $perPage = (int) $request->get(
                'per_page',
                10,
            );

            if ($perPage <= 0) {
                $perPage = 10;
            }

            $data = $query
                ->orderByDesc('id')
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Ambil approval seluruh Vendor pada halaman aktif
        |--------------------------------------------------------------------------
        */
            $vendorIds = $data
                ->getCollection()
                ->pluck('id')
                ->map(
                    fn($id) => (int) $id,
                )
                ->filter()
                ->values();

            $approvalRowsByVendor = collect();

            if ($vendorIds->isNotEmpty()) {
                $approvalRowsByVendor = DB::table(
                    'master_vendor_approvals',
                )
                    ->whereIn(
                        'vendor_id',
                        $vendorIds->all(),
                    )
                    ->select([
                        'id',
                        'vendor_id',
                        'approval_flow_id',
                        'approval_flow_step_id',
                        'step_order',
                        'approver_type',
                        'approver_id',
                        'approval_mode',
                        'label',
                        'status',
                        'approver_name_snapshot',
                        'notes',
                        'approved_at',
                        'rejected_at',
                        'cancelled_at',
                        'created_at',
                        'updated_at',
                    ])
                    ->orderBy('step_order')
                    ->orderBy('id')
                    ->get()
                    ->groupBy(
                        fn($approval) => (int) $approval->vendor_id,
                    );
            }

            /*
        |--------------------------------------------------------------------------
        | Transform response
        |--------------------------------------------------------------------------
        */
            $items = $data
                ->getCollection()
                ->map(function ($item) use (
                    $user,
                    $userRoleIds,
                    $approvalRowsByVendor,
                    $canUpdate,
                    $canSubmit,
                    $canDelete,
                ) {
                    $item->public_id = Crypt::encryptString(
                        (string) $item->id,
                    );

                    /*
                |--------------------------------------------------------------------------
                | Status Vendor
                |--------------------------------------------------------------------------
                */
                    $vendorStatus = strtoupper(
                        trim(
                            (string) $item->status_approval,
                        ),
                    );

                    /*
                |--------------------------------------------------------------------------
                | Ownership dan department
                |--------------------------------------------------------------------------
                */
                    $isCreator = (
                        !empty($item->created_by)
                        && (int) $item->created_by
                        === (int) $user->id
                    );

                    $isSameDepartment = (
                        !empty($user->departemen_id)
                        && !empty($item->id_department)
                        && (int) $item->id_department
                        === (int) $user->departemen_id
                    );

                    /*
                |--------------------------------------------------------------------------
                | Approval snapshot Vendor
                |--------------------------------------------------------------------------
                */
                    $vendorApprovals = collect(
                        $approvalRowsByVendor->get(
                            (int) $item->id,
                            collect(),
                        ),
                    );

                    /*
                |--------------------------------------------------------------------------
                | Cari step aktif berdasarkan status WAITING
                |--------------------------------------------------------------------------
                */
                    $waitingApprovals = $vendorApprovals
                        ->filter(function ($approval) {
                            return strtoupper(
                                trim(
                                    (string) $approval->status,
                                ),
                            ) === 'WAITING';
                        })
                        ->values();

                    $currentStepOrder = $waitingApprovals
                        ->min('step_order');

                    $currentStepApprovals = $currentStepOrder !== null
                        ? $waitingApprovals
                        ->filter(function ($approval) use (
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
                | Cari approval aktif yang cocok dengan USER/ROLE login
                |--------------------------------------------------------------------------
                */
                    $userCurrentApproval = $currentStepApprovals
                        ->first(function ($approval) use (
                            $user,
                            $userRoleIds,
                        ) {
                            $approverType = strtoupper(
                                trim(
                                    (string) $approval->approver_type,
                                ),
                            );

                            if ($approverType === 'USER') {
                                return (int) $approval->approver_id
                                    === (int) $user->id;
                            }

                            if ($approverType === 'ROLE') {
                                return $userRoleIds->contains(
                                    (int) $approval->approver_id,
                                );
                            }

                            return false;
                        });

                    /*
                |--------------------------------------------------------------------------
                | Hak approve/reject
                |--------------------------------------------------------------------------
                |
                | Tidak terkait permission update/submit/delete.
                | Hanya berdasarkan kandidat WAITING pada step aktif.
                |--------------------------------------------------------------------------
                */
                    $canApprove = (
                        $vendorStatus === 'PENDING REVIEW'
                        && $userCurrentApproval !== null
                    );

                    /*
                |--------------------------------------------------------------------------
                | Apakah final approver wajib mengisi kode Vendor
                |--------------------------------------------------------------------------
                */
                    $currentApprovalMode = strtoupper(
                        trim(
                            (string) (
                                $userCurrentApproval?->approval_mode
                                ?? 'ANY'
                            ),
                        ),
                    );

                    $hasNextStep = false;

                    if ($currentStepOrder !== null) {
                        $hasNextStep = $vendorApprovals
                            ->contains(function ($approval) use (
                                $currentStepOrder,
                            ) {
                                return (
                                    (int) $approval->step_order
                                    > (int) $currentStepOrder
                                    && in_array(
                                        strtoupper(
                                            trim(
                                                (string) $approval->status,
                                            ),
                                        ),
                                        [
                                            'PENDING',
                                            'WAITING',
                                        ],
                                        true,
                                    )
                                );
                            });
                    }

                    $currentWaitingCount = $currentStepApprovals
                        ->filter(function ($approval) {
                            return strtoupper(
                                trim(
                                    (string) $approval->status,
                                ),
                            ) === 'WAITING';
                        })
                        ->count();

                    $requiresVendorCode = (
                        $canApprove
                        && !$hasNextStep
                        && (
                            $currentApprovalMode === 'ANY'
                            || (
                                $currentApprovalMode === 'ALL'
                                && $currentWaitingCount === 1
                            )
                        )
                    );

                    /*
                |--------------------------------------------------------------------------
                | Hak update per row
                |--------------------------------------------------------------------------
                |
                | Syarat:
                | - mempunyai vendor.update;
                | - status DRAFT atau REJECTED;
                | - creator ATAU satu department.
                |--------------------------------------------------------------------------
                */
                    $rowCanUpdate = (
                        $canUpdate
                        && in_array(
                            $vendorStatus,
                            [
                                'DRAFT',
                                'REJECTED',
                            ],
                            true,
                        )
                        && (
                            $isCreator
                            || $isSameDepartment
                        )
                    );

                    /*
                |--------------------------------------------------------------------------
                | Hak submit per row
                |--------------------------------------------------------------------------
                |
                | Syarat:
                | - mempunyai vendor.submit;
                | - status masih DRAFT;
                | - creator ATAU satu department.
                |--------------------------------------------------------------------------
                */
                    $rowCanSubmit = (
                        $canSubmit
                        && $vendorStatus === 'DRAFT'
                        && (
                            $isCreator
                            || $isSameDepartment
                        )
                    );

                    /*
                |--------------------------------------------------------------------------
                | Hak delete per row
                |--------------------------------------------------------------------------
                |
                | Syarat:
                | - mempunyai vendor.delete;
                | - status masih DRAFT;
                | - hanya creator.
                |
                | Satu department tidak otomatis boleh menghapus Vendor.
                |--------------------------------------------------------------------------
                */
                    $rowCanDelete = (
                        $canDelete
                        && $vendorStatus === 'DRAFT'
                        && $isCreator
                    );

                    /*
                |--------------------------------------------------------------------------
                | Response ownership
                |--------------------------------------------------------------------------
                */
                    $item->is_creator = $isCreator;
                    $item->is_same_department = $isSameDepartment;

                    /*
                |--------------------------------------------------------------------------
                | Response action per row
                |--------------------------------------------------------------------------
                */
                    $item->can_update = $rowCanUpdate;
                    $item->can_submit = $rowCanSubmit;
                    $item->can_delete = $rowCanDelete;
                    $item->can_approve = $canApprove;

                    /*
                |--------------------------------------------------------------------------
                | Alias untuk chip frontend
                |--------------------------------------------------------------------------
                */
                    $item->is_waiting_my_approval = $canApprove;

                    /*
                |--------------------------------------------------------------------------
                | Informasi active approval
                |--------------------------------------------------------------------------
                */
                    $item->current_step_order
                        = $currentStepOrder !== null
                        ? (int) $currentStepOrder
                        : null;

                    $item->current_approval_candidates_count
                        = $currentStepApprovals->count();

                    $item->requires_vendor_code
                        = $requiresVendorCode;

                    $item->current_approval = $userCurrentApproval
                        ? [
                            'id'
                            => $userCurrentApproval->id,

                            'step_order'
                            => (int) $userCurrentApproval->step_order,

                            'approver_type'
                            => $userCurrentApproval->approver_type,

                            'approver_id'
                            => (int) $userCurrentApproval->approver_id,

                            'approval_mode'
                            => strtoupper(
                                trim(
                                    (string) (
                                        $userCurrentApproval->approval_mode
                                        ?? 'ANY'
                                    ),
                                ),
                            ),

                            'label'
                            => $userCurrentApproval->label,

                            'status'
                            => $userCurrentApproval->status,
                        ]
                        : null;

                    return $item;
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $items,

                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),

                /*
            |--------------------------------------------------------------------------
            | Abilities global
            |--------------------------------------------------------------------------
            |
            | Abilities global hanya menunjukkan permission user.
            | Tombol per-row tetap wajib memakai can_update/can_submit/can_delete
            | dari masing-masing item.
            |--------------------------------------------------------------------------
            */
                'abilities' => [
                    'can_view' => $canView,
                    'view_scope' => $viewScope,
                    'can_create' => $canCreate,
                    'can_update' => $canUpdate,
                    'can_submit' => $canSubmit,
                    'can_delete' => $canDelete,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error(
                '[Master Vendor] Gagal memuat data vendor',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function store(Request $request)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('vendor.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Master Vendor.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $lastVendor = MasterVendor::where('kode_vendor', 'like', 'TEMP-%')
                ->orderBy('kode_vendor', 'desc')
                ->first();

            if ($lastVendor) {
                $lastNumber = (int) str_replace('TEMP-', '', $lastVendor->kode_vendor);
                $newNumber = $lastNumber + 1;
            } else {
                $newNumber = 1;
            }

            $kodeVendor = 'TEMP-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            $clean = fn($v) => is_null($v) ? null : htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $vendor = MasterVendor::create([
                'nama_vendor'       => $clean($request->nama_vendor),
                'kode_vendor'       => $kodeVendor,
                'inisial_vendor'    => $clean($request->inisial_vendor),
                'telepon'           => $clean($request->telepon),
                'fax'               => $clean($request->fax),
                'email'             => $clean($request->email),
                'jenis_perusahaan'  => $clean($request->jenis_perusahaan),
                'kategori_vendor'   => $clean($request->kategori_vendor),
                'id_department'     => $request->filled('id_department') ? (int) $request->id_department : null,
                'no_ktp'            => $clean($request->nomor_ktp),
                'alamat'            => $clean($request->alamat),

                'nama_pic'          => $clean($request->contact_nama),
                'jabatan_pic'       => $clean($request->contact_jabatan),
                'telp_pic'          => $clean($request->contact_hp),
                'email_pic'         => $clean($request->contact_email),

                'status_pkp'        => $clean($request->status_pkp),
                'no_npwp'           => $clean($request->npwp),
                'alamat_npwp'       => $clean($request->npwp_alamat),
                'no_sppkp'          => $clean($request->sppkp_nomor),
                'tgl_sppkp'         => $request->sppkp_tanggal ?: null,
                'alamat_sppkp'      => $clean($request->sppkp_alamat),
                'same_as_npwp'      => $request->same_as_npwp == "true" ? 1 : 0,

                'jenis_pembayaran'  => $clean($request->jenis_pembayaran),
                'top'               => $clean($request->top ?? 0),
                'created_by'        => $user->id,
                'updated_by'        => $user->id,
            ]);

            $vendorId = $vendor->id;

            $transaksi = json_decode($request->transaksi_ids ?? '[]', true);
            if (is_array($transaksi) && !empty($transaksi)) {
                foreach ($transaksi as $trxId) {
                    VendorTransaksi::create([
                        'vendor_id'    => $vendorId,
                        'transaksi_id' => $trxId,
                    ]);
                }
            }

            $banks = json_decode($request->banks ?? '[]', true);

            if (is_array($banks) && !empty($banks)) {
                foreach ($banks as $index => $bank) {

                    if (
                        empty($bank['bank_id']) &&
                        empty($bank['atas_nama']) &&
                        empty($bank['nomor_rekening']) &&
                        empty($bank['cabang']) &&
                        empty($bank['alamat_bank'])
                    ) {
                        continue;
                    }

                    if (empty($bank['bank_id'])) {

                        Log::error('Validasi vendor gagal: bank kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " bank wajib diisi.",
                        ], 422);
                    }

                    if (empty($bank['atas_nama'])) {

                        Log::error('Validasi vendor gagal: atas nama kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " atas nama wajib diisi.",
                        ], 422);
                    }

                    if (empty($bank['nomor_rekening'])) {

                        Log::error('Validasi vendor gagal: nomor rekening kosong', [
                            'index' => $index + 1,
                            'bank_data' => $bank,
                            'request' => $request->all(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Data bank ke-" . ($index + 1) . " nomor rekening wajib diisi.",
                        ], 422);
                    }

                    VendorBank::create([
                        'vendor_id' => $vendorId,
                        'bank_id' => $bank['bank_id'] ?? null,
                        'atas_nama' => $bank['atas_nama'] ?? null,
                        'nomor_rekening' => $bank['nomor_rekening'] ?? null,
                        'cabang' => $bank['cabang'] ?? null,
                        'alamat_bank' => $bank['alamat_bank'] ?? null,
                        'swift_code_snapshot' => $bank['swift_code_snapshot'] ?? null,
                        'is_active' => true,
                    ]);
                }
            }

            $selectedDokumen = json_decode($request->dokumen_pendukung ?? '[]', true);
            $selectedDokumen = is_array($selectedDokumen) ? array_map('intval', $selectedDokumen) : [];

            $dokumenFiles = $request->file('dokumen_files', []);

            $namaVendor = $clean($request->nama_vendor);
            $vendorSlug = Str::slug($namaVendor);

            if ($vendorSlug === '') {
                $vendorSlug = 'vendor';
            }

            if (!empty($dokumenFiles)) {
                foreach ($dokumenFiles as $docId => $files) {
                    $docId = (int) $docId;

                    if (!in_array($docId, $selectedDokumen, true)) {
                        continue;
                    }

                    $masterDoc = MasterDokumenPendukung::find($docId);
                    if (!$masterDoc) {
                        continue;
                    }

                    $docSlug = $masterDoc->slug
                        ? Str::slug($masterDoc->slug)
                        : Str::slug($masterDoc->nama_dokumen);

                    if ($docSlug === '') {
                        $docSlug = 'dokumen-' . $docId;
                    }

                    $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$vendorId}_{$vendorSlug}/{$docSlug}";

                    // Buat folder jika belum ada
                    Storage::disk('public')->makeDirectory($folder);

                    // Set permission folder jadi all access
                    $fullFolderPath = storage_path('app/public/' . $folder);

                    if (File::exists($fullFolderPath)) {
                        @chmod($fullFolderPath, 0777);
                        @chmod(dirname($fullFolderPath), 0777);
                    }

                    $files = is_array($files) ? $files : [$files];

                    foreach ($files as $file) {
                        if (!$file || !$file->isValid()) {
                            continue;
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = strtolower($file->getClientOriginalExtension());

                        $safeOriginalName = Str::slug($originalName);

                        if ($safeOriginalName === '') {
                            $safeOriginalName = 'file';
                        }

                        $filename = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                        $path = $file->storeAs($folder, $filename, 'public');

                        // Set permission file jadi all access
                        $fullFilePath = storage_path('app/public/' . $path);

                        if (File::exists($fullFilePath)) {
                            @chmod($fullFilePath, 0777);
                        }

                        VendorDokumenPendukung::create([
                            'vendor_id'  => $vendorId,
                            'dokumen_id' => $docId,
                            'file_name'  => $filename,
                            'file_path'  => $path,
                            'file_size'  => $file->getSize(),
                            'file_type'  => $file->getMimeType(),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Vendor berhasil dibuat!',
                'vendor_id' => $vendorId,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal membuat vendor', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat vendor.',
            ], 500);
        }
    }

    public function destroy(string $publicId, Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('vendor.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Master Vendor.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);
            $vendorName = $vendor->nama_vendor;

            // Ambil semua dokumen vendor untuk hapus file fisik
            $dokumenPendukung = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();

            foreach ($dokumenPendukung as $dokumen) {
                if ($dokumen->file_path && Storage::disk('public')->exists($dokumen->file_path)) {
                    Storage::disk('public')->delete($dokumen->file_path);
                }
            }

            $vendor->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Vendor {$vendorName} berhasil dihapus.",
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal menghapus vendor', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus vendor.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, string $publicId)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'is_active' => $request->boolean('is_active'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Status vendor berhasil diupdate.',
                'data' => $vendor->fresh(),
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate status vendor', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status vendor.',
            ], 500);
        }
    }

    public function show(
        string $publicId,
        Request $request,
    ): JsonResponse {
        $user = $request->user();

        $canView = $user
            && (
                $user->hasPermission('vendor.view')
                || $user->hasPermission('vendor.update')
            );

        if (!$canView) {
            return response()->json([
                'success' => false,
                'message'
                => 'Anda tidak memiliki akses untuk melihat Master Vendor.',
            ], 403);
        }

        try {
            $vendorId = (int) Crypt::decryptString(
                $publicId,
            );

            $vendor = MasterVendor::query()
                ->with([
                    'banks.masterBank',

                    'transaksi:id,vendor_id,transaksi_id',

                    'dokumenPendukung:id,vendor_id,dokumen_id,file_name,file_path',

                    'department',

                    'approvals' => function ($query) {
                        $query
                            ->orderBy('step_order')
                            ->orderBy('id');
                    },
                ])
                ->findOrFail($vendorId);

            /*
            |--------------------------------------------------------------------------
            | Audit User
            |--------------------------------------------------------------------------
            */
            $auditUserIds = collect([
                $vendor->created_by,
                $vendor->updated_by,
                $vendor->submitted_by,
            ])
                ->filter(
                    fn($id) => $id !== null
                        && (int) $id > 0,
                )
                ->map(
                    fn($id) => (int) $id,
                )
                ->unique()
                ->values();

            $auditUsers = User::query()
                ->whereIn(
                    'id',
                    $auditUserIds->all(),
                )
                ->get()
                ->keyBy('id');

            $resolveAuditUserName = function (
                mixed $userId,
            ) use (
                $auditUsers,
            ): ?string {
                if (!$userId) {
                    return null;
                }

                $auditUser = $auditUsers->get(
                    (int) $userId,
                );

                return $auditUser?->name
                    ?? $auditUser?->fullname
                    ?? $auditUser?->email
                    ?? null;
            };

            $approvalUserIds = $vendor->approvals
                ->filter(function (
                    MasterVendorApproval $approval,
                ) {
                    return strtoupper(
                        trim(
                            (string) $approval->approver_type,
                        ),
                    ) === 'USER';
                })
                ->pluck('approver_id')
                ->filter()
                ->map(
                    fn($id) => (int) $id,
                )
                ->unique()
                ->values();

            $approvalRoleIds = $vendor->approvals
                ->filter(function (
                    MasterVendorApproval $approval,
                ) {
                    return strtoupper(
                        trim(
                            (string) $approval->approver_type,
                        ),
                    ) === 'ROLE';
                })
                ->pluck('approver_id')
                ->filter()
                ->map(
                    fn($id) => (int) $id,
                )
                ->unique()
                ->values();

            $approvalUsers = User::query()
                ->whereIn(
                    'id',
                    $approvalUserIds->all(),
                )
                ->get()
                ->keyBy('id');

            $approvalRoles = Role::query()
                ->whereIn(
                    'id',
                    $approvalRoleIds->all(),
                )
                ->get()
                ->keyBy('id');

            $approvals = $vendor->approvals
                ->map(function (
                    MasterVendorApproval $approval,
                ) use (
                    $approvalUsers,
                    $approvalRoles,
                ) {
                    $approverType = strtoupper(
                        trim(
                            (string) $approval->approver_type,
                        ),
                    );

                    $status = strtoupper(
                        trim(
                            (string) $approval->status,
                        ),
                    );

                    $candidateName = null;

                    if ($approverType === 'USER') {
                        $candidateUser = $approvalUsers->get(
                            (int) $approval->approver_id,
                        );

                        $candidateName = $candidateUser?->name
                            ?? $candidateUser?->fullname
                            ?? $candidateUser?->email
                            ?? null;
                    }

                    if ($approverType === 'ROLE') {
                        $candidateRole = $approvalRoles->get(
                            (int) $approval->approver_id,
                        );

                        $candidateName = $candidateRole?->nama
                            ?? $candidateRole?->name
                            ?? $candidateRole?->kode
                            ?? null;
                    }

                    $processedBy = in_array(
                        $status,
                        [
                            'APPROVED',
                            'REJECTED',
                        ],
                        true,
                    )
                        ? (
                            $approval->approver_name_snapshot
                            ?: null
                        )
                        : null;

                    return [
                        'id'
                        => $approval->id,

                        'step_order'
                        => (int) $approval->step_order,

                        'label'
                        => $approval->label
                            ?: (
                                $candidateName
                                ?: 'Tahap '
                                . $approval->step_order
                            ),

                        'approver_type'
                        => $approverType,

                        'approver_id'
                        => $approval->approver_id
                            ? (int) $approval->approver_id
                            : null,

                        'approver_name_snapshot'
                        => $approval->approver_name_snapshot
                            ?: $candidateName,

                        'candidate_name'
                        => $candidateName,

                        'processed_by'
                        => $processedBy,

                        'approval_mode'
                        => strtoupper(
                            trim(
                                (string) (
                                    $approval->approval_mode
                                    ?: 'ANY'
                                ),
                            ),
                        ),

                        'status'
                        => $status,

                        'notes'
                        => $approval->notes,

                        'approved_at'
                        => $approval->approved_at,

                        'rejected_at'
                        => $approval->rejected_at,

                        'cancelled_at'
                        => $approval->cancelled_at,

                        'created_at'
                        => $approval->created_at,

                        'updated_at'
                        => $approval->updated_at,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Detail vendor berhasil dimuat.',

                'data' => [
                    'public_id'
                    => Crypt::encryptString(
                        (string) $vendor->id,
                    ),

                    'nama_vendor'
                    => $vendor->nama_vendor,

                    'kode_vendor'
                    => $vendor->kode_vendor,

                    /*
                    |--------------------------------------------------------------------------
                    | Audit Information
                    |--------------------------------------------------------------------------
                    */
                    'created_by'
                    => $vendor->created_by
                        ? (int) $vendor->created_by
                        : null,

                    'created_by_name'
                    => $resolveAuditUserName(
                        $vendor->created_by,
                    ),

                    'created_at'
                    => $vendor->created_at,

                    'updated_by'
                    => $vendor->updated_by
                        ? (int) $vendor->updated_by
                        : null,

                    'updated_by_name'
                    => $resolveAuditUserName(
                        $vendor->updated_by,
                    ),

                    'updated_at'
                    => $vendor->updated_at,

                    'inisial_vendor'
                    => $vendor->inisial_vendor,

                    'telepon'
                    => $vendor->telepon,

                    'fax'
                    => $vendor->fax,

                    'email'
                    => $vendor->email,

                    'jenis_perusahaan'
                    => $vendor->jenis_perusahaan,

                    'kategori_vendor'
                    => $vendor->kategori_vendor,

                    'id_department'
                    => $vendor->id_department,

                    'department' => [
                        'id'
                        => $vendor->department?->id,

                        'kode'
                        => $vendor->department?->kode,

                        'nama'
                        => $vendor->department?->nama,

                        'label'
                        => trim(
                            (
                                $vendor->department?->kode
                                ?? '-'
                            )
                                . ' - '
                                . (
                                    $vendor->department?->nama
                                    ?? '-'
                                ),
                        ),
                    ],

                    'nomor_ktp'
                    => $vendor->nomor_ktp,

                    'alamat'
                    => $vendor->alamat,

                    'is_active'
                    => $vendor->is_active,

                    'contact_nama'
                    => $vendor->nama_pic,

                    'contact_jabatan'
                    => $vendor->jabatan_pic,

                    'contact_hp'
                    => $vendor->telp_pic,

                    'contact_email'
                    => $vendor->email_pic,

                    'status_pkp'
                    => $vendor->status_pkp,

                    'npwp'
                    => $vendor->no_npwp,

                    'npwp_alamat'
                    => $vendor->alamat_npwp,

                    'sppkp_nomor'
                    => $vendor->no_sppkp,

                    'sppkp_tanggal'
                    => $vendor->tgl_sppkp
                        ? Carbon::parse(
                            $vendor->tgl_sppkp,
                        )->format('Y-m-d')
                        : null,

                    'sppkp_alamat'
                    => $vendor->alamat_sppkp,

                    'same_as_npwp'
                    => (bool) $vendor->same_as_npwp,

                    'jenis_pembayaran'
                    => $vendor->jenis_pembayaran,

                    'top'
                    => $vendor->top,

                    'status_approval'
                    => $vendor->status_approval,

                    'submitted_by'
                    => $vendor->submitted_by
                        ? (int) $vendor->submitted_by
                        : null,

                    'submitted_by_name'
                    => $resolveAuditUserName(
                        $vendor->submitted_by,
                    ),

                    'submitted_at'
                    => $vendor->submitted_at,

                    'transaksi_ids'
                    => $vendor->transaksi
                        ->pluck('transaksi_id')
                        ->values(),

                    'dokumen_ids'
                    => $vendor->dokumenPendukung
                        ->pluck('dokumen_id')
                        ->values(),

                    'dokumen_files'
                    => $vendor->dokumenPendukung
                        ->map(function ($dokumen) {
                            return [
                                'id'
                                => $dokumen->id,

                                'dokumen_id'
                                => $dokumen->dokumen_id,

                                'file_name'
                                => $dokumen->file_name,

                                'file_path'
                                => $dokumen->file_path,

                                'file_url'
                                => $dokumen->file_path
                                    ? asset(
                                        'storage/'
                                            . $dokumen->file_path,
                                    )
                                    : null,
                            ];
                        })
                        ->values(),

                    'banks'
                    => $vendor->banks
                        ->map(function ($bank) {
                            return [
                                'id'
                                => $bank->id,

                                'bank_id'
                                => $bank->bank_id,

                                'nama_bank'
                                => $bank
                                    ->masterBank
                                    ->nama_bank
                                    ?? '-',

                                'nama_bank_pendek'
                                => $bank
                                    ->masterBank
                                    ->nama_bank_pendek
                                    ?? null,

                                'kode_bank'
                                => $bank
                                    ->masterBank
                                    ->kode_bank
                                    ?? null,

                                'atas_nama'
                                => $bank->atas_nama,

                                'nomor_rekening'
                                => $bank->nomor_rekening,

                                'cabang'
                                => $bank->cabang,

                                'alamat_bank'
                                => $bank->alamat_bank,

                                'swift_code'
                                => $bank
                                    ->swift_code_snapshot
                                    ?? (
                                        $bank
                                        ->masterBank
                                        ->swift_code
                                        ?? null
                                    ),
                            ];
                        })
                        ->values(),

                    'approvals'
                    => $approvals,
                ],
            ], 200);
        } catch (DecryptException $e) {
            Log::warning(
                'Public ID vendor tidak valid',
                [
                    'public_id'
                    => $publicId,

                    'message'
                    => $e->getMessage(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid',
            ], 404);
        } catch (
            \Illuminate\Database\Eloquent\ModelNotFoundException $e
        ) {
            Log::warning(
                'Vendor tidak ditemukan',
                [
                    'public_id'
                    => $publicId,

                    'message'
                    => $e->getMessage(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                'Gagal memuat detail vendor',
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
                'message' => 'Gagal memuat data vendor',

                'debug'
                => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }


    public function update(Request $request, string $publicId)
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('vendor.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Master Vendor.',
            ], 403);
        }

        $request->validate([
            'nama_vendor' => ['required', 'string', 'max:255'],
            'inisial_vendor' => ['required', 'string', 'max:50'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'jenis_perusahaan' => ['required'],
            'kategori_vendor' => ['required'],
            'nomor_ktp' => ['nullable', 'string', 'max:100'],
            'alamat' => ['nullable', 'string'],
            'id_department' => ['required', 'integer'],

            'contact_nama' => ['nullable', 'string', 'max:255'],
            'contact_jabatan' => ['nullable', 'string', 'max:255'],
            'contact_hp' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],

            'status_pkp' => ['nullable'],
            'npwp' => ['nullable', 'string', 'max:100'],
            'npwp_alamat' => ['nullable', 'string'],
            'sppkp_nomor' => ['nullable', 'string', 'max:100'],
            'sppkp_tanggal' => ['nullable', 'date'],
            'sppkp_alamat' => ['nullable', 'string'],
            'same_as_npwp' => ['nullable', 'boolean'],

            'jenis_pembayaran' => ['nullable'],
            'top' => ['nullable'],

            'transaksi_ids' => ['nullable', 'array'],
            'transaksi_ids.*' => ['integer'],

            'dokumen_ids' => ['nullable', 'array'],
            'dokumen_ids.*' => ['integer'],

            'banks' => ['nullable'],
            'banks.*.id' => ['nullable', 'integer'],
            'banks.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'banks.*.atas_nama' => ['nullable', 'string', 'max:255'],
            'banks.*.nomor_rekening' => ['nullable', 'string', 'max:100'],
            'banks.*.cabang' => ['nullable', 'string', 'max:255'],
            'banks.*.alamat_bank' => ['nullable', 'string'],
            'banks.*.swift_code' => ['nullable', 'string', 'max:100'],

            'dokumen_existing_ids' => ['nullable', 'array'],
            'dokumen_existing_ids.*' => ['nullable', 'array'],
            'dokumen_existing_ids.*.*' => ['integer'],

            'dokumen_files' => ['nullable', 'array'],
            'dokumen_files.*' => ['nullable', 'array'],
            'dokumen_files.*.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $clean = fn($v) => is_null($v) ? null : htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);
            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'nama_vendor' => $clean($request->nama_vendor),
                'inisial_vendor' => $clean($request->inisial_vendor),
                'telepon' => $clean($request->telepon),
                'fax' => $clean($request->fax),
                'email' => $clean($request->email),
                'jenis_perusahaan' => $clean($request->jenis_perusahaan),
                'kategori_vendor' => $clean($request->kategori_vendor),
                'id_department' => $clean($request->id_department),
                'no_ktp' => $clean($request->nomor_ktp),
                'alamat' => $clean($request->alamat),

                'nama_pic' => $clean($request->contact_nama),
                'jabatan_pic' => $clean($request->contact_jabatan),
                'telp_pic' => $clean($request->contact_hp),
                'email_pic' => $clean($request->contact_email),

                'status_pkp' => $clean($request->status_pkp),
                'no_npwp' => $clean($request->npwp),
                'alamat_npwp' => $clean($request->npwp_alamat),
                'no_sppkp' => $clean($request->sppkp_nomor),
                'tgl_sppkp' => $request->filled('sppkp_tanggal')
                    ? Carbon::parse($request->sppkp_tanggal)->format('Y-m-d')
                    : null,
                'alamat_sppkp' => $clean($request->sppkp_alamat),
                'same_as_npwp' => $clean($request->boolean('same_as_npwp')),

                'jenis_pembayaran' => $clean($request->jenis_pembayaran),
                'top' => $request->filled('top') ? $request->top : null,
                'updated_by' => $user->id,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 1. Sinkron transaksi vendor
        |--------------------------------------------------------------------------
        */
            $transaksiIds = collect($request->input('transaksi_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            VendorTransaksi::where('vendor_id', $vendor->id)
                ->whereNotIn('transaksi_id', $transaksiIds->all())
                ->delete();

            foreach ($transaksiIds as $transaksiId) {
                VendorTransaksi::updateOrCreate(
                    [
                        'vendor_id' => $vendor->id,
                        'transaksi_id' => $transaksiId,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Sinkron bank vendor
            |--------------------------------------------------------------------------
            */
            $banks = $request->input('banks', []);

            if (is_string($banks)) {
                $decodedBanks = json_decode($banks, true);
                $banks = is_array($decodedBanks) ? $decodedBanks : [];
            }

            $banks = collect($banks);

            $bankIdsToKeep = [];

            foreach ($banks as $index => $bankData) {
                $isEmpty =
                    blank($bankData['bank_id'] ?? null) &&
                    blank($bankData['atas_nama'] ?? null) &&
                    blank($bankData['nomor_rekening'] ?? null) &&
                    blank($bankData['cabang'] ?? null) &&
                    blank($bankData['alamat_bank'] ?? null);

                if ($isEmpty) {
                    continue;
                }

                if (blank($bankData['bank_id'] ?? null)) {
                    Log::warning('Update vendor gagal: bank_id kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' nama bank wajib dipilih.',
                    ], 422);
                }

                if (blank($bankData['atas_nama'] ?? null)) {
                    Log::warning('Update vendor gagal: atas_nama kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' atas nama wajib diisi.',
                    ], 422);
                }

                if (blank($bankData['nomor_rekening'] ?? null)) {
                    Log::warning('Update vendor gagal: nomor_rekening kosong', [
                        'vendor_id' => $vendor->id,
                        'index' => $index + 1,
                        'bank_data' => $bankData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Data bank ke-' . ($index + 1) . ' nomor rekening wajib diisi.',
                    ], 422);
                }

                $payload = [
                    'bank_id' => $bankData['bank_id'] ?? null,
                    'atas_nama' => $bankData['atas_nama'] ?? null,
                    'nomor_rekening' => $bankData['nomor_rekening'] ?? null,
                    'cabang' => $bankData['cabang'] ?? null,
                    'alamat_bank' => $bankData['alamat_bank'] ?? null,
                    'swift_code_snapshot' => $bankData['swift_code_snapshot'] ?? null,
                    'is_active' => true,
                ];

                if (!empty($bankData['id'])) {
                    $bank = VendorBank::where('vendor_id', $vendor->id)
                        ->where('id', $bankData['id'])
                        ->first();

                    if ($bank) {
                        $bank->update($payload);

                        $bankIdsToKeep[] = $bank->id;
                        continue;
                    }
                }

                $newBank = VendorBank::create([
                    'vendor_id' => $vendor->id,
                ] + $payload);

                $bankIdsToKeep[] = $newBank->id;
            }

            if (!empty($bankIdsToKeep)) {
                VendorBank::where('vendor_id', $vendor->id)
                    ->whereNotIn('id', $bankIdsToKeep)
                    ->delete();
            } else {
                VendorBank::where('vendor_id', $vendor->id)->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Sinkron dokumen pendukung
            |--------------------------------------------------------------------------
            */

            $pathsToDelete = [];

            $dokumenIds = collect($request->input('dokumen_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $dokumenExistingIds = collect($request->input('dokumen_existing_ids', []));

            /*
            |--------------------------------------------------------------------------
            | 1. Hapus record DB file lama yang tidak dipertahankan
            |    File fisik jangan dihapus dulu, hanya tampung path-nya.
            |--------------------------------------------------------------------------
            */
            foreach ($dokumenIds as $dokumenId) {
                $keepIdsForDokumen = collect($dokumenExistingIds->get((string) $dokumenId, []))
                    ->filter(fn($id) => $id !== null && $id !== '')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->all();

                $oldFilesQuery = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                    ->where('dokumen_id', $dokumenId);

                $filesToDelete = !empty($keepIdsForDokumen)
                    ? (clone $oldFilesQuery)->whereNotIn('id', $keepIdsForDokumen)->get()
                    : $oldFilesQuery->get();

                foreach ($filesToDelete as $file) {
                    if ($file->file_path) {
                        $pathsToDelete[] = $file->file_path;
                    }

                    $file->delete();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Hapus record DB dari dokumen yang sudah tidak dipilih
            |--------------------------------------------------------------------------
            */
            $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                ->when(
                    $dokumenIds->isNotEmpty(),
                    fn($query) => $query->whereNotIn('dokumen_id', $dokumenIds->all())
                )
                ->get();

            if ($dokumenIds->isEmpty()) {
                $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();
            }

            foreach ($dokumenYangDihapusTotal as $file) {
                if ($file->file_path) {
                    $pathsToDelete[] = $file->file_path;
                }

                $file->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Simpan file baru
            |--------------------------------------------------------------------------
            */
            $uploadedDokumenFiles = $request->file('dokumen_files', []);

            $namaVendor = $clean($request->nama_vendor);
            $vendorSlug = Str::slug($namaVendor);

            if ($vendorSlug === '') {
                $vendorSlug = 'vendor';
            }

            foreach ($uploadedDokumenFiles as $dokumenId => $files) {
                $dokumenId = (int) $dokumenId;

                if (!$dokumenIds->contains($dokumenId)) {
                    continue;
                }

                $masterDoc = MasterDokumenPendukung::find($dokumenId);

                if (!$masterDoc) {
                    continue;
                }

                $docSlug = $masterDoc->slug
                    ? Str::slug($masterDoc->slug)
                    : Str::slug($masterDoc->nama_dokumen);

                if ($docSlug === '') {
                    $docSlug = 'dokumen-' . $dokumenId;
                }

                $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$vendor->id}_{$vendorSlug}/{$docSlug}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                    @chmod(dirname($fullFolderPath), 0777);
                }

                $files = is_array($files) ? $files : [$files];

                foreach ($files as $file) {
                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());

                    $safeOriginalName = Str::slug($originalName);

                    if ($safeOriginalName === '') {
                        $safeOriginalName = 'file';
                    }

                    $filename = now()->format('YmdHis') . '_' . uniqid() . '_' . $safeOriginalName . '.' . $extension;

                    $storedPath = $file->storeAs($folder, $filename, 'public');

                    $fullFilePath = storage_path('app/public/' . $storedPath);

                    if (File::exists($fullFilePath)) {
                        @chmod($fullFilePath, 0777);
                    }

                    VendorDokumenPendukung::create([
                        'vendor_id'  => $vendor->id,
                        'dokumen_id' => $dokumenId,
                        'file_name'  => $filename,
                        'file_path'  => $storedPath,
                        'file_size'  => $file->getSize(),
                        'file_type'  => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            foreach (array_unique($pathsToDelete) as $oldPath) {
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil diperbarui.',
                'data' => [
                    'public_id' => Crypt::encryptString((string) $vendor->id),
                ],
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate vendor', [
                'public_id' => $publicId,
                'request' => $request->except(['dokumen_files']),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate vendor.',
            ], 500);
        }
    }

    public function dropdownSelect(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->where('status_approval', 'APPROVED')
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function dropdownSelectForPurchaseRequest(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('status_approval')
                        ->orWhere('status_approval', '!=', 'REJECTED');
                })
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function dropdownSelectForPurchaseOrder(Request $request)
    {
        try {
            $query = MasterVendor::query()
                ->where('is_active', true)
                ->where('status_approval', 'APPROVED')
                ->orderBy('nama_vendor', 'ASC');

            // if ($request->filled('id_department')) {
            //     $query->where('id_department', (int) $request->id_department);
            // }

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('email_vendor', 'ILIKE', "%{$search}%");
                });
            }

            $vendors = $query->get()->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'value' => $vendor->id,
                    'id_department' => $vendor->id_department,

                    'nama_vendor' => $vendor->nama_vendor,
                    'status_pkp' => $vendor->status_pkp ?? 'NON_PKP',

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'title' => $vendor->nama_vendor,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $vendors,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Vendor] Dropdown select error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
                'data' => [],
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function submit(
        Request $request,
        string $publicId,
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User login tidak ditemukan.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt dan lock vendor
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Vendor hanya dapat disubmit dari status DRAFT
        |--------------------------------------------------------------------------
        */
            if (
                strtoupper(
                    trim((string) $vendor->status_approval),
                ) !== 'DRAFT'
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message'
                    => 'Vendor hanya dapat disubmit jika status masih DRAFT.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Generate snapshot approval
        |--------------------------------------------------------------------------
        |
        | Generator akan:
        | - membaca approval flow MASTER_VENDOR;
        | - menghapus snapshot lama;
        | - membuat kandidat approval;
        | - mengaktifkan seluruh kandidat step pertama sebagai WAITING;
        | - membuat step berikutnya sebagai PENDING.
        |--------------------------------------------------------------------------
        */
            $this->vendorApprovalGeneratorService
                ->generate($vendor);

            /*
        |--------------------------------------------------------------------------
        | Update status header Master Vendor
        |--------------------------------------------------------------------------
        */
            $vendor->status_approval = 'PENDING REVIEW';
            $vendor->submitted_at = now();
            $vendor->submitted_by = $user->id;
            $vendor->save();

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notification dan email setelah commit
        |--------------------------------------------------------------------------
        |
        | Tidak lagi mengirim manual dari controller agar tidak duplikat.
        |--------------------------------------------------------------------------
        */
            $this->vendorApprovalNotificationService
                ->notifyCurrentApprovers($vendor);

            $vendor->refresh();

            return response()->json([
                'success' => true,
                'message'
                => 'Vendor berhasil disubmit untuk proses approval.',
                'data' => [
                    'id' => $vendor->id,
                    'public_id' => $vendor->encrypted_id,
                    'nama_vendor' => $vendor->nama_vendor,
                    'status_approval' => $vendor->status_approval,
                    'submitted_at' => $vendor->submitted_at,
                    'submitted_by' => $vendor->submitted_by,
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
                    ?? 'Data Master Vendor tidak valid.',
                'errors' => $errors,
            ], 422);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Master Vendor tidak valid.',
            ], 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('[Master Vendor] Submit error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal submit Master Vendor.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function approve(
        Request $request,
        string $publicId,
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'kode_vendor' => [
                    'nullable',
                    'string',
                    'max:30',
                ],
            ], [
                'notes.string'
                => 'Catatan approval harus berupa teks.',

                'notes.max'
                => 'Catatan approval maksimal 2000 karakter.',
            ]);

            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User login tidak ditemukan.',
                ], 401);
            }

            $kodeVendor = isset($validated['kode_vendor'])
                ? strtoupper(
                    trim(
                        strip_tags(
                            (string) $validated['kode_vendor'],
                        ),
                    ),
                )
                : null;

            if ($kodeVendor === '') {
                $kodeVendor = null;
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt dan lock Master Vendor
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Status vendor harus PENDING REVIEW
        |--------------------------------------------------------------------------
        */
            $vendorStatus = strtoupper(
                trim((string) $vendor->status_approval),
            );

            if ($vendorStatus !== 'PENDING REVIEW') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message'
                    => 'Vendor hanya dapat diapprove jika status masih PENDING REVIEW.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Sanitasi catatan approval
        |--------------------------------------------------------------------------
        */
            $notes = null;

            if ($request->filled('notes')) {
                $notes = htmlspecialchars(
                    strip_tags(
                        trim(
                            (string) $request->input('notes'),
                        ),
                    ),
                    ENT_QUOTES,
                    'UTF-8',
                );

                if ($notes === '') {
                    $notes = null;
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Proses approval melalui service
        |--------------------------------------------------------------------------
        |
        | Service menangani:
        | - validasi approver USER/ROLE;
        | - active step;
        | - mode ANY/ALL;
        | - SKIPPED untuk peer mode ANY;
        | - aktivasi step berikutnya;
        | - final status APPROVED.
        |--------------------------------------------------------------------------
        */
            $result = $this->vendorApprovalService->approve(
                vendor: $vendor,
                user: $user,
                notes: $notes,
                kodeVendor: $kodeVendor,
            );

            $vendor->refresh();

            $approval = $result['approval'] ?? null;

            $stepCompleted = (
                $result['step_completed'] ?? false
            ) === true;

            $hasNextStep = (
                $result['has_next_step'] ?? false
            ) === true;

            $isFinalApproved = (
                $result['is_final_approved'] ?? false
            ) === true;

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notifikasi/email setelah transaksi berhasil
        |--------------------------------------------------------------------------
        |
        | Error notifikasi tidak boleh membuat approval yang sudah commit
        | dianggap gagal.
        |--------------------------------------------------------------------------
        */
            try {
                /*
            | Jika step selesai dan terdapat step berikutnya,
            | kirim notification/email kepada approver step berikut.
            */
                if ($stepCompleted && $hasNextStep) {
                    $this->vendorApprovalNotificationService
                        ->notifyCurrentApprovers($vendor);
                }

                /*
            | Creator diberi tahu saat satu step selesai.
            |
            | Untuk mode ALL, creator tidak menerima notifikasi setiap satu
            | kandidat approve; notifikasi dikirim setelah step selesai.
            */
                if ($isFinalApproved) {
                    $this->vendorApprovalNotificationService
                        ->notifyCreatorApproved(
                            vendor: $vendor,
                            approvedBy: $user,
                            isFinalApproved: true,
                        );
                }
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Master Vendor] Notifikasi setelah approve gagal',
                    [
                        'vendor_id' => $vendor->id,
                        'approved_by' => $user->id,
                        'step_completed' => $stepCompleted,
                        'has_next_step' => $hasNextStep,
                        'is_final_approved' => $isFinalApproved,
                        'message' => $notificationError->getMessage(),
                        'file' => $notificationError->getFile(),
                        'line' => $notificationError->getLine(),
                    ],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Pesan response
        |--------------------------------------------------------------------------
        */
            if ($isFinalApproved) {
                $message = 'Master Vendor berhasil diapprove.';
            } elseif ($stepCompleted && $hasNextStep) {
                $message
                    = 'Tahap approval Master Vendor berhasil diselesaikan dan diteruskan ke approver berikutnya.';
            } elseif ($stepCompleted) {
                $message
                    = 'Tahap approval Master Vendor berhasil diselesaikan.';
            } else {
                $message
                    = 'Approval Anda berhasil disimpan dan masih menunggu approver lainnya.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,

                'data' => [
                    'id' => $vendor->id,

                    'public_id'
                    => $vendor->encrypted_id,

                    'nama_vendor'
                    => $vendor->nama_vendor,

                    /*
                | Tetap PENDING REVIEW selama belum final.
                */
                    'status_approval'
                    => $vendor->status_approval,

                    'approval_status'
                    => $approval?->status,

                    'step_completed'
                    => $stepCompleted,

                    'has_next_step'
                    => $hasNextStep,

                    'is_final_approved'
                    => $isFinalApproved,

                    'current_step_order'
                    => $result['current_step_order'] ?? null,

                    'next_step_order'
                    => $result['next_step_order'] ?? null,

                    'approved_at'
                    => $approval?->approved_at,

                    'approved_by'
                    => $approval?->approver_name_snapshot,

                    'notes'
                    => $approval?->notes,

                    'kode_vendor' => $vendor->kode_vendor,
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
                    ?? 'Approval Master Vendor tidak valid.',

                'errors' => $errors,
            ], 422);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Master Vendor tidak valid.',
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Data Master Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Master Vendor] Approve error',
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
                'message' => 'Gagal approve Master Vendor.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function reject(
        Request $request,
        string $publicId,
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $request->validate([
                'notes' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ], [
                'notes.required'
                => 'Catatan penolakan wajib diisi.',

                'notes.string'
                => 'Catatan penolakan harus berupa teks.',

                'notes.max'
                => 'Catatan penolakan maksimal 2000 karakter.',
            ]);

            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'User login tidak ditemukan.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | Decrypt dan lock Master Vendor
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString($publicId);

            $vendor = MasterVendor::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Status vendor harus PENDING REVIEW
        |--------------------------------------------------------------------------
        */
            $vendorStatus = strtoupper(
                trim((string) $vendor->status_approval),
            );

            if ($vendorStatus !== 'PENDING REVIEW') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message'
                    => 'Vendor hanya dapat direject jika status masih PENDING REVIEW.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Sanitasi catatan reject
        |--------------------------------------------------------------------------
        */
            $notes = htmlspecialchars(
                strip_tags(
                    trim(
                        (string) $request->input('notes'),
                    ),
                ),
                ENT_QUOTES,
                'UTF-8',
            );

            if ($notes === '') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message'
                    => 'Catatan penolakan wajib diisi.',

                    'errors' => [
                        'notes' => [
                            'Catatan penolakan wajib diisi.',
                        ],
                    ],
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Proses reject melalui service
        |--------------------------------------------------------------------------
        |
        | Service menangani:
        | - validasi user sebagai approver aktif;
        | - row actor menjadi REJECTED;
        | - approval lain PENDING/WAITING menjadi CANCELLED;
        | - status vendor menjadi REJECTED.
        |--------------------------------------------------------------------------
        */
            $result = $this->vendorApprovalService->reject(
                vendor: $vendor,
                user: $user,
                notes: $notes,
            );

            $vendor->is_active = false;
            $vendor->save();

            $vendor->refresh();

            $approval = $result['approval'] ?? null;

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notification/email creator setelah transaksi berhasil
        |--------------------------------------------------------------------------
        */
            try {
                $this->vendorApprovalNotificationService
                    ->notifyCreatorRejected(
                        vendor: $vendor,
                        rejectedBy: $user,
                        notes: $notes,
                    );
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Master Vendor] Notifikasi setelah reject gagal',
                    [
                        'vendor_id' => $vendor->id,
                        'rejected_by' => $user->id,
                        'message' => $notificationError->getMessage(),
                        'file' => $notificationError->getFile(),
                        'line' => $notificationError->getLine(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Master Vendor berhasil direject.',

                'data' => [
                    'id' => $vendor->id,

                    'public_id'
                    => $vendor->encrypted_id,

                    'nama_vendor'
                    => $vendor->nama_vendor,

                    'status_approval'
                    => $vendor->status_approval,

                    'is_active'
                    => (bool) $vendor->is_active,

                    'approval_status'
                    => $approval?->status,

                    'rejected_at'
                    => $approval?->rejected_at,

                    'rejected_by'
                    => $approval?->approver_name_snapshot,

                    'reject_notes'
                    => $approval?->notes,

                    'step_order'
                    => $approval?->step_order,

                    'current_step_order'
                    => $result['current_step_order'] ?? null,
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
                    ?? 'Penolakan Master Vendor tidak valid.',

                'errors' => $errors,
            ], 422);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'ID Master Vendor tidak valid.',
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Data Master Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                '[Master Vendor] Reject error',
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
                'message' => 'Gagal reject Master Vendor.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function userCanAccessVendorByScope(
        User $user,
        MasterVendor $vendor,
        string $scope,
    ): bool {
        $scope = strtoupper(
            trim($scope),
        );

        return match ($scope) {
            'ALL' => true,

            'OWN_DATA' => (int) $vendor->created_by
                === (int) $user->id,

            'OWN_DEPARTMENT' => (
                !empty($user->department_id)
                && (int) $vendor->id_department
                === (int) $user->department_id
            ),

            default => false,
        };
    }
}
