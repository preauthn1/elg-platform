# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

此文檔為 Claude Code 在此程式碼庫中工作時提供指引。所有文檔和程式碼使用**正體中文**。

## 專案概述

**OneGate** 是一個四方金流聚合支付系統，定位為中間層支付平台：

```
下游商戶 → [OneGate 聚合系統] → 上游四方支付平台（ECPay、NewebPay...）
```

### 核心功能
- 提供統一 API 給商戶接入
- 聚合多個上游四方支付平台
- 智能訂單路由（根據金額、費率、成功率等）
- 異步回調轉發與重試機制
- 對帳清算系統
- 商戶管理與報表統計

## 技術棧決策

### 主要技術選型
- **後端框架**：PHP 8.4 with Laravel 12
- **架構模式**：傳統 PHP-FPM + Nginx（**不使用** Octane/Swoole）
- **資料庫**：MySQL 8.0+ (InnoDB 引擎)
- **緩存/隊列**：Redis 7.0+
- **隊列管理**：Laravel Horizon
- **進程管理**：Supervisor

### 技術選型理由
1. **開發效率**：Laravel 生態完整，能快速開發和迭代
2. **上游整合**：多數四方平台提供 PHP SDK，易於整合
3. **團隊熟悉度**：降低學習曲線和維護成本
4. **架構簡單**：傳統架構穩定可靠，部署和運維成本低

## 架構設計原則

### 1. 統一支付接口設計

所有上游平台適配器必須實現統一接口：

```php
interface PaymentGatewayInterface
{
    public function createOrder(Order $order): array;
    public function queryOrder(string $orderId): array;
    public function handleCallback(Request $request): CallbackResult;
    public function refund(Refund $refund): array;
    public function verifySign(array $data): bool;
}
```

### 2. 目錄結構規範

```
app/
├── Services/
│   ├── Payment/
│   │   ├── PaymentGatewayInterface.php
│   │   ├── Gateways/              # 各平台適配器
│   │   │   ├── ECPayGateway.php
│   │   │   ├── NewebPayGateway.php
│   │   │   └── ...
│   │   ├── PaymentRouter.php      # 訂單路由引擎
│   │   └── PaymentService.php     # 支付服務
│   └── Order/
│       └── OrderService.php
├── Models/
│   ├── Merchant.php               # 商戶
│   ├── Order.php                  # 訂單
│   ├── Transaction.php            # 交易記錄
│   └── Gateway.php                # 支付網關配置
└── Jobs/
    ├── ProcessPaymentOrder.php    # 處理支付訂單
    ├── ProcessPaymentCallback.php # 處理回調
    └── NotifyMerchant.php         # 通知商戶
```

### 3. 異步處理原則

**所有耗時操作必須使用 Queue 異步處理：**
- ✅ 調用上游支付 API
- ✅ 處理支付回調
- ✅ 通知下游商戶
- ✅ 對帳清算任務
- ❌ 不要在 HTTP 請求中同步處理

## 關鍵技術要點

### ⚠️ 重要：Laravel 事務與 Queue 的時序問題

這是支付系統中最容易出錯的地方，必須正確處理：

#### 問題說明

```php
// ❌ 錯誤示例：可能導致數據不一致
DB::transaction(function () {
    $order = Order::create([...]);  // 事務內創建訂單

    ProcessPayment::dispatch($order);  // Job 可能在事務提交前執行
});

// Job 執行時可能查詢不到訂單，或查到舊數據
```

**嚴重後果：**
- Job 查詢不到剛創建的訂單
- 商戶收到通知時，查詢訂單狀態還是舊的
- 導致資金對帳錯誤

#### 解決方案

**方案 1：全局配置（必須啟用）**

```php
// config/queue.php
return [
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'after_commit' => true,  // ✅ 全局啟用
        ],
    ],
];
```

**方案 2：所有 Job 實現接口**

```php
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class ProcessPayment implements ShouldQueue, ShouldQueueAfterCommit
{
    // 確保事務提交後才執行
}
```

