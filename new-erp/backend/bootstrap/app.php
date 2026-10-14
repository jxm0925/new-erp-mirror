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
        // 各 Controller 的按钮权限校验统一抛出“无按钮权限：<权限码>”，这里把权限码换成权限名称，
        // 让业务人员看得懂缺的是哪项权限；原权限码仍放在 details 里便于管理员排查。
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception, $request) {
            $prefix = '无按钮权限：';
            if ($exception->getStatusCode() !== 403 || !str_starts_with($exception->getMessage(), $prefix) || !$request->is('api/*')) return null;
            $codes = array_values(array_filter(array_map('trim', explode(' 或 ', substr($exception->getMessage(), strlen($prefix))))));
            $names = \Illuminate\Support\Facades\DB::table('erp_rbac_permissions')->whereIn('code', $codes)->pluck('name', 'code');
            $labels = array_map(fn ($code) => '「'.($names[$code] ?? $code).'」', $codes);
            $message = '当前账号没有'.implode('或', $labels).'权限，请联系管理员开通。';
            return response()->json(['message' => $message, 'error_code' => 'forbidden', 'errors' => ['permission' => [$message]], 'details' => ['permission_codes' => $codes]], 403);
        });
        $exceptions->render(function (WorkOrderDomainException $exception, $request) {
            if ($request->is('api/v1/erp/production/*') || $request->is('api/v1/erp/sales/*') || $request->is('api/v1/erp/inventory/warehouse-*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error_code' => $exception->errorCode,
                    'errors' => [$exception->errorCode => [$exception->getMessage()]],
                    'details' => $exception->details,
                ], $exception->status);
            }
        });
        $exceptions->render(function (ValidationException $exception, $request) {
            if ($request->is('api/v1/erp/production/*') || $request->is('api/v1/erp/sales/*') || $request->is('api/v1/erp/inventory/warehouse-*')) {
                return response()->json([
                    'message' => '请求参数校验失败。',
                    'error_code' => 'validation_error',
                    'errors' => $exception->errors(),
                    'details' => [],
                ], 422);
            }
        });
        $exceptions->render(function (QueryException $exception, $request) {
            if (! $request->is('api/v1/erp/production/*') && ! $request->is('api/v1/erp/sales/*') && ! $request->is('api/v1/erp/inventory/warehouse-*')) return;
            $sqlState = (string) $exception->getCode();
            $driverCode = (string) ($exception->errorInfo[1] ?? '');
            $concurrency = $sqlState === '40001' || in_array($driverCode, ['1205', '1213'], true);
            $conflict = $sqlState === '23000' || $driverCode === '1062';
            $code = $concurrency ? 'concurrency_conflict' : ($conflict ? 'persistence_conflict' : 'persistence_error');
            return response()->json([
                'message' => $concurrency ? '业务数据正在被其他请求更新，请刷新后重试。' : ($conflict ? '业务数据发生唯一性冲突，请刷新后重试。' : '业务数据保存失败，请联系管理员。'),
                'error_code' => $code,
                'errors' => [$code => [$concurrency ? '并发写入未完成。' : ($conflict ? '数据冲突导致写入未完成。' : '写入未完成。')]],
                'details' => ['sql_state' => $sqlState, 'driver_code' => $driverCode],
            ], $concurrency || $conflict ? 409 : 500);
        });
    })->create();
