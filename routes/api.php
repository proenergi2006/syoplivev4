<?php

// use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccurateController;
use App\Http\Controllers\Api\GoodsReceiveController;
use App\Http\Controllers\Api\Master\ApprovalFlowController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\Master\WilayahController;
use App\Http\Controllers\Api\Master\CabangController;
use App\Http\Controllers\Api\Master\DepartmentController;
use App\Http\Controllers\Api\Master\ProvinsiController;
use App\Http\Controllers\Api\Master\KabupatenController;
use App\Http\Controllers\Api\Master\VendorController;
use App\Http\Controllers\Api\Master\AreaController;
use App\Http\Controllers\Api\Master\HargaJualController;
use App\Http\Controllers\Api\Master\HargaPertaminaController;
use App\Http\Controllers\Api\Master\PbbkbController;
use App\Http\Controllers\Api\Master\TerminalController;
use App\Http\Controllers\Api\Master\UserController;
use App\Http\Controllers\Api\Master\RoleController;
use App\Http\Controllers\Api\Master\ProdukController;
use App\Http\Controllers\Api\Master\RoleMenuController;
use App\Http\Controllers\Api\Master\TransportirController;
use App\Http\Controllers\Api\Master\TransportirSopirController;

use App\Http\Controllers\Api\Master\VolumeController;
use App\Http\Controllers\Api\Master\WilayahAngkutController;

use App\Http\Controllers\Api\Master\TransportirMobilController;