**方案 3：派發時指定**

```php
ProcessPayment::dispatch($order)->afterCommit();
```

#### 標準流程模板

```php
// 創建訂單流程
public function createOrder(Request $request)
{
    $order = DB::transaction(function () use ($request) {
        $order = Order::create([...]);
        OrderLog::create([...]);
        return $order;
    });

    // ✅ 事務外派發
    ProcessPayment::dispatch($order)->afterCommit();

    return response()->json($order);
}

// 回調處理流程
class ProcessPaymentCallback implements ShouldQueue, ShouldQueueAfterCommit
{
    public function handle()
    {
        DB::transaction(function () {
            $order = Order::lockForUpdate()->find($this->orderId);
            $order->update(['status' => 'paid']);
            Transaction::create([...]);
        });

        // ✅ 事務提交後才通知商戶
        NotifyMerchant::dispatch($order)->afterCommit();
    }
}
```

### 其他關鍵技術規範

#### 1. 必須使用數據庫事務

```php
// ✅ 正確
DB::transaction(function () {
    $order = Order::create([...]);
    Transaction::create([...]);
    $merchant->increment('balance', $amount);
});

// ❌ 錯誤：沒有事務保護
$order = Order::create([...]);
Transaction::create([...]);
$merchant->increment('balance', $amount);  // 可能失敗導致數據不一致
```

#### 2. 防止重複處理（使用 Redis 鎖）

```php
public function handleCallback(Request $request)
{
    $lock = Cache::lock("callback:{$request->order_no}", 10);

    if (!$lock->get()) {
        return response('DUPLICATE', 200);
    }

    try {
        $this->processCallback($request);
    } finally {
        $lock->release();
    }
}
```

#### 3. 悲觀鎖處理並發

```php
// 處理訂單狀態變更時必須加鎖
$order = Order::where('order_no', $orderNo)
    ->lockForUpdate()
    ->first();

if ($order->status === 'pending') {
    $order->update(['status' => 'paid']);
}
```

#### 4. 簽名驗證

```php
class SignatureService
{
    public function generate(array $data, string $key): string
    {
        ksort($data);
        $string = http_build_query($data) . "&key={$key}";
        return strtoupper(md5($string));
    }

    public function verify(array $data, string $sign, string $key): bool
    {
        // 使用 hash_equals 防止時間差攻擊
        return hash_equals($sign, $this->generate($data, $key));
    }
}
```

#### 5. 回調快速響應

```php
// 回調路由不需要 CSRF
Route::post('/callback/{gateway}', [CallbackController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken:[REDACTED];

public function handle(Request $request, string $gateway)
{
    // 立即派發到 Queue，快速響應
    ProcessPaymentCallback::dispatch($gateway, $request->all());

    // 上游平台通常要求 5 秒內響應
    return response('SUCCESS', 200);
}
```

## 錯誤處理與降級方案

支付系統必須具備完善的錯誤處理和降級能力，確保在異常情況下仍能保證資金安全和服務可用性。

### 1. 異常分類與處理策略

#### 異常分類

```php
namespace App\Exceptions\Payment;

// 基礎支付異常
abstract class PaymentException extends \Exception
{
    protected $isRetryable = false;

    public function isRetryable(): bool
    {
        return $this->isRetryable;
    }
}

// 可重試異常（網路問題、上游超時等）
class RetryablePaymentException extends PaymentException
{
    protected $isRetryable = true;
}

// 不可重試異常（簽名錯誤、參數錯誤等）
class NonRetryablePaymentException extends PaymentException
{
    protected $isRetryable = false;
}

// 具體異常類型
class GatewayTimeoutException extends RetryablePaymentException {}
class GatewayUnavailableException extends RetryablePaymentException {}
class InvalidSignatureException extends NonRetryablePaymentException {}
class InsufficientBalanceException extends NonRetryablePaymentException {}
class OrderNotFoundException extends NonRetryablePaymentException {}
```

