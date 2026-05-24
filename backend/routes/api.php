<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\Tickets\TicketController;
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

                Route::get('/customers', [CustomerController::class, 'index'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
                    ->middleware('organization.role:owner,admin');

                Route::get('/tickets', [TicketController::class, 'index'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/tickets', [TicketController::class, 'store'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::patch('/tickets/{ticket}/priority', [TicketController::class, 'updatePriority'])
                    ->middleware('organization.role:owner,admin');
                Route::patch('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
                    ->middleware('organization.role:owner,admin');
                Route::post('/tickets/{ticket}/notes', [TicketController::class, 'addNote'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/tickets/{ticket}/messages', [TicketController::class, 'addMessage'])
                    ->middleware('organization.role:owner,admin,agent');
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
