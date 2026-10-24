<?php

declare(strict_types=1);

namespace App\Services\BotNotify;

use App\Enums\BotNotify\AlertCategory;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Exceptions\SmsGatewayException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Exceptions\VendorSetupException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use ValueError;

/**
 * 例外告警的唯一控制點：分類 → 訊息組裝 → 靜音／降噪決策 → 發送。
 *
 * bootstrap 例外處理與 ApiException 都經由 {@see handle()} 呼叫本類別。
 * 分類與降噪邏輯都在本類別內；appTrace 黑名單見 {@see resolveAt()}。
 *
 * 靜音（不通知，但 log 照舊）：ApiException 屬業務邏輯例外（可預期的規則性拒絕，如餘額不足、
 * 驗證失敗）；ConnectionException 屬三方網路瞬時故障。兩者都在 {@see classify()} 開頭直接回 null。
 *
 * 刻意不遮罩（忠實移植自舊 Handler）：{@see classify()} 放進訊息的 handler_context['url']
 * （含 query string）與還原 binding 後的 sql 原樣送出，不經 Common::maskCredentials()。
 * BotNotify 送的是排查用的除錯資訊，遮了會難排查，CLAUDE.md 的遮罩紅線已把 BotNotify 列為例外。
 * 另外 maskCredentials() 只遮陣列裡鍵名吻合的憑證值、非陣列原樣回傳；url 與 sql 都是字串，
 * 套上去內容也不會變。真要遮得解析 query string 與 SQL 內文，正好會遮到排查要看的值。
 *
 * 踩坑紀錄：external 面的路由真實前綴是 external/api（bootstrap/app.php），任何用 Str::is() 這類
 * pattern 比對 external 路由的代碼若寫成 external/deposit/notify/* 會永遠比不中；舊版業務路由
 * 告警名單曾因此從未發出過代收／代付回調告警。
 */
class AlertGate
{
    private const string LOCK_PREFIX = 'bot_notify:throttle:';

    private const int WINDOW_SECONDS = 1800;

    /**
     * appTrace 黑名單：app/ 內經由這些框架類別轉發的 frame 不具定位意義（prefix 比對）。
     */
    private const array SKIP_FRAMEWORK_CLASSES = [
        'Illuminate\\Support\\Facades\\Facade',
        'Illuminate\\Foundation\\Application',
        'Illuminate\\Container\\Container',
        'Illuminate\\Pipeline\\Pipeline',
    ];

    /**
     * 唯一入口。能到此處的＝Laravel 已判定要 report 的例外（扣除 internalDontReport、扣除自帶 report() 短路者）。
     */
    public function handle(Throwable $e): void
    {
        try {
            $classified = $this->classify($e);

            if ($classified === null) {
                return;
            }

            $this->dispatch($classified['title'], $classified['traceInfo']);
        } catch (Throwable $gateError) {
            // 告警機制本身的錯誤絕不影響主流程。
            Log::error('AlertGate 處理失敗', [
                'error' => $gateError->getMessage(),
                'trace' => Common::formatTrace($gateError),
            ]);
        } finally {
            $this->clearContext();
        }
    }