#### 統一異常處理器

```php
// app/Exceptions/Handler.php
class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->reportable(function (PaymentException $e) {
            Log::channel('payment')->error('Payment exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
                'trace' => $e->getTraceAsString(),
            ]);
        });
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof PaymentException) {
            return response()->json([
                'success' => false,
                'error_code' => $this->getErrorCode($e),
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ], 400);
        }

        return parent::render($request, $e);
    }
}
```

### 2. 網關適配器錯誤處理

每個網關適配器必須實現完整的錯誤處理：

```php
class ECPayGateway implements PaymentGatewayInterface
{
    private const TIMEOUT = 10; // 10 秒超時
    private const MAX_RETRIES = 3;

    public function createOrder(Order $order): array
    {
        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            try {
                $response = $this->sendRequest('/create', $order->toArray());

                // 驗證響應簽名
                if (!$this->verifySign($response)) {
                    throw new InvalidSignatureException('Invalid response signature');
                }

                return $response;

            } catch (ConnectException $e) {
                // 網路連線錯誤，可重試
                $retries++;
                if ($retries >= self::MAX_RETRIES) {
                    throw new GatewayUnavailableException(
                        "Gateway unavailable after {$retries} retries: " . $e->getMessage()
                    );
                }

                // 指數退避：1秒、2秒、4秒
                sleep(pow(2, $retries - 1));
                continue;

            } catch (RequestException $e) {
                // HTTP 錯誤
                $statusCode = $e->getResponse()?->getStatusCode();

                if (in_array($statusCode, [500, 502, 503, 504])) {
                    // 上游服務器錯誤，可重試
                    throw new GatewayTimeoutException("Gateway error: {$statusCode}");
                }

                // 4xx 錯誤，不可重試
                throw new NonRetryablePaymentException(
                    "Invalid request: {$statusCode}"
                );

            } catch (\Throwable $e) {
                // 未預期的錯誤
                Log::channel('payment')->critical('Unexpected gateway error', [
                    'gateway' => 'ECPay',
                    'order_id' => $order->id,
                    'exception' => $e->getMessage(),
                ]);

                throw new GatewayUnavailableException(
                    'Unexpected error: ' . $e->getMessage()
                );
            }
        }
    }

    private function sendRequest(string $endpoint, array $data): array
    {
        $client = new \GuzzleHttp\Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 5,
        ]);

        $response = $client->post($this->baseUrl . $endpoint, [
            'json' => $data,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'OneGate/1.0',
            ],
        ]);

        return json_decode($response->getBody(), true);
    }
}
```

### 3. Job 重試策略

#### Queue Job 配置

```php
class ProcessPaymentOrder implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 最大重試次數
    public $tries = 5;

    // 重試間隔（指數退避）
    public $backoff = [60, 300, 900, 3600]; // 1分、5分、15分、1小時

    // 超時時間
    public $timeout = 120;

    public function handle()
    {
        try {
            $order = Order::findOrFail($this->orderId);

            // 檢查訂單狀態，避免重複處理
            if ($order->status !== 'pending') {
                Log::channel('payment')->warning('Order already processed', [
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);
                return;
            }

            $gateway = app(PaymentGatewayFactory::class)->make($order->gateway_id);
            $result = $gateway->createOrder($order);

            DB::transaction(function () use ($order, $result) {
                $order->update([
                    'gateway_order_no' => $result['order_no'],
                    'payment_url' => $result['payment_url'],
                    'status' => 'processing',
                ]);

                Transaction::create([
                    'order_id' => $order->id,
                    'type' => 'create',
                    'data' => $result,
                ]);
            });

            // 通知商戶（另一個 Job）
            NotifyMerchant::dispatch($order, 'order_created')->afterCommit();

        } catch (NonRetryablePaymentException $e) {
            // 不可重試錯誤，標記訂單失敗
            $this->fail($e);
            $this->markOrderAsFailed($e);

        } catch (RetryablePaymentException $e) {
            // 可重試錯誤，拋出異常讓 Laravel 自動重試
            Log::channel('payment')->warning('Payment job will retry', [
                'order_id' => $this->orderId,
                'attempt' => $this->attempts(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        // Job 最終失敗後的處理
        Log::channel('payment')->error('Payment job failed permanently', [
            'order_id' => $this->orderId,
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
        ]);

        $this->markOrderAsFailed($exception);

        // 發送告警
        app(AlertService::class)->sendPaymentFailedAlert($this->orderId, $exception);
    }

    private function markOrderAsFailed(\Throwable $exception): void
    {
        DB::transaction(function () use ($exception) {
            Order::where('id', $this->orderId)->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);

            Transaction::create([
                'order_id' => $this->orderId,
                'type' => 'failed',
                'data' => [
                    'error' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
            ]);
        });

        // 退款給商戶（如果已扣款）
        RefundToMerchant::dispatch($this->orderId)->afterCommit();
    }
}
```

