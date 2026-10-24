<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 金流訂單的三方往返紀錄（工單 target/tickets#4446、target/tickets#4449）。建表理由見該 migration 的註解。
 *
 * 🔴 **append-only**：只 insert，禁止 update／delete。日誌被改過就失去證據價值；
 * 清理一律以保留期整批刪（本次未實作）。
 * 🔴 **本表存三方往返原文、不遮罩**，含明文商戶金鑰與玩家轉帳憑證連結：
 * 禁止經任何 API／後台頁面暴露、禁止匯出。
 * 🔴 **只收金流往返，禁止寫入遊戲商往返**：`orderable` 的 morph alias 一律只有
 * `deposit_order`（{@see Order}）與 `payout_record`（{@see WithdrawalPayoutRecord}）兩種。
 * 遊戲商注單回調的量比金流高好幾個數量級，要記往返一律另開表，不要往這裡加 alias。
 * 🔴 **查往返必須連 `orderable_type` 一起比**：代收與代付兩張表的 id 空間重疊
 * （實測 TPower：`orders` 到 2030 萬、`withdrawal_payout_records` 到 186 萬），只比 id 會撈到對方的紀錄。
 *
 * @property int $id
 * @property string $orderable_type morph alias：deposit_order / payout_record（一律有值）
 * @property int|null $orderable_id 對不到訂單／代付請求時為 NULL
 * @property string|null $order_no
 * @property string|null $third_party_order_no
 * @property string $action
 * @property string $url
 * @property string|null $source_ip
 * @property int|null $http_status
 * @property string|null $request_headers
 * @property string|null $request_body
 * @property string|null $response_body
 * @property string|null $result
 */
class OrderRequestLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 這筆往返的對象（代收 Order 或代付 WithdrawalPayoutRecord，alias 見 AppServiceProvider 的 morphMap） */
    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }
}
