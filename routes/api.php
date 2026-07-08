<?php

// use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccurateController;
use App\Http\Controllers\Api\GainLossInventoryController;
use App\Http\Controllers\Api\GoodsReceiptInventoryController;
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
use App\Http\Controllers\Api\Master\UserPermissionController;

use App\Http\Controllers\Api\Master\VolumeController;
use App\Http\Controllers\Api\Master\WilayahAngkutController;

use App\Http\Controllers\Api\Master\TransportirMobilController;

use App\Http\Controllers\Api\Master\OngkosAngkutController;
use App\Http\Controllers\Api\Master\CustomerController;
use App\Http\Controllers\Api\Master\GroupCabangController;
use App\Http\Controllers\Api\Master\MasterDokumenPendukungController;
use App\Http\Controllers\Api\Master\MasterKeteranganTransaksiController;
use App\Http\Controllers\Api\Master\MasterVendorController;
use App\Http\Controllers\Api\OngkosAngkutKapalController;
use App\Http\Controllers\Api\Master\PermissionController;
use App\Http\Controllers\Api\Master\PermissionModuleController;
use App\Http\Controllers\Api\Master\RolePermissionController;
use App\Http\Controllers\Api\PurchaseOrderInventoryController;
use App\Http\Controllers\Api\Master\UnitController as MasterUnitController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\ShippingInstructionController;
use App\Http\Controllers\MasterBankController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Api\GoodsReturnController;
use App\Http\Controllers\Api\Dashboard\DashboardModuleController;
use App\Http\Controllers\Api\Dashboard\PurchaseOrderDashboardController;
use App\Http\Controllers\Monitoring\LogViewerController;

Route::post('/auth/login', [AuthController::class, 'login']);
// routes/api.php