    /**
     * 依例外類型決定中文標題與 trace context，回傳 null 代表靜音（不發）。
     *
     * @return array{title: string, traceInfo: array<string, mixed>}|null
     */
    protected function classify(Throwable $e): ?array
    {
        // 靜音清單，一律不通知：
        // - ApiException：業務邏輯例外（可預期的規則性拒絕，如餘額不足、驗證失敗）。
        // - ConnectionException：三方連線／讀取超時，屬外部網路瞬時故障，收到告警也無從即時處置。
        //   log 不受影響：framework 預設 report logging 一定會寫（bootstrap/app.php 的 report()
        //   對非 ApiException 回傳 true，不短路預設 logging），HTTP 請求路徑另有
        //   bootstrap/app.php 的 render() 寫 Log::warning('連線超時')＋handler_context。
        if ($e instanceof ApiException || $e instanceof ConnectionException) {
            return null;
        }

        $traceInfo = ['at' => $this->resolveAt($e)];
        $status = null;
        if ($e instanceof RequestException) {
            $status = $e->response?->status() ?? 0;
            $category = AlertCategory::HTTP_ERROR;
            $traceInfo['status_code'] = $status;
            $traceInfo['error_type'] = AlertCategory::httpErrorType($status);
            $traceInfo['msg'] = Str::limit($e->getMessage(), 500);
            $traceInfo['handler_context'] = Common::getGuzzleHandlerContext($e);
            // 2026-08-10 靜音：ConnectionException 改於本方法開頭直接回 null，此分支暫時停用。
            // 若日後要恢復告警，移除開頭靜音條件中的 ConnectionException 並解開下列註解即可。
            // } elseif ($e instanceof ConnectionException) {
            //     $category = AlertCategory::HTTP_TIMEOUT;
            //     $traceInfo['msg'] = '連線超時';
            //     $traceInfo['handler_context'] = Common::getGuzzleHandlerContext($e);
        } elseif ($e instanceof QueryException) {
            $category = AlertCategory::SQL_ERROR;
            $errorInfo = $e->errorInfo;
            $traceInfo['msg'] = sprintf('[%s] %s', $errorInfo[1] ?? '', $errorInfo[2] ?? $e->getMessage());
            $traceInfo['sql'] = Common::queryToSql($e->getSql(), $e->getBindings());
        } elseif ($e instanceof LockTimeoutException) {
            $category = AlertCategory::LOCK_TIMEOUT;
            $traceInfo['msg'] = '資源被鎖定';
        } elseif ($e instanceof ValueError) {
            $category = AlertCategory::ENUM_ERROR;
            $traceInfo['msg'] = $e->getMessage();
        } elseif ($e instanceof VendorSetupException) {
            $category = AlertCategory::VENDOR_SETUP;
            $traceInfo['msg'] = $e->getMessage();
        } elseif ($e instanceof VendorNotFoundException) {
            $category = AlertCategory::VENDOR_NOT_FOUND;
            $traceInfo['msg'] = $e->getMessage();
        } elseif ($e instanceof VendorAccountException) {
            $category = AlertCategory::VENDOR_ACCOUNT;
            $traceInfo['msg'] = $e->getMessage();
        } elseif ($e instanceof InvalidArgumentException) {
            $category = AlertCategory::INVALID_ARGUMENT;
            $traceInfo['msg'] = $e->getMessage();
        } elseif ($e instanceof SmsGatewayException) {
            $category = AlertCategory::SMS_GATEWAY;
            $traceInfo['msg'] = $e->getMessage();
            $traceInfo += $e->alertContext();
        } else {
            $category = AlertCategory::THROWABLE;
            $traceInfo['msg'] = $e->getMessage();
        }

        $context = $this->getContext();
        $traceInfo = array_merge($context['trace_info'] ?? [], $traceInfo);
        $traceInfo['tid'] = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        if ($context['title'] ?? '') {
            $title = sprintf('【%s】%s', $context['title'], $category->label($status));
        } else {
            $title = $category->label($status);
        }

        return [
            'title' => $title,
            'traceInfo' => $traceInfo,
        ];
    }

    private function dispatch(string $title, array $traceInfo): void
    {
        if (!$this->attempt($traceInfo)) {
            return;
        }

        BotNotify::channel('warn')->send($title, $traceInfo);
    }