#### 死信隊列處理

```php
// config/queue.php
return [
    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];

// 定期檢查死信隊列
// app/Console/Commands/ProcessFailedJobs.php
class ProcessFailedJobs extends Command
{
    protected $signature = 'queue:process-failed';

    public function handle()
    {
        // 獲取所有失敗的支付 Job
        $failedJobs = DB::table('failed_jobs')
            ->where('queue', 'payment')
            ->where('failed_at', '>', now()->subDays(7))
            ->get();

        foreach ($failedJobs as $job) {
            // 記錄到專門的表進行人工審核
            FailedPayment::create([
                'job_id' => $job->uuid,
                'payload' => $job->payload,
                'exception' => $job->exception,
                'failed_at' => $job->failed_at,
                'status' => 'pending_review',
            ]);
        }

        $this->info("Processed {$failedJobs->count()} failed jobs");
    }
}
```

### 4. 降級方案

#### 上游平台故障降級

```php
class PaymentRouter
{
    public function route(Order $order): Gateway
    {
        $availableGateways = $this->getAvailableGateways($order);

        if ($availableGateways->isEmpty()) {
            throw new GatewayUnavailableException('No available payment gateway');
        }

        // 優先使用成功率高的網關
        return $availableGateways->sortByDesc(function ($gateway) {
            return $this->getGatewayScore($gateway);
        })->first();
    }

    private function getAvailableGateways(Order $order): Collection
    {
        return Gateway::where('status', 'active')
            ->where('min_amount', '<=', $order->amount)
            ->where('max_amount', '>=', $order->amount)
            ->get()
            ->filter(function ($gateway) {
                // 檢查網關健康狀態
                return $this->isGatewayHealthy($gateway);
            });
    }

    private function isGatewayHealthy(Gateway $gateway): bool
    {
        $cacheKey = "gateway:health:{$gateway->id}";

        // 從緩存讀取健康狀態
        $health = Cache::get($cacheKey);

        if ($health === 'down') {
            // 檢查是否可以恢復
            if ($this->canRecover($gateway)) {
                Cache::forget($cacheKey);
                return true;
            }
            return false;
        }

        // 檢查最近的成功率
        $successRate = $this->calculateSuccessRate($gateway);

        if ($successRate < 0.7) { // 成功率低於 70%
            // 標記為不健康，5 分鐘後重試
            Cache::put($cacheKey, 'down', 300);

            Log::channel('payment')->warning('Gateway marked as unhealthy', [
                'gateway' => $gateway->name,
                'success_rate' => $successRate,
            ]);

            return false;
        }

        return true;
    }

    private function calculateSuccessRate(Gateway $gateway): float
    {
        $cacheKey = "gateway:success_rate:{$gateway->id}";

        return Cache::remember($cacheKey, 60, function () use ($gateway) {
            $recent = Order::where('gateway_id', $gateway->id)
                ->where('created_at', '>', now()->subHour())
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as success
                ')
                ->first();

            if ($recent->total < 10) {
                return 1.0; // 樣本不足，假設健康
            }

            return $recent->success / $recent->total;
        });
    }

    private function canRecover(Gateway $gateway): bool
    {
        // 每 5 分鐘嘗試恢復一次
        $lastCheck = Cache::get("gateway:last_check:{$gateway->id}");

        if ($lastCheck && $lastCheck > now()->subMinutes(5)) {
            return false;
        }

        Cache::put("gateway:last_check:{$gateway->id}", now(), 300);

        // 執行健康檢查（ping 端點）
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get($gateway->health_check_url);
            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

#### 熔斷器模式

```php
class CircuitBreaker
{
    private const FAILURE_THRESHOLD = 5; // 5 次失敗觸發熔斷
    private const TIMEOUT = 300; // 5 分鐘後嘗試恢復
    private const SUCCESS_THRESHOLD = 2; // 2 次成功後關閉熔斷

