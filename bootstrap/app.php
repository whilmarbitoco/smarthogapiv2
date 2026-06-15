<?php

use App\Console\Commands\ProcessFeedingSchedules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ProcessFeedingSchedules::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('feeding:process-schedules')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $message] = match (true) {
                $exception instanceof AuthenticationException => [401, 'Unauthorized'],
                $exception instanceof AuthorizationException,
                $exception instanceof AccessDeniedHttpException => [403, 'Forbidden'],
                $exception instanceof NotFoundHttpException => [404, 'Resource not found'],
                $exception instanceof ValidationException => [422, 'Validation failed'],
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 401 => [401, 'Unauthorized'],
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403 => [403, 'Forbidden'],
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404 => [404, 'Resource not found'],
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429 => [429, 'Too many requests'],
                default => [500, 'Server error'],
            };

            $payload = [
                'success' => false,
                'message' => $message,
            ];

            if ($exception instanceof ValidationException) {
                $payload['data'] = [
                    'errors' => $exception->errors(),
                ];
            }

            return response()->json($payload, $status);
        });
    })->create();