    /**
     * 告警標題用的歸屬名稱／id：VENDOR_INFO（遊戲商）與 AGENT_CASH_FLOW（金流商）正常不會同時存在，
     * 優先取遊戲商，沒有才取金流商。
     *
     * @return array{title?: string, trace_info?: array<string, mixed>}
     */
    private function getContext(): array
    {
        if ($vendorInfo = Context::getHidden(ContextKeyEnum::VENDOR_INFO->value)) {
            return [
                'title' => $vendorInfo->name,
                'trace_info' => [
                    'vendor_info_id' => $vendorInfo->id,
                ],
            ];
        }

        if ($agentCashFlow = Context::getHidden(ContextKeyEnum::AGENT_CASH_FLOW->value)) {
            return [
                'title' => $agentCashFlow->name,
                'trace_info' => [
                    'agent_cash_flow_id' => $agentCashFlow->id,
                ],
            ];
        }

        return [];
    }

    /**
     * 就地降噪判斷（SETNX 語意）：同一個 key 在冷卻視窗內只有第一筆通過，其餘直接丟棄。
     * key 固定用拋出位置（file:line）當唯一維度，統一含白標識別（site_name）避免多白標共用 redis 時跨白標遮蔽。
     */
    private function attempt(array $traceInfo): bool
    {
        $key = sprintf('%s:%s', config('custom.site_name'), $traceInfo['at'] ?? 'unknown');

        return Cache::add(self::LOCK_PREFIX . $key, true, self::WINDOW_SECONDS);
    }

    /**
     * 找出第一個有意義的應用代碼呼叫位置（排除 vendor／bootstrap／黑名單框架類別），
     * 回傳相對於專案根目錄的 file:line。
     *
     * 例外本身的建構位置（$e->getFile()/getLine()）若已落在 app/，直接採用——
     * getTrace() 的第一個 frame 是「呼叫端」而非「拋出點」，兩者只有例外從 vendor 內部
     * 拋出（QueryException／ConnectionException／RequestException）時才會一致，
     * app 內直接 throw 的例外（VendorSetupException 等）用 trace 會多算一層。
     */
    private function resolveAt(Throwable $e): string
    {
        if ($this->isAppFile($e->getFile())) {
            return $this->toRelativeLocation($e->getFile(), $e->getLine());
        }

        $frame = array_find($e->getTrace(), fn (array $frame): bool => $this->isAppFrame($frame));

        return $this->toRelativeLocation($frame['file'] ?? $e->getFile(), $frame['line'] ?? $e->getLine());
    }

    /**
     * 有意義的應用代碼 frame：檔案落在 app/（排除 bootstrap/），且 class 不在框架黑名單。
     */
    private function isAppFrame(array $frame): bool
    {
        return isset($frame['file'])
            && !str_contains($frame['file'], DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR)
            && $this->isAppFile($frame['file'])
            && !$this->isSkippedFrameworkClass($frame['class'] ?? '');
    }

    private function isAppFile(string $file): bool
    {
        return !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && str_contains($file, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
    }

    private function isSkippedFrameworkClass(string $class): bool
    {
        return array_any(self::SKIP_FRAMEWORK_CLASSES, fn (string $skip): bool => str_starts_with($class, $skip));
    }

    private function toRelativeLocation(string $file, int $line): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ':' . $line;
    }

    /**
     * 清理 context。
     *
     * forget() 與 forgetHidden() 必須兩行都下：vendor 相關 context 是用 addHidden 寫入的，
     * Context::forget() 只清 data 不清 hidden。hidden 不會自動進 log（log processor 只帶 data），
     * 但漏了 forgetHidden 會讓 VENDOR_INFO／AGENT_CASH_FLOW 殘留給同一請求後續的程式讀到，
     * 例如本類別的 {@see getContext()} 會把下一則不相干的告警掛到這個遊戲商或金流商名下。
     */
    private function clearContext(): void
    {
        Log::withoutContext(['request', 'response']);

        $keys = [
            ContextKeyEnum::VENDOR->value,
            ContextKeyEnum::VENDOR_INFO->value,
            ContextKeyEnum::AGENT_CASH_FLOW->value,
        ];

        Context::forget($keys);
        Context::forgetHidden($keys);
    }
}
