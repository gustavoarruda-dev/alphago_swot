<?php

use App\Http\Middleware\ValidateInternalApiKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'internal.api' => ValidateInternalApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->renderable(function (AuthorizationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage() ?: 'Forbidden.',
                ], 403);
            }
        });

        $exceptions->renderable(function (ValidationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $exception->errors(),
                ], 422);
            }
        });

        $exceptions->renderable(function (NotFoundHttpException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Route not found.',
                ], 404);
            }
        });

        $exceptions->renderable(function (MethodNotAllowedHttpException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Method not allowed.',
                ], 405);
            }
        });

        $exceptions->renderable(function (HttpException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP error.',
                ], $exception->getStatusCode());
            }
        });

        $exceptions->renderable(function (\Throwable $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => config('app.debug')
                        ? $exception->getMessage()
                        : 'Internal server error.',
                ], 500);
            }
        });
    })->create();
