<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;

/**
 * 把 {@see OrderRequestLog} 整理成可以給客服看的形狀（工單 target/tickets#4446）。
 *
 * 🔴 **只回時間與判定結果這類純量，一個字的往返內容都不回**（`request_headers` /
 * `request_body` / `response_body` 全部不出現）。那張表存的是未遮罩原文、含明文商戶金鑰，
 * 而且各家金流商的憑證鍵名都不同——靠白名單過濾等於留一個要靠人維護的控制面，
 * 下一個人往清單加一個鍵就破了。什麼都不顯示才沒有維護面。
 *
 * 需求上也站得住：客服要回答的是「這筆為什麼沒上分」，那是 `result` 的工作
 * （被防線擋下時我方回給三方的 body 一律是 FAIL，只有 `result` 說得出是哪一道）；
 * 要看「三方到底送了什麼」的是開發，直接查表即可，不必經過後台。代付的訂單詳情也是這個做法。
 *
 * @knowledge rules/payment-callback-common-checks
 */
readonly class OrderRequestLogDisplayService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(Order $order): array
    {
        // 🔴 **三個鍵任一命中都算**，缺一個就會漏掉最該被看到的那種紀錄：
        // - `orderable_id`：正常情形。但防線 1（來源 IP）擋在「取訂單」之前就 return，那種是 NULL
        // - `order_no`：回調帶來的 uniqueCode。IP 被擋時只剩這個對得上
        // - `third_party_order_no`：兩種情形只剩這個對得上本單——回調只帶三方自己的訂單號、
        //   沒有我方訂單號（各家協定不同，Ap8Pay 兩個都帶但別家不保證），
        //   以及回調的 uniqueCode 被竄改成別人的訂單號、但 taskCode 是真的（需求 2 的情境）
        //
        // order_no 與 taskCode 都來自回調、是對方填的，所以偽造者拿本單的任一識別碼來試都會
        // 出現在這張單底下。這是刻意的：那正是「有人對這筆單送過假回調」的證據。
        // 🔴 **空值一律不比對**：order_no 與 third_party_order_no 在紀錄表都是 nullable
        // （IP 被擋、或查單沒帶訂單時就是空的），拿空值去比會把所有空的列全撈進別人的訂單底下。
        $keys = array_filter([
            'order_no' => (string) $order->order_no,
            'third_party_order_no' => (string) $order->third_party_order_no,
        ], static fn (string $value): bool => $value !== '');

        return OrderRequestLog::query()
            // 🔴 **先把型別釘死再比三個鍵**：代收與代付共用這張表，而 orders 與 withdrawal_orders
            // 的 id 空間重疊，三方單號也可能撞（同一家金流商的代收代付常共用序號空間）。
            // 少了這一層，代收訂單會撈到 id 相同的那張提款單的往返紀錄。
            ->where('orderable_type', $order->getMorphClass())
            ->where(function ($query) use ($order, $keys): void {
                $query->where('orderable_id', $order->id);

                foreach ($keys as $column => $value) {
                    $query->orWhere($column, $value);
                }
            })
            ->orderBy('id')
            ->get()
            ->map($this->toDisplayRow(...))
            ->all();
    }

    /**
     * 提款訂單的往返紀錄（工單 target/tickets#4449）。
     *
     * 🔴 **代付掛的是 payout record 不是提款單**：一張提款單可能有多次代付請求（重試、換渠道），
     * 一次往返對應的是其中一次請求。所以**每筆 record 各自一組紀錄**，不併成一張大表——
     * 併了之後看不出哪一次請求配哪幾筆往返，而「上一次為什麼沒成功」正是要看的東西。
     * 後台的畫面也是一樣：代付資訊一個 record 一塊，往返紀錄就掛在那塊底下。
     *
     * 其餘規則與 {@see self::forOrder()} 相同：三個鍵任一命中都算、空值不比對、型別先釘死，
     * 回傳一樣只有純量，往返原文一個字都不回。
     *
     * @return array<int, array<string, mixed>> 每筆 record 一個元素，logs 依時間排序
     */
    public function forWithdrawalOrder(WithdrawalOrder $order): array
    {
        $records = WithdrawalPayoutRecord::query()
            ->where('withdrawal_order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'transaction_no', 'third_party_transaction_no']);

        if ($records->isEmpty()) {
            return [];
        }

        return $records
            ->map(fn (WithdrawalPayoutRecord $record): array => [
                'payout_record_id' => $record->id,
                'transaction_no' => $record->transaction_no,
                'third_party_transaction_no' => $record->third_party_transaction_no,
                'logs' => $this->logsForPayoutRecord($record),
            ])
            ->all();
    }

    /**
     * 單筆代付請求的往返紀錄。
     *
     * @return array<int, array<string, mixed>>
     */
    private function logsForPayoutRecord(WithdrawalPayoutRecord $record): array
    {
        // 空值一律不比對：被白名單擋下的回調沒有我方單號，拿空值去比會把所有空的列撈進來
        $keys = array_filter([
            'order_no' => (string) $record->transaction_no,
            'third_party_order_no' => (string) $record->third_party_transaction_no,
        ], static fn (string $value): bool => $value !== '');

        return OrderRequestLog::query()
            ->where('orderable_type', $record->getMorphClass())
            ->where(function ($query) use ($record, $keys): void {
                $query->where('orderable_id', $record->id);

                foreach ($keys as $column => $value) {
                    $query->orWhere($column, $value);
                }
            })
            ->orderBy('id')
            ->get()
            ->map($this->toDisplayRow(...))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toDisplayRow(OrderRequestLog $log): array
    {
        return [
            'action' => $log->action,
            'result' => $log->result,
            'http_status' => $log->http_status,
            'third_party_order_no' => $log->third_party_order_no,
            'source_ip' => $log->source_ip,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }
}
