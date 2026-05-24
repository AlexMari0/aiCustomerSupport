<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Middleware\EnsureOrganizationAccess;
use App\Http\Middleware\EnsureOrganizationRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization.access' => EnsureOrganizationAccess::class,
            'organization.role' => EnsureOrganizationRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldReturnApiJson = static function (Request $request): bool {
            return $request->expectsJson() || $request->is('api/*');
        };

        $exceptions->render(function (ValidationException $exception, Request $request) use ($shouldReturnApiJson) {
            if (! $shouldReturnApiJson($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($shouldReturnApiJson) {
            if (! $shouldReturnApiJson($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => [],
            ], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($shouldReturnApiJson) {
            if (! $shouldReturnApiJson($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this resource.',
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($shouldReturnApiJson) {
            if (! $shouldReturnApiJson($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'errors' => [],
            ], Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) use ($shouldReturnApiJson) {
            if (! $shouldReturnApiJson($request)) {
                return null;
            }

            $payload = [
                'success' => false,
                'message' => 'Unexpected server error.',
                'errors' => [],
            ];

            if (config('app.debug')) {
                $payload['errors'] = [
                    'exception' => class_basename($exception),
                    'message' => $exception->getMessage(),
                ];
            }

            return response()->json($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
