<?php

/** @noinspection D */

declare(strict_types=1);

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Exceptions\EmailTemplateNotFoundException;
use App\Exceptions\SmsGatewayException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Http\Middleware as GlobalMiddleware;
use App\Services\BotNotify\AlertGate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
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
            $appRoutes = [
                'Frontend_API' => [
                    'prefix' => 'frontend/api',
                    'name' => 'frontend.',
                    'routes' => [
                        'routes/frontend/auth.php',
                        'routes/frontend/non_auth.php',
                        'routes/frontend/third_party_auth.php',
                        'routes/frontend/tgbot_auth.php',
                        'routes/shared/detect_ip.php', // target/tickets#4187 IP 偽造測試
                    ],
                ],
                'External_API' => [
                    'prefix' => 'external/api',
                    'name' => 'external.',
                    'routes' => [
                        'routes/external/api.php',
                        'routes/external/sw.php', // 無縫錢包
                        'routes/external/tw.php', // 轉帳錢包
                        'routes/external/livechat-bot.php', // Livechat Bot
                        'routes/shared/detect_ip.php', // target/tickets#4187 IP 偽造測試
                    ],
                ],
                'Agent_API' => [
                    'prefix' => 'agent/api',
                    'name' => 'agent.',
                    'routes' => [
                        'routes/agent/unauth.php',
                        'routes/agent/auth.php',
                        'routes/shared/detect_ip.php', // target/tickets#4187 IP 偽造測試
                    ],
                ],
            ];

            // 走 api/* 而非 frontend/api/*，由 Frontend_API 一併掛上
            $legacyApkRoutes = [
                'prefix' => 'api',
                'name' => 'legacy.',
                'routes' => [
                    'routes/legacy/apk.php',
                ],
            ];

            $appName = config('app.name');

            if ($appName === 'Laravel') {
                foreach ($appRoutes as $routeConfig) {
                    registerRoute($routeConfig);
                }
                registerRoute($legacyApkRoutes);
            } elseif (isset($appRoutes[$appName])) {
                $routeConfig = $appRoutes[$appName];
                registerRoute($routeConfig);

                if ($appName === 'Frontend_API') {
                    registerRoute($legacyApkRoutes);
                }
            }

            $devRoutes = [
                'prefix' => 'api',
                'name' => 'api.',
                'routes' => [
                    'routes/dev_tool.php',
                ],
            ];

            if (App::isLocal() && App::hasDebugModeEnabled()) {
                registerRoute($devRoutes);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 日誌批號
        $middleware->append(GlobalMiddleware\AssignRequestId::class);
        $middleware->append(GlobalMiddleware\RateLimitNotify::class);

        // 工單 4540：忘記密碼限流（throttle:guest-reset-password／guest-member-accounts）的 key 要讀解密後的
        // username／手機／信箱，擋下時回的 2037 訊息要跟著請求語系。Laravel 會依 middleware priority 把
        // ThrottleRequests 提到 api 群組的 SubstituteBindings 前面，也就跑在路由群組的 DecryptRequest 與
        // DetectLanguage 之前：key 會全部讀到空值（變成全站共用一把）、訊息永遠是預設語系。
        // 所以把兩者排進 priority、放在 ThrottleRequests 之前，加入順序即先後：DecryptRequest → DetectLanguage → ThrottleRequests。
        // 影響範圍：所有掛這兩支的前台路由，它們都會早於 SubstituteBindings 執行，DetectLanguage 也會早於 DetectIp／DetectDevice；
        // DecryptRequest 只解 body、DetectLanguage 只讀 Accept-Language header 與 languages 表，都不依賴路由綁定或那兩支，無副作用。
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: GlobalMiddleware\Frontend\DecryptRequest::class,
        );
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: GlobalMiddleware\Frontend\DetectLanguage::class,
        );

        // Trusted proxies（#3967 B2）：設定位於 config/trustedproxy.php（TRUSTED_PROXIES env）。
        // 不在此呼叫 $middleware->trustProxies(at: env(...))——本 callback 於 kernel resolve
        // 時執行，早於 .env / config 載入，env() 在此讀不到掛載的 .env 值（已實測）；
        // 內建 TrustProxies middleware 會於 request 時 fallback 讀 config('trustedproxy.proxies')。
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        // 設定上下文
        $exceptions->context(function () use ($requestId) {
            return [
                'tid' => $requestId,
            ];
        });

        // 路由不存在
        $exceptions->render(function (NotFoundHttpException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_NOT_FOUND->value,
                'message' => $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_NOT_FOUND->value);
        });

        // 方法不允許
        $exceptions->render(function (MethodNotAllowedHttpException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_METHOD_NOT_ALLOWED->value,
                'message' => $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_METHOD_NOT_ALLOWED->value);
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
        $exceptions->render(function (ModelNotFoundException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_NOT_FOUND->value,
                'message' => $e->getMessage(),
                'tid' => $requestId,
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
            Log::warning('連線超時', [
                'message' => $e->getMessage(),
                'tid' => $requestId,
                'handler_context' => Common::getGuzzleHandlerContext($e),
            ]);

            return response()->json([
                'code' => HttpCode::HTTP_GATEWAY_TIMEOUT->value,
                'message' => 'Connection timeout',
                'tid' => $requestId,
            ], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
        });

        // HTTP Client 請求異常
        $exceptions->render(function (RequestException $e) use ($requestId) {
            $message = strtolower($e->getMessage());
            $response = $e->response;
            $statusCode = $response?->status() ?? 0;

            // cURL error 6: DNS 解析失敗
            // cURL error 7: 連線失敗
            $isDnsOrConnectionFailed = str_contains($message, 'curl error 6')
                || str_contains($message, 'curl error 7')
                || str_contains($message, 'could not resolve host')
                || str_contains($message, 'failed to connect');

            if ($isDnsOrConnectionFailed) {
                Log::warning('連線失敗', [
                    'message' => $e->getMessage(),
                    'status_code' => $statusCode,
                    'tid' => $requestId,
                ]);

                return response()->json([
                    'code' => HttpCode::HTTP_BAD_GATEWAY->value,
                    'message' => 'Connection failed',
                    'tid' => $requestId,
                ], HttpCode::HTTP_BAD_GATEWAY->value);
            }

            // cURL error 28: 請求超時
            $isTimeout = str_contains($message, 'curl error 28')
                || str_contains($message, 'timed out')
                || str_contains($message, 'timeout');

            if ($isTimeout) {
                Log::warning('請求超時', [
                    'message' => $e->getMessage(),
                    'status_code' => $statusCode,
                    'handler_context' => Common::getGuzzleHandlerContext($e),
                    'tid' => $requestId,
                ]);

                return response()->json([
                    'code' => HttpCode::HTTP_GATEWAY_TIMEOUT->value,
                    'message' => 'Request timeout',
                    'tid' => $requestId,
                ], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
            }

            // HTTP 狀態碼分類處理
            $errorMessage = match (true) {
                $statusCode === 429 => 'Too many requests',
                $statusCode === 401 || $statusCode === 403 => 'Authentication failed',
                $statusCode === 404 => 'Endpoint not found',
                $statusCode === 502 || $statusCode === 503 || $statusCode === 504 => 'Service unavailable',
                $statusCode >= 500 => 'Server error',
                $statusCode >= 400 => 'Bad request',
                default => 'Request failed',
            };

            // 記錄日誌
            $logLevel = ($statusCode >= 500 && $statusCode < 503) ? 'error' : 'warning';
            Log::$logLevel(sprintf('HTTP 請求失敗 (%d)', $statusCode), [
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'original_message' => $e->getMessage(),
                'tid' => $requestId,
            ]);

            return response()->json([
                'code' => $statusCode ?: HttpCode::HTTP_BAD_GATEWAY->value,
                'message' => $errorMessage,
                'tid' => $requestId,
            ], $statusCode ?: HttpCode::HTTP_BAD_GATEWAY->value);
        });

        // Vendor 配置發現異常
        $exceptions->render(function (VendorSetupException $e) use ($requestId) {
            Log::error('VendorSetupException', [
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'trace' => Common::formatTrace($e),
            ]);

            return response()->json([
                'code' => HttpCode::HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL->value,
                'message' => $e->getMessage() ?: 'Vendor setup error',
                'tid' => $requestId,
            ], HttpCode::HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL->value);
        });

        // Vendor 不存在
        $exceptions->render(function (VendorNotFoundException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_SERVICE_UNAVAILABLE->value,
                'message' => $e->getMessage() ?: 'Vendor not found',
                'tid' => $requestId,
            ], HttpCode::HTTP_SERVICE_UNAVAILABLE->value);
        });

        // 暂无可用的游戏帐号可绑定会员
        $exceptions->render(function (VendorAccountException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_SERVICE_UNAVAILABLE->value,
                'message' => $e->getMessage() ?: 'No available vendor account to bind member',
                'tid' => $requestId,
            ], HttpCode::HTTP_SERVICE_UNAVAILABLE->value);
        });

        // 簡訊發送失敗：失敗細節只進 log／TG 告警（走 report() → AlertGate），玩家端一律回泛化訊息
        $exceptions->render(function (SmsGatewayException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_BAD_REQUEST->value,
                'message' => __('error_message.2003'),
                'tid' => $requestId,
            ], HttpCode::HTTP_BAD_REQUEST->value);
        });

        // 驗證碼郵件查無樣板：與簡訊同一泛化訊息，細節只進 log／告警
        $exceptions->render(function (EmailTemplateNotFoundException $e) use ($requestId) {
            return response()->json([
                'code' => HttpCode::HTTP_BAD_REQUEST->value,
                'message' => __('error_message.2003'),
                'tid' => $requestId,
            ], HttpCode::HTTP_BAD_REQUEST->value);
        });

        // 參數異常
        $exceptions->render(function (InvalidArgumentException $e) use ($requestId) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'tid' => $requestId,
            ], HttpCode::HTTP_BAD_REQUEST->value);
        });

        // 例外告警：統一匯流至 AlertGate（分類 → 降噪 → 發送）。
        // 能到此處的＝Laravel 已判定要 report 的例外（扣除 internalDontReport、扣除自帶 report() 短路者）。
        $exceptions->report(function (Throwable $e) {
            app(AlertGate::class)->handle($e);

            // ApiException 原本自帶 report() 會短路、不進預設 logging；移除 report() 後在此維持等價語意，
            // 避免 ApiException 重複寫入預設 log。其餘例外維持「繼續預設 logging」行為。
            return !($e instanceof ApiException);
        });
    })->create();