    public function call(string $service, callable $callback)
    {
        $state = $this->getState($service);

        if ($state === 'open') {
            // 熔斷器開啟，直接拋出異常
            if (!$this->shouldAttemptReset($service)) {
                throw new CircuitBreakerOpenException("Circuit breaker is open for {$service}");
            }

            // 進入半開狀態，嘗試恢復
            $this->setState($service, 'half_open');
        }

        try {
            $result = $callback();
            $this->recordSuccess($service);
            return $result;

        } catch (\Throwable $e) {
            $this->recordFailure($service);
            throw $e;
        }
    }

    private function recordSuccess(string $service): void
    {
        $state = $this->getState($service);

        if ($state === 'half_open') {
            $successes = Cache::increment("circuit:{$service}:half_open_success");

            if ($successes >= self::SUCCESS_THRESHOLD) {
                // 關閉熔斷器
                $this->setState($service, 'closed');
                Cache::forget("circuit:{$service}:failures");
                Cache::forget("circuit:{$service}:half_open_success");

                Log::channel('payment')->info('Circuit breaker closed', [
                    'service' => $service,
                ]);
            }
        } else {
            Cache::forget("circuit:{$service}:failures");
        }
    }

    private function recordFailure(string $service): void
    {
        $failures = Cache::increment("circuit:{$service}:failures");

        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->setState($service, 'open');
            Cache::put("circuit:{$service}:opened_at", now(), self::TIMEOUT);

            Log::channel('payment')->error('Circuit breaker opened', [
                'service' => $service,
                'failures' => $failures,
            ]);

            // 發送告警
            app(AlertService::class)->sendCircuitBreakerAlert($service);
        }
    }

    private function getState(string $service): string
    {
        return Cache::get("circuit:{$service}:state", 'closed');
    }

    private function setState(string $service, string $state): void
    {
        Cache::put("circuit:{$service}:state", $state, 3600);
    }

    private function shouldAttemptReset(string $service): bool
    {
        $openedAt = Cache::get("circuit:{$service}:opened_at");
        return $openedAt && $openedAt->addSeconds(self::TIMEOUT) < now();
    }
}

// 使用範例
class ECPayGateway implements PaymentGatewayInterface
{
    public function createOrder(Order $order): array
    {
        $breaker = app(CircuitBreaker::class);

        return $breaker->call('ecpay', function () use ($order) {
            return $this->sendRequest('/create', $order->toArray());
        });
    }
}
```

#### 服務降級開關

```php
// 通過 Redis 實現動態降級開關
class FeatureFlag
{
    public function isEnabled(string $feature): bool
    {
        return Cache::get("feature:{$feature}", true);
    }

    public function enable(string $feature): void
    {
        Cache::forever("feature:{$feature}", true);
    }

    public function disable(string $feature): void
    {
        Cache::forever("feature:{$feature}", false);
    }
}

