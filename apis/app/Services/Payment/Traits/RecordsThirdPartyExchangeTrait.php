<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use App\Models\Order;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\OrderRequestLogger;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 三方往返紀錄的共用輔助（寫進 {@see \App\Models\OrderRequestLog}，工單 target/tickets#4446、target/tickets#4449）。
 *
 * 為什麼要記：被防線擋下的回調不會動訂單，訂單停在原狀，跟「玩家根本沒付」長得一樣；
 * 對不到訂單的回調連訂單都沒有，更是沒地方留。客服要回答「為什麼沒上分」就只能靠這張表。
 *
 * 硬規則：
 *
 * 🔴 **一律在交易外呼叫**。寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒，
 * 剛好丟掉最想留的那筆。回調類的寫法是在 `handle()` 外層包 try/finally，交易在 finally 之前就結束。
 *
 * 🔴 **傳 `$request->all()` 而不是 `validate()` 的結果**：`validate()` 只回傳規則裡列出的欄位，
 * 三方多送的欄位（簽名、額外狀態碼）會被丟掉，而那些往往正是事後要查的東西。
 *
 * 🔴 **寫入失敗不可影響主流程**：{@see OrderRequestLogger} 自己包了 try/catch，這裡不再處理。
 *
 * 代收與代付、建單與回調，能記的一律記。代付的「送出請求」雖然 `withdrawal_payout_records`
 * 已有 `payout_order_request_body` 與 `payout_order_response`，仍另外記一筆：那張表一筆 record
 * 只留得下最後一次的內容，沒有 HTTP 狀態、也沒有統一的判定值，而且與代收分屬兩張表、查法不同。
 * 兩邊並存是刻意的。
 *
 * @knowledge rules/payment-callback-common-checks
 */
trait RecordsThirdPartyExchangeTrait
{
    /**
     * 本次往返的判定結果。
     *
     * 預設 EXCEPTION：`handle()` 的 finally 一定會寫一筆，中途被未預期的例外中斷時就沿用這個值，
     * 不會留下一筆看起來正常的紀錄。各判定點負責在往下走之前覆寫它。
     */
    protected OrderRequestResultEnum|string $result = OrderRequestResultEnum::EXCEPTION;

    /**
     * 本次往返對應到的訂單／代付請求，供 finally 寫紀錄時掛關聯用。
     *
     * 查到之後要立刻指派，不要等處理完——被防線擋下或中途拋例外時，finally 仍會用到它。
     * 對不到時維持 null，靠 order_no 與 third_party_order_no 事後對回來。
     */
    protected Order|WithdrawalPayoutRecord|null $loggedOrder = null;

    /**
     * 記錄一次三方往返。
     *
     * @param  Order|WithdrawalPayoutRecord|null  $order  代收傳訂單、代付傳 payout record；
     *                                                    對不到（例如來源 IP 在查單之前就被擋）時傳 null，
     *                                                    靠 $orderNo 與 $thirdPartyOrderNo 事後對回來
     * @param  mixed  $request  請求內容原文；陣列會被 json 編碼
     * @param  mixed  $response  響應內容原文；陣列會被 json 編碼
     * @param  string|null  $sourceIp  這次往返的對端 IP：入站＝三方打來的來源 IP（Common::getIp()）、
     *                                 出站＝三方伺服器 IP（curl 的 primary_ip，見 HttpLoggerCustom）
     * @param  int|null  $httpStatus  這次往返的 HTTP 狀態：出站＝三方回我方的、入站＝我方回三方的
     *                                （取 callback 回應 DTO 的 httpStatusCode，controller 就是拿它回應）；
     *                                兩邊都可能是 null——出站連不上、入站在決定狀態之前就拋例外
     */
    protected function logExchange(
        OrderRequestActionEnum $action,
        string $url,
        mixed $request,
        mixed $response,
        OrderRequestResultEnum|string|null $result,
        ?array $requestHeaders = null,
        Order|WithdrawalPayoutRecord|null $order = null,
        ?string $orderNo = null,
        ?string $thirdPartyOrderNo = null,
        ?string $sourceIp = null,
        ?int $httpStatus = null,
    ): void {
        app(OrderRequestLogger::class)->record(
            action: $action,
            url: $url,
            order: $order,
            orderNo: $orderNo,
            thirdPartyOrderNo: $thirdPartyOrderNo,
            sourceIp: $sourceIp,
            httpStatus: $httpStatus,
            requestHeaders: $requestHeaders,
            request: $request,
            response: $response,
            result: $result,
        );
    }

    /**
     * finally 寫紀錄之前，收掉還開著的交易。
     *
     * 回調的 `process()` 外層只 catch `Exception`，接不住 `Error`（TypeError、記憶體不足這類）。
     * 走到那條路徑時交易還開著就進了 finally，紀錄會跟著整個交易被 rollback——
     * 而程式炸掉那次正是最需要留下往返原文的一次。
     *
     * 正常路徑（commit 或 rollback 都做完了）這裡是 no-op，不會動到任何東西。
     *
     * 🔴 只收掉**進入 handle() 之後自己多開的**層數，所以要帶 `$baseline`：測試用 RefreshDatabase
     * 本來就開著一層外層交易，收到 0 會把整個測試的資料一起 rollback。
     */
    protected function closeDanglingTransaction(int $baseline = 0): void
    {
        if (DB::transactionLevel() <= $baseline) {
            return;
        }

        Log::warning('回調結束時交易仍開著，先 rollback 再寫往返紀錄', [
            'class' => static::class,
            'level' => DB::transactionLevel(),
            'baseline' => $baseline,
        ]);

        while (DB::transactionLevel() > $baseline) {
            DB::rollBack();
        }
    }

    /**
     * 出站失敗時從例外取回三方的 HTTP 狀態；連不上（無響應）時為 null。
     */
    protected function httpStatusOf(Throwable $e): ?int
    {
        return $e instanceof RequestException ? $e->response->status() : null;
    }

    /**
     * 出站失敗時判定 result：三方有給錯誤碼就記 `failed:<碼>`，否則記 exception。
     *
     * 🔴 **非 2xx 不等於連不上**。好幾家（EasyPay、Ap8Pay）用 HTTP 400 承載業務錯誤，
     * body 裡帶著 SERVICE_UNDER_MAINTENANCE、MERCHANT_INSUFFICIENT_BALANCE 這類碼。
     * `sendRequest()` 的 `->throw()` 會先把它變成例外，呼叫端若直接記 EXCEPTION，
     * 「三方明確拒絕」與「連線斷掉」在這張表上就長得一模一樣——而那正是 result 要分的東西。
     *
     * $codeKeys 用三方 response 裡錯誤碼的鍵，支援 dot path（例 `error.type`）；
     * 依序取第一個非空字串。**只取 type／code 這類欄位，不要取 message**：message 是會變的
     * 自由文字，原文本來就在 response 欄位裡。取不到（連不上、body 非 JSON、沒有該鍵）就退回
     * EXCEPTION，所以多傳幾個鍵是安全的。
     */
    protected function providerFailureOf(Throwable $e, string ...$codeKeys): OrderRequestResultEnum|string
    {
        if (!$e instanceof RequestException) {
            return OrderRequestResultEnum::EXCEPTION;
        }

        foreach ($codeKeys as $key) {
            $code = $e->response->json($key);

            if (is_string($code) && $code !== '') {
                return OrderRequestResultEnum::providerFailure($code);
            }
        }

        return OrderRequestResultEnum::EXCEPTION;
    }
}
