<?php

use App\Http\Controllers\Api\Admin\OrderAdminController;
use App\Http\Controllers\Api\ArticleRequestController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesController;
use Illuminate\Support\Facades\Route;

/*
| Same-origin JSON API for the Vue frontend, loaded from routes/web.php under
| /api with the web (session + CSRF) and auth middleware. Contract: docs/API.md.
*/

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/{order:uuid}', [OrderController::class, 'show'])->name('orders.show');
Route::put('/orders/{uuid}', [OrderController::class, 'upsert'])->whereUuid('uuid')->name('orders.upsert');
Route::delete('/orders/{order:uuid}', [OrderController::class, 'destroy'])->withTrashed()->name('orders.destroy');

Route::get('/clients', [CatalogController::class, 'clientDelta'])->name('clients.delta');
Route::get('/articles', [CatalogController::class, 'articleDelta'])->name('articles.delta');
Route::get('/clients/search', [CatalogController::class, 'clients'])->name('clients.search');
Route::get('/clients/possible-duplicate', [CatalogController::class, 'possibleDuplicate'])->name('clients.duplicate');
Route::put('/clients/{uuid}', [ClientController::class, 'upsert'])->whereUuid('uuid')->name('clients.upsert');
Route::get('/clients/{client}/recent-orders', [SalesController::class, 'recent'])->name('clients.recent-orders');
Route::get('/clients/{client}/history', [SalesController::class, 'history'])->name('clients.history');
Route::get('/deliveries', [SalesController::class, 'deliveryDelta'])->name('deliveries.delta');
Route::get('/delivery-lines', [SalesController::class, 'lineDelta'])->name('delivery-lines.delta');
Route::get('/articles/search', [CatalogController::class, 'articles'])->name('articles.search');
Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
Route::put('/article-requests/{uuid}', [ArticleRequestController::class, 'upsert'])->name('article-requests.upsert');
Route::get('/article-requests/{uuid}', [ArticleRequestController::class, 'show'])->name('article-requests.show');
Route::get('/paytypes', [CatalogController::class, 'paytypes'])->name('paytypes.index');
Route::get('/reports/stock', [ReportController::class, 'stock'])->name('reports.stock');

Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/orders', [OrderAdminController::class, 'index'])->name('orders.index');
    Route::post('/orders/approve', [OrderAdminController::class, 'bulkApprove'])->name('orders.bulk-approve');
    // In both modes: with Evoliz running, only for orders approved without it (ApprovalService::exportableHere).
    Route::post('/orders/mark-exported', [OrderAdminController::class, 'markExported'])->name('orders.mark-exported');
    Route::post('/orders/{order:uuid}/unmark-exported', [OrderAdminController::class, 'unmarkExported'])->name('orders.unmark-exported');
    Route::post('/orders/{order:uuid}/approve', [OrderAdminController::class, 'approve'])->name('orders.approve');
    Route::post('/orders/{order:uuid}/reject', [OrderAdminController::class, 'reject'])->name('orders.reject');
    // In both modes: standalone, "Réessayer" settles a failed order for the export (ApprovalService::retry).
    Route::post('/orders/{order:uuid}/retry', [OrderAdminController::class, 'retry'])->name('orders.retry');
    Route::post('/orders/{order:uuid}/send-to-evoliz', [OrderAdminController::class, 'sendToEvoliz'])->middleware('sync.evoliz')->name('orders.send-to-evoliz');
    Route::post('/orders/{order:uuid}/sms', [OrderAdminController::class, 'resendSms'])->name('orders.sms');
});
