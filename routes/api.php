<?php

// use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
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
use App\Http\Controllers\Api\Master\UnitController as MasterUnitController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\MasterBankController;
use App\Http\Controllers\UnitController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/my-menus', [MenuController::class, 'myMenus']);
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
    Route::apiResource('master/users', UserController::class);
    Route::apiResource('master/roles', RoleController::class);

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
    Route::get('/provinsi', [WilayahAngkutController::class, 'provinsi']);
    Route::get('/kabupaten/{provinsi}', [WilayahAngkutController::class, 'kabupaten']);
    Route::get('/area', [HargaPertaminaController::class, 'area']);
    Route::get('/produk', [HargaPertaminaController::class, 'produk']);

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

        // Vendor
        Route::get('vendor/dropdown-select', [MasterVendorController::class, 'dropdownSelect']);
        Route::patch('vendor/{id}/status', [MasterVendorController::class, 'updateStatus']);

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
    });

    Route::prefix('transaction')->group(function () {
        // ===================== PURCHASE REQUEST =========================
        Route::get('purchase-request/dropdown-approved', [PurchaseRequestController::class, 'dropdownApproved']);
        Route::get('purchase-request/{publicId}/edit', [PurchaseRequestController::class, 'edit']);
        Route::patch('purchase-request/{publicId}/submit', [PurchaseRequestController::class, 'submit']);
        Route::apiResource('purchase-request', PurchaseRequestController::class)
            ->parameters([
                'purchase-request' => 'publicId',
            ]);

        // ===================== PURCHASE ORDER =========================
        Route::get('purchase-order/{publicId}/edit', [PurchaseOrderController::class, 'edit']);
        Route::get('purchase-order/{publicId}/print', [PurchaseOrderController::class, 'print']);
        Route::patch('purchase-order/{publicId}/submit', [PurchaseOrderController::class, 'submit']);
        Route::patch('purchase-order/{publicId}/approve', [PurchaseOrderController::class, 'approve']);
        Route::apiResource('purchase-order', PurchaseOrderController::class)
            ->parameters([
                'purchase-order' => 'publicId',
            ]);
    });
});
