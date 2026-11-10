<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\OrderPaymentMethodController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseItemController;
use App\Http\Controllers\PurchasePaymentController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UniteController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;

Route::middleware('web')->group(function () {
    // Public Routes
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect.alias');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

    Route::get('/test-auth', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        // 🔑 បន្ថែម Route នេះសម្រាប់ឲ្យ React ទាញយក User profile តាមរយៈ Sanctum Cookie
        Route::get('/user', function (Request $request) {
            return response()->json($request->user());
        });

        // Admin Only
        Route::middleware('role:Admin')->group(function () {
            Route::get('/brand/export/excel', [BrandController::class, 'exportExcel']);
            Route::get('/brand/export/pdf', [BrandController::class, 'exportPdf']);
            Route::get('/brand/stats', [BrandController::class, 'stats']);
            Route::post('/brand/import', [BrandController::class, 'import']);
            Route::apiResource('users', UserController::class);
            Route::apiResource('brand', BrandController::class);
            // exportExcel
            Route::get('/category/export/pdf', [CategoryController::class, 'exportPdf']);
            Route::get('/category/export/excel', [CategoryController::class, 'exportExcel']);
            Route::get('/category/state', [CategoryController::class, 'state']);
            Route::post('/category/import', [CategoryController::class, 'import']);
            Route::apiResource('category', CategoryController::class);
            Route::apiResource('bank', BankController::class)->except(['index']);
            Route::apiResource('orderPaymentMethod', OrderPaymentMethodController::class);
            Route::apiResource('unite', UniteController::class);
        });

        // Admin + Manager
        Route::middleware('role:Admin,Manager')->group(function () {
            Route::get('/bank', [BankController::class, 'index']);
            Route::post('/supplier/import', [SupplierController::class, 'import']);
            Route::get('/supplier/export/excel', [SupplierController::class, 'exportExcel']);
            Route::get('/supplier/export/pdf', [SupplierController::class, 'exportPdf']);
            Route::get('/supplier/stats', [SupplierController::class, 'stats']);
            Route::middleware('throttle:products')->group(function () {
                Route::get('/product/export/pdf', [ProductController::class, 'exportPdf']);
                Route::get('/product/export/excel', [ ProductController::class, 'exportExcel' ]);
                Route::post('/product/import', [ ProductController::class, 'import' ]);
                Route::get('/product/state', [ ProductController::class, 'state' ]);
                Route::apiResource('product', ProductController::class);
            });
            Route::apiResource('supplier', SupplierController::class);
            Route::get('/purchase/state', [PurchaseController::class, 'getstate']);
            Route::apiResource('purchase', PurchaseController::class);
            Route::get('/goods-received', [GoodsReceiptController::class, 'index']);
            Route::get('/goods-received/{id}', [GoodsReceiptController::class, 'show']);
            Route::post('/goods-received', [GoodsReceiptController::class, 'store']);
            Route::post('/purchase/{purchase}/receive', [GoodsReceiptController::class, 'receivePurchase']);
            Route::post('/purchase/{purchase}/payments', [PurchasePaymentController::class, 'store']);
            Route::apiResource('purchaseItem', PurchaseItemController::class);
            Route::apiResource('stockMovement', StockMovementController::class);
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::post('/warehouses', [WarehouseController::class, 'store']);
            Route::get('/warehouses/{id}/stocks', [WarehouseController::class, 'stocks']);
            Route::get('/stock-transfers/summary', [StockTransferController::class, 'summary']);
            Route::get('/stock-transfers/stats', [StockTransferController::class, 'stats']);
            Route::get('/stock-transfers', [StockTransferController::class, 'index']);
            Route::post('/stock-transfers', [StockTransferController::class, 'store']);
            Route::get('/stock-transfers/{id}', [StockTransferController::class, 'show']);
            Route::put('/stock-transfers/{id}', [StockTransferController::class, 'update']);
            Route::patch('/stock-transfers/{id}', [StockTransferController::class, 'update']);
            Route::delete('/stock-transfers/{id}', [StockTransferController::class, 'destroy']);
            Route::patch('/stock-transfers/{id}/status', [StockTransferController::class, 'changeStatus']);
            Route::get('/stock-transfer-requests', [StockTransferController::class, 'requestIndex']);
            Route::post('/stock-transfer-requests', [StockTransferController::class, 'requestStore']);
            Route::get('/stock-transfer-requests/{id}', [StockTransferController::class, 'requestShow']);
            Route::put('/stock-transfer-requests/{id}', [StockTransferController::class, 'requestUpdate']);
            Route::patch('/stock-transfer-requests/{id}', [StockTransferController::class, 'requestUpdate']);
            Route::delete('/stock-transfer-requests/{id}', [StockTransferController::class, 'requestDestroy']);
        });

        // Admin + Manager + Staff
        Route::middleware('role:Admin,Manager,Staff')->group(function () {
            Route::post('/customer/import', [CustomerController::class, 'import']);
            Route::get('/customer/export/excel', [CustomerController::class, 'exportExcel']);
            Route::get('/customer/export/pdf', [CustomerController::class, 'exportPdf']);
            Route::get('/customer/stats', [CustomerController::class, 'stats']);
            Route::apiResource('customer', CustomerController::class);
            Route::get('/sales', [SaleController::class, 'index']);
            Route::post('/sales', [SaleController::class, 'store']);
            Route::get('/sales/{id}', [SaleController::class, 'show']);
            Route::apiResource('order', OrderController::class);
            Route::apiResource('orderItem', OrderItemController::class);
            Route::get('/orders', [OrderController::class, 'pendingIndex']);
            Route::post('/orders/pending', [OrderController::class, 'storePending']);
            Route::patch('/orders/{id}/specs', [OrderController::class, 'updatePendingSpecs']);
            Route::post('/orders/{id}/deposit', [OrderController::class, 'addDeposit']);
            Route::post('/orders/{id}/convert-to-sale', [OrderController::class, 'convertToSale']);
            Route::post('/orders/{id}/cancel', [OrderController::class, 'cancelPending']);
        });
    });
});
