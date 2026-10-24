<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Helpers\Common;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 寫入金流訂單的三方往返紀錄（{@see OrderRequestLog}，工單 target/tickets#4446、target/tickets#4449）。
 *
 * 硬規則：
 * - 🔴 **寫入失敗不可影響主流程**：整段包 try/catch，寫不進去只記一行 warning。
 *   日誌掛掉不能讓建單或回調跟著失敗。
 * - 🔴 **呼叫端必須在交易外呼叫**：寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒，
 *   剛好丟掉最想留的那筆。
 * - 🔴 **不遮罩**：原文進 DB，理由與代價見 migration 註解。
 * - 🔴 **`$order` 只收代收 {@see Order} 或代付 {@see WithdrawalPayoutRecord}**，禁止傳入遊戲商相關 model：
 *   遊戲商往返量體差好幾個數量級，一律另開表（理由見 migration 註解）。
 *
 * 呼叫端：Ap8Pay、B3Wallet、EasyPay、MaxPay、TngPin 的代收建單與回調，以及全部 30 家的代付請求
 * （{@see Traits\WithdrawalPayoutTrait}）。保留期與清理排程見
 * {@see \App\Console\Commands\Schedule\CleanOrderRequestLogs}。
 */
readonly class OrderRequestLogger
{
    /**
     * 各欄的截斷上限。
     *
     * 🔴 **這是攻擊面控制，不只是防寫爆欄位**：external 的回調路由對外公開、沒有認證，
     * 而被白名單擋下的請求同樣會寫一筆。上限訂太寬等於開一條未認證的寫入放大管道——
     * 60000 bytes × 高頻請求，灌的是主庫與 binlog，撐爆的是全站寫入（含正常的儲值與提款）。
     * ⚠️ 這是目前**唯一**的一道：回調路由刻意沒有掛 throttle（理由見 `routes/external/api.php`
     * 那兩條路由的註解——金流商用一兩個出口 IP 服務整站，尖峰破百筆/分，限流擋到的是真回調）。
     * 所以單筆大小的上限不能再放寬。
     *
     * 數字取自實測（2026-10-08，Dev 的 681 筆往返）：request 最大 248、response 最大 104、
     * headers 最大 466 bytes。以下留了一個數量級以上的餘裕，正常流量不會被截到一個字。
     *
     * `request` 的上限壓最低，因為那是攻擊者唯一能控制大小的欄位。
     */
    private const int REQUEST_LIMIT = 4096;

    private const int RESPONSE_LIMIT = 16384;

    private const int HEADERS_LIMIT = 2048;

    /**
     * @param  Order|WithdrawalPayoutRecord|null  $order  這次往返的對象：代收是訂單，代付是那一次
     *                                                    代付請求（一張提款單可能有多次）。對不到
     *                                                    （例如來源 IP 在查單之前就被擋下）時傳 null，
     *                                                    靠 $orderNo 與 $thirdPartyOrderNo 事後對回來
     * @param  string|null  $orderNo  我方單號：代收＝orders.order_no，代付＝payout record 的 transaction_no
     * @param  mixed  $request  請求內容；陣列會被 json 編碼
     * @param  mixed  $response  響應內容；陣列會被 json 編碼
     */
    public function record(
        OrderRequestActionEnum $action,
        string $url,
        Order|WithdrawalPayoutRecord|null $order = null,
        ?string $orderNo = null,
        ?string $thirdPartyOrderNo = null,
        ?string $sourceIp = null,
        ?int $httpStatus = null,
        ?array $requestHeaders = null,
        mixed $request = null,
        mixed $response = null,
        OrderRequestResultEnum|string|null $result = null,
    ): void {
        // 兩邊的我方單號欄位名不同（代收 order_no、代付 transaction_no），各自取各自的
        $orderNo ??= match (true) {
            $order instanceof Order => (string) $order->order_no,
            $order instanceof WithdrawalPayoutRecord => (string) $order->transaction_no,
            default => null,
        };

        try {
            OrderRequestLog::create([
                // 🔴 型別一律填，連對不到訂單時也填（理由見 OrderRequestActionEnum::orderableType()）。
                // 有訂單時以訂單本身為準（getMorphClass() 取的是 morphMap 的 alias、不是 FQCN），
                // 兩者不一致代表呼叫端把代付訂單配上了代收的 action，是程式錯誤，以訂單為準比較不會誤導。
                'orderable_type' => $order?->getMorphClass() ?? $action->orderableType(),
                'orderable_id' => $order?->id,
                'order_no' => $orderNo,
                'third_party_order_no' => $thirdPartyOrderNo,
                'action' => $action->value,
                'url' => $url,
                'source_ip' => $sourceIp,
                'http_status' => $httpStatus,
                'request_headers' => $this->stringify($this->flattenHeaders($requestHeaders), self::HEADERS_LIMIT),
                'request_body' => $this->stringify($request, self::REQUEST_LIMIT),
                'response_body' => $this->stringify($response, self::RESPONSE_LIMIT),
                'result' => $result instanceof OrderRequestResultEnum ? $result->value : $result,
            ]);
        } catch (Throwable $e) {
            Log::warning('OrderRequestLogger write failed', [
                'action' => $action->value,
                'orderNo' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 攤平 header（Laravel 的 header 是 name => [value]）。
     *
     * 🔴 刻意不用 {@see Common::flattenHeaders()}：那支會順便遮罩憑證，而本表的用途就是
     * 留下原文（理由與代價見 migration 註解）。這裡只攤平、不建立任何遮罩鍵清單。
     *
     * @param  array<string, mixed>|null  $headers
     * @return array<string, mixed>|null
     */
    private function flattenHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        return array_map(
            static fn ($value) => is_array($value) ? ($value[0] ?? null) : $value,
            $headers,
        );
    }

    private function stringify(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_string($value) ? $value : Common::safeJsonEncode($value);

        return mb_strcut((string) $text, 0, $limit);
    }
}
