<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationMembershipController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::post('/organizations/join', [OrganizationMembershipController::class, 'join']);

        Route::prefix('organizations/{organization}')
            ->middleware('organization.access')
            ->group(function (): void {
                Route::get('/', [OrganizationController::class, 'show']);

                Route::get('/members', [OrganizationMembershipController::class, 'members'])
                    ->middleware('organization.role:owner,admin');

                Route::patch('/members/{member}', [OrganizationMembershipController::class, 'updateRole'])
                    ->middleware('organization.role:owner');
            });
    });
});

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Route not found.',
        'errors' => [],
    ], 404);
});
