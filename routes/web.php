<?php

use App\Http\Controllers\DemoController;
use App\Http\Middleware\RequireStripeWebhookSecret;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::get('/', [DemoController::class, 'index'])->name('demo.index');
Route::post('/checkout', [DemoController::class, 'checkout'])->name('demo.checkout');
Route::get('/protected', [DemoController::class, 'protected'])->name('demo.protected');

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])
    ->middleware([RequireStripeWebhookSecret::class, VerifyWebhookSignature::class])
    ->name('cashier.webhook');
