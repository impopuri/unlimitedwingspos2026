<?php

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/posetivacwingspos');

Route::prefix('posetivacwingspos')->group(function (): void {
    Route::get('/', [PosController::class, 'index'])->name('login');
    Route::post('/login', [PosController::class, 'login'])->middleware('throttle:10,1')->name('pos.login');
    Route::post('/logout', [PosController::class, 'logout'])->middleware('auth')->name('logout');

    Route::middleware('auth')->group(function (): void {
        Route::get('/app', [PosController::class, 'app'])->name('pos.app');
        Route::get('/api/bootstrap', [PosController::class, 'bootstrap'])->name('pos.bootstrap');
        Route::get('/api/admin/cashflow', [AdminOperationsController::class, 'cashflow'])->middleware('role:superadmin')->name('pos.admin.cashflow');
        Route::get('/api/admin/promotions', [AdminOperationsController::class, 'promotions'])->middleware('role:superadmin')->name('pos.admin.promotions');
        Route::post('/api/admin/promotions', [AdminOperationsController::class, 'createPromotion'])->middleware('role:superadmin')->name('pos.admin.promotions.create');
        Route::patch('/api/admin/promotions/{promotion}', [AdminOperationsController::class, 'updatePromotion'])->middleware('role:superadmin')->name('pos.admin.promotions.update');
        Route::delete('/api/admin/promotions/{promotion}', [AdminOperationsController::class, 'deletePromotion'])->middleware('role:superadmin')->name('pos.admin.promotions.delete');
        Route::put('/api/admin/receipt-settings', [AdminOperationsController::class, 'saveReceiptSettings'])->middleware('role:superadmin')->name('pos.admin.receipt-settings');
        Route::post('/api/admin/receipt-qr', [AdminOperationsController::class, 'uploadReceiptQr'])->middleware('role:superadmin')->name('pos.admin.receipt-qr');
        Route::post('/api/admin/reset-sales', [AdminOperationsController::class, 'resetSalesData'])->middleware('role:superadmin')->name('pos.admin.reset-sales');
        Route::post('/api/admin/expenses', [AdminOperationsController::class, 'addExpense'])->middleware('role:superadmin')->name('pos.admin.expenses');
        Route::patch('/api/admin/expenses/{expense}', [AdminOperationsController::class, 'updateExpense'])->middleware('role:superadmin')->name('pos.admin.expenses.update');
        Route::delete('/api/admin/expenses/{expense}', [AdminOperationsController::class, 'voidExpense'])->middleware('role:superadmin')->name('pos.admin.expenses.void');
        Route::post('/api/admin/orders/{order}/refunds', [AdminOperationsController::class, 'requestRefund'])->middleware('role:superadmin,cashier')->name('pos.admin.refunds.request');
        Route::patch('/api/admin/refunds/{refund}', [AdminOperationsController::class, 'reviewRefund'])->middleware('role:superadmin')->name('pos.admin.refunds.review');
        Route::get('/api/admin/catalog', [AdminOperationsController::class, 'catalog'])->middleware('role:superadmin')->name('pos.admin.catalog');
        Route::post('/api/admin/categories', [AdminOperationsController::class, 'saveCategory'])->middleware('role:superadmin')->name('pos.admin.categories.create');
        Route::patch('/api/admin/categories/{category}', [AdminOperationsController::class, 'saveCategory'])->middleware('role:superadmin')->name('pos.admin.categories.update');
        Route::post('/api/admin/flavors', [AdminOperationsController::class, 'saveFlavor'])->middleware('role:superadmin')->name('pos.admin.flavors.create');
        Route::patch('/api/admin/flavors/{flavor}', [AdminOperationsController::class, 'saveFlavor'])->middleware('role:superadmin')->name('pos.admin.flavors.update');
        Route::get('/api/admin/inventory', [AdminOperationsController::class, 'inventory'])->middleware('role:superadmin')->name('pos.admin.inventory');
        Route::post('/api/admin/inventory/items', [AdminOperationsController::class, 'createInventoryItem'])->middleware('role:superadmin')->name('pos.admin.inventory.create');
        Route::post('/api/admin/inventory/adjustments', [AdminOperationsController::class, 'adjustInventory'])->middleware('role:superadmin')->name('pos.admin.inventory.adjust');
        Route::post('/api/admin/products', [AdminOperationsController::class, 'createProduct'])->middleware('role:superadmin')->name('pos.admin.products.create');
        Route::patch('/api/admin/products/{product}', [AdminOperationsController::class, 'updateProduct'])->middleware('role:superadmin')->name('pos.admin.products.update');
        Route::get('/api/admin/sales.csv', [AdminOperationsController::class, 'salesCsv'])->middleware('role:superadmin')->name('pos.admin.sales.csv');
        Route::get('/api/admin/sales-report', [AdminOperationsController::class, 'salesReport'])->middleware('role:superadmin')->name('pos.admin.sales.report');
        Route::post('/api/admin/cash-drawers', [AdminOperationsController::class, 'openCashDrawer'])->middleware('role:superadmin,cashier')->name('pos.admin.cash-drawers.open');
        Route::post('/api/admin/cash-drawers/{drawer}/close', [AdminOperationsController::class, 'closeCashDrawer'])->middleware('role:superadmin,cashier')->name('pos.admin.cash-drawers.close');
        Route::post('/api/admin/cash-entries', [AdminOperationsController::class, 'cashEntry'])->middleware('role:superadmin,cashier')->name('pos.admin.cash-entries.create');
        Route::get('/api/cash-drawer', [AdminOperationsController::class, 'cashDrawerStatus'])->middleware('role:superadmin,cashier')->name('pos.cash-drawer');
        Route::get('/api/admin/refunds', [AdminOperationsController::class, 'refunds'])->middleware('role:superadmin')->name('pos.admin.refunds');
        Route::get('/api/admin/void-requests', [AdminOperationsController::class, 'voidRequests'])->middleware('role:superadmin')->name('pos.admin.voids');
        Route::patch('/api/admin/void-requests/{voidRequest}', [AdminOperationsController::class, 'reviewVoid'])->middleware('role:superadmin')->name('pos.admin.voids.review');
        Route::get('/api/admin/audit-logs', [AdminOperationsController::class, 'auditLogs'])->middleware('role:superadmin')->name('pos.admin.audit');
        Route::get('/api/orders/recent', [AdminOperationsController::class, 'recentOrders'])->middleware('role:superadmin,cashier')->name('pos.orders.recent');
        Route::get('/api/admin/accounts', [AdminOperationsController::class, 'accounts'])->middleware('role:superadmin')->name('pos.admin.accounts');
        Route::post('/api/admin/accounts', [AdminOperationsController::class, 'createAccount'])->middleware('role:superadmin')->name('pos.admin.accounts.create');
        Route::patch('/api/admin/accounts/{user}', [AdminOperationsController::class, 'updateAccount'])->middleware('role:superadmin')->name('pos.admin.accounts.update');
        Route::get('/api/kitchen', [PosController::class, 'kitchen'])->middleware('role:superadmin,kitchen')->name('pos.kitchen');
        Route::get('/api/receipts/{order}', [PosController::class, 'receipt'])->name('pos.receipt');
        Route::post('/api/orders', [PosController::class, 'checkout'])->middleware('role:superadmin,cashier')->name('pos.checkout');
        Route::post('/api/orders/{order}/void-requests', [AdminOperationsController::class, 'requestVoid'])->middleware('role:superadmin,cashier')->name('pos.orders.void.request');
        Route::post('/api/tables', [PosController::class, 'addTable'])->middleware('role:superadmin')->name('pos.tables.add');
        Route::post('/api/unli-sessions', [PosController::class, 'startSession'])->middleware('role:superadmin,cashier')->name('pos.unli.start');
        Route::post('/api/unli-sessions/{session}/rounds', [PosController::class, 'confirmRound'])->middleware('role:superadmin,cashier')->name('pos.unli.round');
        Route::post('/api/unli-sessions/{session}/billout', [PosController::class, 'billout'])->middleware('role:superadmin,cashier')->name('pos.unli.billout');
        Route::patch('/api/kitchen/{ticket}', [PosController::class, 'updateKitchen'])->middleware('role:superadmin,kitchen')->name('pos.kitchen.update');
        Route::post('/api/kitchen/{ticket}/archive', [PosController::class, 'archiveKitchen'])->middleware('role:superadmin,kitchen')->name('pos.kitchen.archive');
        Route::get('/api/kitchen/history', [PosController::class, 'kitchenHistory'])->middleware('role:superadmin,kitchen')->name('pos.kitchen.history');
    });
});