use App\Http\Controllers\Api\Master\OngkosAngkutController;
use App\Http\Controllers\Api\Master\CustomerController;
use App\Http\Controllers\Api\Master\GroupCabangController;
use App\Http\Controllers\Api\Master\MasterDokumenPendukungController;
use App\Http\Controllers\Api\Master\MasterKeteranganTransaksiController;
use App\Http\Controllers\Api\Master\MasterVendorController;
use App\Http\Controllers\Api\Master\PermissionController;
use App\Http\Controllers\Api\Master\PermissionModuleController;
use App\Http\Controllers\Api\Master\RolePermissionController;
use App\Http\Controllers\Api\PurchaseOrderInventoryController;
use App\Http\Controllers\Api\Master\UnitController as MasterUnitController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\MasterBankController;
use App\Http\Controllers\UnitController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/me/permissions', [AuthController::class, 'permissions']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/my-menus', [MenuController::class, 'myMenus']);
    Route::put('/account/change-password', [AccountController::class, 'changePassword']);
    Route::get('master/cabang/options', [CabangController::class, 'options']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/read', [NotificationController::class, 'deleteRead']);

    Route::apiResource('master/wilayah', WilayahController::class);
    Route::apiResource('master/provinsi', ProvinsiController::class);
    Route::apiResource('master/kabupaten', KabupatenController::class);
    // Route::apiResource('master/vendor', VendorController::class);
    Route::apiResource('master/area', AreaController::class);
    Route::apiResource('master/terminal', TerminalController::class);
    Route::get('master/roles', [RoleController::class, 'index']);
    Route::prefix('master/user')->middleware('auth:sanctum')->group(function () {
        Route::get('/check-signature', [UserController::class, 'checkUserSignature']);
        Route::post('/store-signature', [UserController::class, 'storeUserSignature']);
    });

    Route::get('master/dropdown/users', [UserController::class, 'dropdown']);
    Route::get('master/dropdown/roles', [RoleController::class, 'dropdown']);

    Route::apiResource('master/produk', ProdukController::class);
    Route::apiResource('master/pbbkb', PbbkbController::class);

    Route::get('/master/role-menus', [RoleMenuController::class, 'index']);
    Route::post('/master/role-menus', [RoleMenuController::class, 'store']);
    Route::apiResource('master/transportir', TransportirController::class);

    Route::apiResource('master/sopir', TransportirSopirController::class);
    Route::apiResource('master/volume', VolumeController::class);
    Route::apiResource('master/wilayah-angkut', WilayahAngkutController::class);
    Route::apiResource('master/harga-jual', HargaJualController::class);
    Route::apiResource('master/harga-pertamina', HargaPertaminaController::class);
    //get API
    Route::get('/provinsi', [WilayahAngkutController::class, 'provinsi']);
    Route::get('/kabupaten/{provinsi}', [WilayahAngkutController::class, 'kabupaten']);
    Route::get('/area', [HargaPertaminaController::class, 'area']);
    Route::get('/produk', [HargaPertaminaController::class, 'produk']);
    Route::get('/terminal', [TerminalController::class, 'terminal']);

    Route::get('master/transportir-mobil', [TransportirMobilController::class, 'index']);
    Route::post('master/transportir-mobil', [TransportirMobilController::class, 'store']);
    Route::get('master/transportir-mobil/{id}', [TransportirMobilController::class, 'show']);
    Route::post('master/transportir-mobil/{id}', [TransportirMobilController::class, 'update']);
    Route::delete('master/transportir-mobil/{id}', [TransportirMobilController::class, 'destroy']);

    Route::apiResource('master/ongkos-angkut', OngkosAngkutController::class);
    Route::apiResource('master/customers', CustomerController::class);

    Route::apiResource('master/banks', MasterBankController::class);
    Route::patch('master/banks/{id}/status', [MasterBankController::class, 'toggleStatus']);

    Route::get('master/keterangan-transaksi', [MasterKeteranganTransaksiController::class, 'index']);
    Route::get('master/dokumen-pendukung', [MasterDokumenPendukungController::class, 'index']);

    Route::get('/units/dropdown-select', [UnitController::class, 'dropdownSelect']);
    Route::apiResource('/units', UnitController::class);

    // ===================== DATA MASTER =====================
    Route::prefix('master')->group(function () {

        // Module Permission
        Route::get(
            'permission-modules',
            [PermissionModuleController::class, 'index'],
        );

        // Permissions
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('permissions/{permission}', [PermissionController::class, 'show']);

        Route::get('role-permissions', [RolePermissionController::class, 'index']);
        Route::put('role-permissions/bulk', [RolePermissionController::class, 'bulkUpdate']);

        // Users
        Route::apiResource('users', UserController::class);
        // Role
        Route::apiResource('roles', RoleController::class);

        // Vendor
        Route::get('vendor/dropdown-select', [MasterVendorController::class, 'dropdownSelect']);
        Route::get('vendor/dropdown-pr', [MasterVendorController::class, 'dropdownSelectForPurchaseRequest']);
        Route::get('vendor/dropdown-po', [MasterVendorController::class, 'dropdownSelectForPurchaseOrder']);
        Route::patch('vendor/{id}/status', [MasterVendorController::class, 'updateStatus']);
        Route::patch('vendor/{publicId}/submit', [MasterVendorController::class, 'submit']);
        Route::patch('vendor/{publicId}/approve', [MasterVendorController::class, 'approve']);
        Route::patch('vendor/{publicId}/reject', [MasterVendorController::class, 'reject']);
        Route::apiResource('vendor', MasterVendorController::class)
            ->parameters([
                'vendor' => 'publicId',
            ]);

        // Group Cabang
        Route::apiResource('group-cabang', GroupCabangController::class);

        // Cabang
        Route::get('cabang/dropdown-select', [CabangController::class, 'dropdownSelect']);
        Route::apiResource('cabang', CabangController::class);

        // Department
        Route::get(
            'department/dropdown-select',
            [DepartmentController::class, 'dropdownSelect']
        );

        Route::apiResource(
            'department',
            DepartmentController::class
        );

        // Approval Flow
        Route::post('/approval-flows', [ApprovalFlowController::class, 'store']);
        Route::get('/approval-flows/{publicId}', [ApprovalFlowController::class, 'show']);
        Route::put('/approval-flows/{publicId}', [ApprovalFlowController::class, 'update']);
        Route::get('/approval-flows', [ApprovalFlowController::class, 'index']);
        Route::patch('/approval-flows/{publicId}/toggle-status', [ApprovalFlowController::class, 'toggleStatus']);
        Route::delete('/approval-flows/{publicId}', [ApprovalFlowController::class, 'destroy']);
    });

    Route::prefix('transaction')
        ->name('transaction.')
        ->group(function () {
            /*
            |--------------------------------------------------------------------------
            | PURCHASE REQUEST
            |--------------------------------------------------------------------------
            | Semua URL di dalam group ini otomatis diawali /transaction.
            |--------------------------------------------------------------------------
            */

            Route::get(
                'purchase-request/dropdown-approved',
                [PurchaseRequestController::class, 'dropdownApproved'],
            );

            Route::get(
                'purchase-request/{publicId}/edit',
                [PurchaseRequestController::class, 'edit'],
            );

            Route::get(
                'purchase-request/{publicId}/print',
                [PurchaseRequestController::class, 'print'],
            );

            Route::patch(
                'purchase-request/{publicId}/submit',
                [PurchaseRequestController::class, 'submit'],
            );

            Route::patch(
                'purchase-request/{publicId}/approve',
                [PurchaseRequestController::class, 'approve'],
            );

            Route::patch(
                'purchase-request/{publicId}/reject',
                [PurchaseRequestController::class, 'reject'],
            );

            Route::apiResource(
                'purchase-request',
                PurchaseRequestController::class,
            )->parameters([
                'purchase-request' => 'publicId',
            ]);

            /*
        |--------------------------------------------------------------------------
        | PURCHASE ORDER
        |--------------------------------------------------------------------------
        */

            Route::get(
                'purchase-order/dropdown-receivable',
                [PurchaseOrderController::class, 'dropdownReceivable'],
            );

            Route::get(
                'purchase-order/{publicId}/receivable-items',
                [PurchaseOrderController::class, 'receivableItems'],
            );

            Route::get(
                'purchase-order/{publicId}/edit',
                [PurchaseOrderController::class, 'edit'],
            );

            Route::get(
                'purchase-order/{publicId}/print',
                [PurchaseOrderController::class, 'print'],
            );

            Route::patch(
                'purchase-order/{publicId}/submit',
                [PurchaseOrderController::class, 'submit'],
            );

            Route::patch(
                'purchase-order/{publicId}/approve',
                [PurchaseOrderController::class, 'approve'],
            );

            Route::patch(
                'purchase-order/{publicId}/reject',
                [PurchaseOrderController::class, 'reject'],
            );

            Route::apiResource(
                'purchase-order',
                PurchaseOrderController::class,
            )->parameters([
                'purchase-order' => 'publicId',
            ]);

            /*
        |--------------------------------------------------------------------------
        | GOODS RECEIVE
        |--------------------------------------------------------------------------
        */

            Route::patch(
                'goods-receive/{publicId}/post',
                [GoodsReceiveController::class, 'post'],
            );

            Route::patch(
                'goods-receive/{publicId}/cancel',
                [GoodsReceiveController::class, 'cancel'],
            );

            Route::get(
                'goods-receive/{publicId}/edit',
                [GoodsReceiveController::class, 'edit'],
            );

            Route::apiResource(
                'goods-receive',
                GoodsReceiveController::class,
            )->parameters([
                'goods-receive' => 'publicId',
            ]);
        });

    //API ACCURATE
    // Route::get('accurate/products', [AccurateController::class, 'products']);
    // Route::get('accurate/accounts', [AccurateController::class, 'accounts']);
    // Route::get('accurate/detail-po', [AccurateController::class, 'getDetailPO']);

    // // ===================== PURCHASE ORDER INVERNTORY =========================
    // Route::prefix('inventory')->group(function () {
    //     Route::apiResource('purchase-order', PurchaseOrderInventoryController::class);
    //     Route::post(
    //         'purchase-order/{id}/approve-cfo',
    //         [PurchaseOrderInventoryController::class, 'approveCFO']
    //     );
    // });
});
