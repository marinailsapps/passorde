<?php

use App\Http\Api\ApiAliases;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ArticleAttributeController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\EvolizUserController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\OrderExportController;
use App\Http\Controllers\Admin\PaytypeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\SyncController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AppShellController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderPageController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Precached by the service worker; carries no user data.
Route::get('/app-shell', AppShellController::class)->name('app-shell');
Route::get('/manifest.webmanifest', [AppShellController::class, 'manifest'])->name('manifest');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('home');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/stock', [ReportController::class, 'stock'])->name('reports.stock');
    Route::get('/reports/stock.xlsx', [ReportController::class, 'export'])->name('reports.stock.export');
    Route::get('/reports/write-offs', [ReportController::class, 'writeOffs'])->name('reports.write-offs');
    Route::get('/reports/write-offs.xlsx', [ReportController::class, 'writeOffsExport'])->name('reports.write-offs.export');
    Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
    Route::get('/reports/sales.xlsx', [ReportController::class, 'salesExport'])->name('reports.sales.export');
    // The catalogue is read by everyone; only admins sync it (admin group below).
    Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::get('/catalog.xlsx', [CatalogController::class, 'export'])->name('catalog.export');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::get('/orders', [OrderPageController::class, 'index'])->name('orders.index');
    Route::get('/orders/new', [OrderPageController::class, 'create'])->name('orders.create');
    Route::get('/orders/{uuid}', [OrderPageController::class, 'show'])->whereUuid('uuid')->name('orders.show');
    Route::get('/orders/{uuid}/edit', [OrderPageController::class, 'edit'])->whereUuid('uuid')->name('orders.edit');

    // 120 requests a minute per user: far above the mirror and outbox bursts.
    Route::prefix('api')->name('api.')->middleware('throttle:120,1')->group(base_path('routes/api.php'));

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/orders', [OrderPageController::class, 'adminIndex'])->name('orders.index');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/password', [UserController::class, 'setPassword'])->name('users.password');

        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::post('/clients/sync', [ClientController::class, 'sync'])->middleware('sync.evoliz')->name('clients.sync');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/push', [ClientController::class, 'push'])->middleware('sync.evoliz')->name('clients.push');
        Route::post('/clients/{client}/changes/{change}/retry', [ClientController::class, 'retryChange'])->middleware('sync.evoliz')->name('clients.changes.retry');

        Route::redirect('/catalog', '/catalog');
        Route::post('/catalog/sync', [CatalogController::class, 'sync'])->middleware('sync.evoliz')->name('catalog.sync');

        Route::get('/article-attributes', [ArticleAttributeController::class, 'index'])->name('article-attributes.index');
        Route::patch('/article-attributes/{attribute}', [ArticleAttributeController::class, 'update'])->name('article-attributes.update');
        Route::post('/article-attributes/bulk-category', [ArticleAttributeController::class, 'bulkCategory'])->name('article-attributes.bulk-category');
        Route::get('/category-thresholds', [ArticleAttributeController::class, 'thresholds'])->name('category-thresholds.index');
        Route::put('/category-thresholds', [ArticleAttributeController::class, 'updateThresholds'])->name('category-thresholds.update');

        Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
        Route::delete('/stock-adjustments/{adjustment}', [StockAdjustmentController::class, 'destroy'])->name('stock-adjustments.destroy');
        Route::post('/stock-adjustments/{adjustment}/retry', [StockAdjustmentController::class, 'retry'])->withTrashed()->middleware('sync.evoliz')->name('stock-adjustments.retry');

        Route::middleware('sync.evoliz')->group(function () {
            Route::get('/evoliz-users', [EvolizUserController::class, 'index'])->name('evoliz-users.index');
            Route::patch('/evoliz-users/{evolizUser}', [EvolizUserController::class, 'update'])->name('evoliz-users.update');

            Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
            Route::post('/sync/{kind}', [SyncController::class, 'run'])->name('sync.run');
        });

        Route::middleware('sync.file')->group(function () {
            Route::get('/orders/export.csv', [OrderExportController::class, 'csv'])->name('orders.export.csv');
            Route::get('/orders/export.xlsx', [OrderExportController::class, 'xlsx'])->name('orders.export.xlsx');
            Route::patch('/catalog/{article}', [CatalogController::class, 'update'])->name('catalog.update');
            Route::get('/import', [ImportController::class, 'index'])->name('import.index');
            Route::get('/import/templates/{kind}.csv', [ImportController::class, 'template'])->whereIn('kind', ['articles', 'clients'])->name('import.template');
            Route::post('/import/{kind}', [ImportController::class, 'store'])->whereIn('kind', ['articles', 'clients'])->name('import.store');
        });

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/evoliz', [SettingsController::class, 'updateEvoliz'])->name('settings.evoliz.update');
        Route::post('/settings/evoliz/test', [SettingsController::class, 'testConnection'])->name('settings.evoliz.test');

        Route::get('/paytypes', [PaytypeController::class, 'index'])->name('paytypes.index');
        Route::patch('/paytypes/{paytype}', [PaytypeController::class, 'update'])->name('paytypes.update');
    });
});

// The installation's older addresses, if its profile names any (aliases.routes; App\Http\Api\ApiAliases).
ApiAliases::routes();
