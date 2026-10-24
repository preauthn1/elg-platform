<?php

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Helpers\Common;
use App\Http\Middleware as GlobalMiddleware;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (!function_exists('registerRoute')) {
    function registerRoute($routeConfig, $middleware = 'api'): void
    {
        $route = Route::middleware($middleware)
            ->prefix($routeConfig['prefix'] ?? '')
            ->name($routeConfig['name'] ?? '');

        foreach ($routeConfig['routes'] as $routeFile) {
            $route->group(base_path($routeFile));
        }
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__ . '/../routes/web.php',
        ],
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('api')->name('api.')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 日誌批號
        $middleware->append(GlobalMiddleware\AssignRequestId::class);
        // $middleware->append(GlobalMiddleware\RateLimitNotify::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        // 路由不存在 / 方法不允許
        $exceptions->render(function (NotFoundHttpException|MethodNotAllowedHttpException $e) use ($requestId) {
            $code = $e instanceof MethodNotAllowedHttpException
                ? HttpCode::HTTP_METHOD_NOT_ALLOWED->value
                : HttpCode::HTTP_NOT_FOUND->value;

            return response()->json([
                'code' => $code,
                'message' => $e->getMessage(),
                'tid' => $requestId,
            ], $code);
        });

        // Request 驗證異常
        $exceptions->render(function (ValidationException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_BAD_REQUEST->value,
                'message' => $e->validator->errors()->first(),
                'tid' => $requestId,
            ], HttpCode::HTTP_BAD_REQUEST->value);
        });

        // SQL 查詢異常
        $exceptions->render(function (QueryException $e) use ($requestId) {
            $errorInfo = $e->errorInfo;
            $sql = Common::queryToSql($e->getSql(), $e->getBindings());
            $errorDesc = config('app.debug') ? sprintf('[%s] %s (%s)', $errorInfo[0], $errorInfo[2], $sql) : '';

            return response()->json([
                'code' => HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
                'message' => __('error_message.2001'),
                'error' => $errorDesc,
                'tid' => $requestId,
            ], HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        });

        // Eloquent 查詢不存在
        $exceptions->render(function (ModelNotFoundException $e) {
            return response()->json([
                'code' => HttpCode::HTTP_NOT_FOUND->value,
                'message' => $e->getMessage(),
            ], HttpCode::HTTP_NOT_FOUND->value);
        });

        // Enum 錯誤
        $exceptions->render(function (ValueError $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
                'message' => 'Enum error: ' . $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        });

        // 分布式緩存鎖超時
        $exceptions->render(function (LockTimeoutException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_LOCKED->value,
                'message' => 'Resource is locked: ' . $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_LOCKED->value);
        });

        // Http Client 請求連線異常
        $exceptions->render(function (ConnectionException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_GATEWAY_TIMEOUT->value,
                'message' => 'Request timed out: ' . $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
        });

    })->create();