// 使用降級開關
class PaymentService
{
    public function createOrder(Request $request): Order
    {
        $featureFlag = app(FeatureFlag::class);

        // 如果回調通知功能被降級，則不發送通知
        if (!$featureFlag->isEnabled('merchant_callback')) {
            Log::channel('payment')->warning('Merchant callback is disabled');
            // 改為記錄到待處理隊列，稍後手動處理
            PendingCallback::create([
                'order_id' => $order->id,
                'type' => 'merchant_callback',
            ]);
        } else {
            NotifyMerchant::dispatch($order)->afterCommit();
        }

        return $order;
    }
}

// Artisan 命令管理降級開關
// php artisan feature:disable merchant_callback
// php artisan feature:enable merchant_callback
```

### 5. 超時控制

#### 統一超時配置

```php
// config/payment.php
return [
    'timeouts' => [
        'gateway_request' => 10,      // 上游請求超時（秒）
        'callback_response' => 5,      // 回調響應超時（秒）
        'merchant_notify' => 10,       // 商戶通知超時（秒）
        'database_query' => 5,         // 資料庫查詢超時（秒）
        'redis_operation' => 2,        // Redis 操作超時（秒）
    ],
];

// HTTP Client 配置
class GatewayClient
{
    public function request(string $method, string $url, array $options = []): array
    {
        $client = new \GuzzleHttp\Client([
            'timeout' => config('payment.timeouts.gateway_request'),
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        $response = $client->request($method, $url, $options);

        if ($response->getStatusCode() >= 500) {
            throw new GatewayTimeoutException('Gateway returned 5xx error');
        }

        return json_decode($response->getBody(), true);
    }
}

// 資料庫查詢超時
DB::connection()->statement('SET SESSION MAX_EXECUTION_TIME=5000'); // 5秒
```

### 6. 監控與告警

#### 錯誤率監控

```php
class PaymentMetrics
{
    public function recordOrderCreated(Order $order): void
    {
        Redis::hincrby('metrics:orders:' . now()->format('Y-m-d:H'), 'created', 1);
    }

    public function recordOrderSuccess(Order $order): void
    {
        Redis::hincrby('metrics:orders:' . now()->format('Y-m-d:H'), 'success', 1);
    }

    public function recordOrderFailed(Order $order): void
    {
        Redis::hincrby('metrics:orders:' . now()->format('Y-m-d:H'), 'failed', 1);
    }

    public function getSuccessRate(string $hour): float
    {
        $stats = Redis::hgetall("metrics:orders:{$hour}");

        if (empty($stats) || !isset($stats['created']) || $stats['created'] == 0) {
            return 0;
        }

        return ($stats['success'] ?? 0) / $stats['created'];
    }
}

// 定時檢查告警
class CheckPaymentMetrics extends Command
{
    protected $signature = 'payment:check-metrics';

    public function handle()
    {
        $metrics = app(PaymentMetrics::class);
        $currentHour = now()->format('Y-m-d:H');
        $successRate = $metrics->getSuccessRate($currentHour);

        if ($successRate < 0.9) { // 成功率低於 90%
            app(AlertService::class)->sendLowSuccessRateAlert($successRate);
        }
    }
}
```

#### 告警服務

```php
class AlertService
{
    public function sendPaymentFailedAlert(int $orderId, \Throwable $exception): void
    {
        // 發送到 Slack/Email/SMS
        Log::channel('alert')->critical('Payment failed permanently', [
            'order_id' => $orderId,
            'exception' => $exception->getMessage(),
        ]);

        // 整合第三方告警服務（如 PagerDuty, Sentry）
        if (app()->bound('sentry')) {
            app('sentry')->captureException($exception);
        }
    }

    public function sendCircuitBreakerAlert(string $service): void
    {
        Log::channel('alert')->error('Circuit breaker opened', [
            'service' => $service,
            'time' => now(),
        ]);
    }

    public function sendLowSuccessRateAlert(float $rate): void
    {
        Log::channel('alert')->warning('Low payment success rate', [
            'rate' => $rate,
            'threshold' => 0.9,
        ]);
    }
}
```

### 7. 回調通知重試機制

```php
class NotifyMerchant implements ShouldQueue, ShouldQueueAfterCommit
{
    public $tries = 10;

    // 遞增的重試間隔：1m, 2m, 5m, 10m, 30m, 1h, 2h, 4h, 8h, 24h
    public $backoff = [60, 120, 300, 600, 1800, 3600, 7200, 14400, 28800, 86400];

    public function handle()
    {
        $order = Order::find($this->orderId);
        $merchant = $order->merchant;

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => config('payment.timeouts.merchant_notify'),
            ]);

            $response = $client->post($merchant->callback_url, [
                'json' => [
                    'order_no' => $order->order_no,
                    'amount' => $order->amount,
                    'status' => $order->status,
                    'paid_at' => $order->paid_at,
                    'sign' => $this->generateSign($order, $merchant->secret_key),
                ],
            ]);

            // 商戶必須返回 "SUCCESS" 字符串
            if ($response->getBody()->getContents() !== 'SUCCESS') {
                throw new \RuntimeException('Merchant did not return SUCCESS');
            }

            // 記錄通知成功
            $order->update(['callback_notified_at' => now()]);

        } catch (\Throwable $e) {
            Log::channel('payment')->error('Merchant notification failed', [
                'order_id' => $order->id,
                'merchant_id' => $merchant->id,
                'attempt' => $this->attempts(),
                'exception' => $e->getMessage(),
            ]);

            // 拋出異常觸發重試
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        // 通知最終失敗，記錄到待處理表
        PendingCallback::create([
            'order_id' => $this->orderId,
            'type' => 'merchant_callback',
            'attempts' => $this->attempts(),
            'last_error' => $exception->getMessage(),
            'status' => 'failed',
        ]);

        // 發送告警給運維人員
        app(AlertService::class)->sendMerchantNotifyFailedAlert($this->orderId);
    }
}
```

## 性能優化要求

### 1. 必須啟用的緩存

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Redis 緩存策略

```php
// 緩存網關配置（熱點數據）
Cache::remember("gateway:{$id}", 3600, function () use ($id) {
    return Gateway::find($id);
});

