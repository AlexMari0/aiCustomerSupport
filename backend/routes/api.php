<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\Tickets\TicketController;
use App\Http\Controllers\Api\V1\KnowledgeBase\KnowledgeCategoryController;
use App\Http\Controllers\Api\V1\KnowledgeBase\KnowledgeArticleController;
use App\Http\Controllers\Api\V1\AI\AiReplyController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes([
    'prefix' => 'v1',
    'middleware' => ['auth:sanctum'],
]);

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
                    ->middleware('organization.role:owner,admin,agent');
                Route::patch('/tickets/{ticket}/category', [TicketController::class, 'updateCategory'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/tickets/{ticket}/classify', [TicketController::class, 'classify'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::patch('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
                    ->middleware('organization.role:owner,admin');
                Route::post('/tickets/{ticket}/notes', [TicketController::class, 'addNote'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/tickets/{ticket}/messages', [TicketController::class, 'addMessage'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/tickets/{ticket}/ai-suggest', [AiReplyController::class, 'suggest'])
                    ->middleware('organization.role:owner,admin,agent');

                // Knowledge Base Categories
                Route::get('/knowledge-base/categories', [KnowledgeCategoryController::class, 'index'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/knowledge-base/categories', [KnowledgeCategoryController::class, 'store'])
                    ->middleware('organization.role:owner,admin');
                Route::patch('/knowledge-base/categories/{category}', [KnowledgeCategoryController::class, 'update'])
                    ->middleware('organization.role:owner,admin');
                Route::delete('/knowledge-base/categories/{category}', [KnowledgeCategoryController::class, 'destroy'])
                    ->middleware('organization.role:owner,admin');

                // Knowledge Base Articles
                Route::get('/knowledge-base/articles', [KnowledgeArticleController::class, 'index'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::get('/knowledge-base/articles/{article}', [KnowledgeArticleController::class, 'show'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/knowledge-base/articles', [KnowledgeArticleController::class, 'store'])
                    ->middleware('organization.role:owner,admin');
                Route::patch('/knowledge-base/articles/{article}', [KnowledgeArticleController::class, 'update'])
                    ->middleware('organization.role:owner,admin');
                Route::delete('/knowledge-base/articles/{article}', [KnowledgeArticleController::class, 'destroy'])
                    ->middleware('organization.role:owner,admin');

                // Workflow Automations
                Route::get('/automations/rules', [\App\Http\Controllers\Api\V1\Automations\AutomationController::class, 'index'])
                    ->middleware('organization.role:owner,admin,agent');
                Route::post('/automations/rules', [\App\Http\Controllers\Api\V1\Automations\AutomationController::class, 'store'])
                    ->middleware('organization.role:owner,admin');
                Route::patch('/automations/rules/{rule}/toggle', [\App\Http\Controllers\Api\V1\Automations\AutomationController::class, 'toggle'])
                    ->middleware('organization.role:owner,admin');
                Route::delete('/automations/rules/{rule}', [\App\Http\Controllers\Api\V1\Automations\AutomationController::class, 'destroy'])
                    ->middleware('organization.role:owner,admin');
                Route::get('/tickets/{ticket}/automation-runs', [\App\Http\Controllers\Api\V1\Automations\AutomationController::class, 'ticketRuns'])
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
