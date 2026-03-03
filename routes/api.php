<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Orders\OrderController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Webhooks\WooCommerceWebhookController;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json(['ok' => true]));

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    Route::middleware(['jwt'])->group(function (): void {
        Route::get('/me', static fn () => response()->json(['user' => request()->user()]));

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders/sync', [OrderController::class, 'sync']);
        Route::post('/orders/{order}/status', [OrderController::class, 'updateStatus']);

        Route::post('/users', [UserController::class, 'store']);
    });

    Route::post(
        '/webhooks/woocommerce',
        [WooCommerceWebhookController::class, 'handle'],
    );
});

