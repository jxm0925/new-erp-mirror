<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureErpAuthenticated::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (WorkOrderDomainException $exception, $request) {
            if ($request->is('api/v1/erp/production/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error_code' => $exception->errorCode,
                    'errors' => [$exception->errorCode => [$exception->getMessage()]],
                    'details' => $exception->details,
                ], $exception->status);
            }
        });
        $exceptions->render(function (ValidationException $exception, $request) {
            if ($request->is('api/v1/erp/production/*')) {
                return response()->json([
                    'message' => '请求参数校验失败。',
                    'error_code' => 'validation_error',
                    'errors' => $exception->errors(),
                    'details' => [],
                ], 422);
            }
        });
        $exceptions->render(function (QueryException $exception, $request) {
            if (! $request->is('api/v1/erp/production/*')) return;
            $sqlState = (string) $exception->getCode();
            $driverCode = (string) ($exception->errorInfo[1] ?? '');
            $concurrency = $sqlState === '40001' || in_array($driverCode, ['1205', '1213'], true);
            $conflict = $sqlState === '23000' || $driverCode === '1062';
            $code = $concurrency ? 'concurrency_conflict' : ($conflict ? 'persistence_conflict' : 'persistence_error');
            return response()->json([
                'message' => $concurrency ? 'Work order concurrency conflict.' : ($conflict ? 'Work order persistence conflict.' : 'Work order persistence failed.'),
                'error_code' => $code,
                'errors' => [$code => ['The work order operation could not be completed.']],
                'details' => ['sql_state' => $sqlState, 'driver_code' => $driverCode],
            ], $concurrency || $conflict ? 409 : 500);
        });
    })->create();