Route::post('/auth/sso', [AuthController::class, 'sso']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/me/permissions', [AuthController::class, 'permissions']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/my-menus', [MenuController::class, 'myMenus']);
    Route::put('/account/change-password', [AccountController::class, 'changePassword']);
    Route::get(
        'master/cabang/options',
        [CabangController::class, 'dropdownSelect']
    );

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
    Route::apiResource('master/oa-kapal', OngkosAngkutKapalController::class);

    //get API
    Route::get('/provinsi', [WilayahAngkutController::class, 'provinsi']);
    Route::get('/kabupaten/{provinsi}', [WilayahAngkutController::class, 'kabupaten']);
    Route::get('/area', [HargaPertaminaController::class, 'area']);
    Route::get('/produk', [HargaPertaminaController::class, 'produk']);
    Route::get('/terminal', [TerminalController::class, 'terminal']);
    Route::get('/transportir', [TransportirController::class, 'transportir']);
    Route::get('/oa-kapal', [OngkosAngkutKapalController::class, 'oaKapal']);

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

    Route::prefix('monitoring')
        ->group(function () {
            Route::get(
                '/logs',
                [LogViewerController::class, 'index'],
            );
        });


    // ===================== DATA DASHBOARD =====================
    Route::prefix('dashboard')
        ->name('dashboard.')
        ->group(function () {
            Route::get(
                '/module-groups',
                [DashboardModuleController::class, 'groups'],
            )->name('module-groups');

            Route::get(
                '/modules',
                [DashboardModuleController::class, 'index'],
            )->name('modules');

            Route::get(
                '/purchase-order',
                [PurchaseOrderDashboardController::class, 'index'],
            )->name('purchase-order');
        });

    // ===================== DATA MASTER =====================
    Route::prefix('master')->group(function () {

        // Module Permission
        Route::get(
            'permission-modules',
            [PermissionModuleController::class, 'index'],
        );

        Route::post(
            'permission-modules',
            [
                PermissionModuleController::class,
                'store',
            ],
        );

        Route::post(
            'permission-modules/{id}/permissions',
            [
                PermissionModuleController::class,
                'storePermission',
            ],
        )->whereNumber('id');

        Route::get(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'show',
            ],
        )->whereNumber('id');

        Route::put(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'update',
            ],
        )->whereNumber('id');

        Route::put(
            'permission-modules/{moduleId}/permissions/{permissionId}',
            [
                PermissionModuleController::class,
                'updatePermission',
            ],
        )
            ->whereNumber('moduleId')
            ->whereNumber('permissionId');

        Route::delete(
            'permission-modules/{moduleId}/permissions/{permissionId}',
            [
                PermissionModuleController::class,
                'destroyPermission',
            ],
        )
            ->whereNumber('moduleId')
            ->whereNumber('permissionId');

        Route::delete(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'destroy',
            ],
        )->whereNumber('id');

        // Permissions
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('permissions/{permission}', [PermissionController::class, 'show']);

        Route::get('role-permissions', [RolePermissionController::class, 'index']);
        Route::put('role-permissions/bulk', [RolePermissionController::class, 'bulkUpdate']);

        Route::get(
            'user-permissions',
            [UserPermissionController::class, 'index'],
        );

        Route::put(
            'user-permissions/bulk',
            [UserPermissionController::class, 'bulkUpdate'],
        );

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

            Route::post(
                'purchase-request/{publicId}/print-url',
                [PurchaseRequestController::class, 'generatePrintUrl']
            );

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

            Route::post(
                'purchase-order/{publicId}/print-url',
                [PurchaseOrderController::class, 'generatePrintUrl']
            );

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

            Route::get(
                'goods-receive/{publicId}/return-history',
                [
                    GoodsReceiveController::class,
                    'returnHistory',
                ],
            );

            Route::apiResource(
                'goods-receive',
                GoodsReceiveController::class,
            )->parameters([
                'goods-receive' => 'publicId',
            ]);

            /*
            |--------------------------------------------------------------------------
            | GOODS RETURN
            |--------------------------------------------------------------------------
            */

            Route::get(
                'goods-return/reasons',
                [GoodsReturnController::class, 'reasons'],
            );

            Route::get(
                'goods-return/create-data',
                [GoodsReturnController::class, 'createData'],
            );

            Route::patch(
                'goods-return/{publicId}/post',
                [GoodsReturnController::class, 'post'],
            );

            Route::get(
                'goods-return/replacement-receivable',
                [GoodsReturnController::class, 'replacementReceivable'],
            );

            Route::patch(
                'goods-return/{publicId}/cancel',
                [GoodsReturnController::class, 'cancel'],
            );

            Route::get(
                'goods-return/{publicId}/edit',
                [GoodsReturnController::class, 'edit'],
            );

            Route::apiResource(
                'goods-return',
                GoodsReturnController::class,
            )->parameters([
                'goods-return' => 'publicId',
            ]);
        });

    //API ACCURATE
    // Route::get('accurate/products', [AccurateController::class, 'products']);
    // Route::get('accurate/accounts', [AccurateController::class, 'accounts']);
    // Route::get('accurate/detail-po', [AccurateController::class, 'getDetailPO']);

    // ===================== PURCHASE ORDER INVENTORY =========================
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('purchase-order/export', [PurchaseOrderInventoryController::class, 'export']);
        Route::apiResource('purchase-order', PurchaseOrderInventoryController::class);
        Route::post('purchase-order/{id}/approve-cfo', [PurchaseOrderInventoryController::class, 'approveCFO']);
        Route::post('purchase-order/{id}/approve-ceo', [PurchaseOrderInventoryController::class, 'approveCEO']);
        Route::get('purchase-order/print/{id}', [PurchaseOrderInventoryController::class, 'print']);
        Route::get('purchase-order/print-gain-loss/{id}', [PurchaseOrderInventoryController::class, 'printGainLoss']);
        Route::get('purchase-order/{id}/history', [PurchaseOrderInventoryController::class, 'history']);
        Route::post('purchase-order/{id}/cancel', [PurchaseOrderInventoryController::class, 'cancel']);
        Route::post('purchase-order/{id}/close', [PurchaseOrderInventoryController::class, 'close']);

        //Goods Receipt
        Route::apiResource('goods-receipt', GoodsReceiptInventoryController::class);
        Route::get('goods-receipt/history/{id}', [GoodsReceiptInventoryController::class, 'grHistory']);

        //Gain Loss
        Route::apiResource('gain-loss', GainLossInventoryController::class);
        Route::post('gain-loss/approval', [GainLossInventoryController::class, 'approval']);

        //Shipping Instruction
        Route::apiResource('shipping-instruction', ShippingInstructionController::class);
        Route::get('shipping-instruction/by-po/{id}', [ShippingInstructionController::class, 'byPo']);
        Route::post('shipping-instruction/{id}/cancel', [ShippingInstructionController::class, 'cancel']);
        Route::post('shipping-instruction/{id}/approve', [ShippingInstructionController::class, 'approve']);
        Route::get('shipping-instruction/print/{id}', [ShippingInstructionController::class, 'print']);
    });
});
Route::get(
    '/transaction/purchase-request/{publicId}/print-signed',
    [PurchaseRequestController::class, 'printSigned']
)->name('transaction.purchase-request.print-signed')->middleware('signed');

Route::get(
    '/transaction/purchase-order/{publicId}/print-signed',
    [PurchaseOrderController::class, 'printSigned']
)->name('transaction.purchase-order.print-signed')->middleware('signed');