// 緩存商戶信息
Cache::remember("merchant:{$id}", 3600, function () use ($id) {
    return Merchant::with('gateways')->find($id);
});
```

### 3. 數據庫查詢優化

```php
// ✅ 使用預加載避免 N+1
Order::with(['merchant', 'transactions', 'gateway'])->get();

// ✅ 使用 chunk 處理大量數據
Order::where('status', 'pending')->chunk(200, function ($orders) {
    // 處理訂單
});

// ✅ 必要的索引
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('order_no')->unique();
    $table->unsignedBigInteger('merchant_id')->index();
    $table->string('status')->index();
    $table->timestamps();

    // 複合索引
    $table->index(['merchant_id', 'status', 'created_at']);
});
```

### 4. Queue Worker 配置

```ini
# /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
command=php /path/to/artisan queue:work redis --sleep=3 --tries=3
numprocs=8
autostart=true
autorestart=true
user=www-data
```

## 環境配置要求

### 生產環境必須配置

```env
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=error

# 使用 Redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# 資料庫
DB_CONNECTION=mysql
DB_ENGINE=InnoDB

# Queue 配置
QUEUE_AFTER_COMMIT=true
```

### PHP-FPM 優化

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 500
request_terminate_timeout = 30s
```

## 安全規範

### 1. 環境隔離
- 測試環境使用沙箱 API
- 生產環境使用獨立的 API Key
- 敏感配置使用環境變數，不提交到 Git

### 2. 日誌記錄
- 所有支付操作必須記錄詳細日誌
- 使用獨立的 payment channel
- 保留至少 30 天

```php
Log::channel('payment')->info('Order created', [
    'order_id' => $order->id,
    'merchant_id' => $order->merchant_id,
    'amount' => $order->amount,
    'gateway' => $gateway->name,
]);
```

### 3. 敏感數據處理
- API Key 加密存儲
- 不在日誌中記錄完整簽名
- 使用 HTTPS 通信

## 監控與維護

