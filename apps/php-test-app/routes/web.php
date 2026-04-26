<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebhookLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/pay', [PaymentController::class, 'index'])->name('pay.index');
Route::post('/pay', [PaymentController::class, 'create'])->name('pay.create');

Route::get('/status', [TransactionController::class, 'index'])->name('status.index');
Route::post('/status', [TransactionController::class, 'query'])->name('status.query');

Route::get('/refund', [RefundController::class, 'index'])->name('refund.index');
Route::post('/refund', [RefundController::class, 'process'])->name('refund.process');

Route::match(['GET', 'POST'], '/webhook/{gateway}', [WebhookController::class, 'receive'])
    ->name('webhook.receive');

Route::get('/webhooks', fn () => view('webhooks'))->name('webhooks.page');
Route::get('/api/webhooks', [WebhookLogController::class, 'index'])->name('webhooks.list');
Route::delete('/api/webhooks', [WebhookLogController::class, 'clear'])->name('webhooks.clear');
