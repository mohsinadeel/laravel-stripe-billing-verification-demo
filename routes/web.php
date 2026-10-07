<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Middleware\RequireStripeWebhookSecret;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::get('/', [DemoController::class, 'index'])->middleware('auth')->name('demo.index');
Route::post('/checkout', [DemoController::class, 'checkout'])->middleware('auth')->name('demo.checkout');
Route::get('/protected', [DemoController::class, 'protected'])->middleware('auth')->name('demo.protected');

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])
    ->middleware([RequireStripeWebhookSecret::class, VerifyWebhookSignature::class])
    ->name('cashier.webhook');

Route::get('/login', [AuthController::class, 'create'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware(['guest', 'throttle:6,1'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