### 必須監控的指標
- 訂單成功率
- 平均響應時間
- Queue 積壓數量
- 上游平台可用性
- 回調成功率

### 使用 Laravel Horizon
```bash
composer require laravel/horizon
php artisan horizon:install

# 訪問 /horizon 查看隊列狀態
```

## 開發規範

### 程式碼風格
- 遵循 PSR-12 編碼標準
- 使用 Type Hints
- 所有 public 方法必須有 DocBlock
- 關鍵業務邏輯必須有單元測試

### Git Commit 規範
```
feat: 新增綠界支付網關適配器
fix: 修復回調重複處理問題
refactor: 重構訂單路由邏輯
docs: 更新 API 文檔
test: 新增支付回調測試案例
```

### 測試要求
```bash
# 單元測試
php artisan test --filter=PaymentTest

# 測試覆蓋率要求
# - 支付核心邏輯：>80%
# - 網關適配器：>70%
```

## 部署架構

```
負載均衡器 (Nginx/HAProxy)
    |
    +-- Web Server 1 (Nginx + PHP-FPM)
    +-- Web Server 2 (Nginx + PHP-FPM)
    |
    +-- Redis (緩存 + 隊列)
    +-- MySQL (主從複製)
    +-- Queue Workers (Supervisor 管理)
```

## 開發環境配置

### DevContainer 環境

專案使用 VS Code DevContainer 提供一致的開發環境，配置位於 `.devcontainer/` 目錄。

#### 已安裝元件

| 元件 | 版本 | 說明 |
|------|------|------|
| PHP | 8.4 | 含所有 Laravel 12 必需擴展 |
| MariaDB | 10.11 | MySQL 相容，開發環境資料庫 |
| Redis CLI | 7.0+ | 緩存和隊列除錯工具 |
| Node.js | 20.x | Vite 前端建置 |
| Composer | 最新 | PHP 套件管理 |

#### 資料庫配置

開發環境使用容器內建的 MariaDB，容器啟動時自動啟動服務。

**連線資訊：**
```
Host:     0.0.0.0
Port:     3306
Database: onegate
Username: onegate
Password: [REDACTED]
```

**初始化資料庫：**
```bash
# 首次使用時執行，創建資料庫和用戶
init-mysql
```

#### 開發服務器

開發環境使用 `php artisan serve`，不使用 Nginx：

```bash
# 啟動開發服務器
php artisan serve

# 訪問 http://localhost:8000
```

#### 常用命令

```bash
# 資料庫初始化
init-mysql

# 啟動開發服務器
php artisan serve

# 資料庫遷移
php artisan migrate

# 查看 MariaDB 狀態
sudo service mariadb status

# 連接資料庫
mariadb -u onegate -p onegate
```

## 常見問題與解決方案

### Q1: Job 查詢不到剛創建的訂單？
A: 檢查是否啟用了 `after_commit` 配置，確保 Job 在事務提交後執行。

### Q2: 商戶收到通知時訂單狀態不對？
A: 確保 `NotifyMerchant` Job 使用 `ShouldQueueAfterCommit` 接口。

### Q3: 回調被重複處理？
A: 使用 Redis 鎖（`Cache::lock()`）防止並發處理。

### Q4: 上游平台回調超時？
A: 回調 Controller 立即響應，使用 Queue 異步處理業務邏輯。

### Q5: 訂單狀態不一致？
A: 所有狀態變更必須在 `DB::transaction` 中，並使用 `lockForUpdate()`。

---

## 重要提醒

⚠️ **支付系統容錯率為零，開發時必須：**
1. 所有金額相關操作使用事務
2. Job 必須在事務提交後執行
3. 關鍵操作必須加鎖
4. 完善的日誌記錄
5. 充分的錯誤處理和重試機制

💡 **遇到問題時優先檢查：**
1. 事務與 Queue 的時序
2. Redis 鎖是否正確釋放
3. 日誌中的錯誤訊息
4. Horizon 隊列狀態