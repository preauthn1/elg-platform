<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 存款人工確認紀錄（多金流商共用）：一張需要人工確認的充值訂單一列。
 *
 * 舊代理後台（API_Agent 的 DepositManualReview::allowsCredit()）在手動上分與重置狀態時只看 allow_credit：
 * 沒有列的充值訂單行為不變；有列時 allow_credit 為 1 才放行。首個使用者是 CCPayment 風險交易
 * （DepositCallback 建單時寫 pending、ccpayment:sync-risky-refunds 依退款結果更新）。
 *
 * 寫入規則（接入新金流商或新原因前逐條確認）：
 * - 寫入一律經 createPending() 與 resolve()，allow_credit 由 result 決定
 *   （DepositManualReviewResultEnum::allowsCredit()），兩欄不會互相矛盾。禁止直接 update 這兩欄。
 * - 兩個方法都必須在交易內呼叫，與充值訂單的建立或狀態變更同一個交易：建單與寫列之間沒有空窗，
 *   API_Agent 不會在空窗內放行手動上分。
 * - 禁止刪列：刪掉就等於「沒有列」，鎖跟著失效。delete() 直接拋錯；查詢建構器的 delete 擋不到，同樣禁止。
 * - reason／result 欄位是 utf8mb4_0900_bin（逐字比對），讀取端不掛 enum cast，
 *   以 ->value 的字串比較，避免髒值在讀取時直接拋錯、中斷排程。
 *
 * @knowledge rules/provider-ccpayment
 *
 * @property int $id
 * @property int $order_id 充值訂單 orders.id
 * @property int $agent_cash_flow_id 金流商戶 agent_cash_flows.id
 * @property int $cash_flow_id 金流商 cash_flows.id
 * @property string $reason 需人工確認的原因（DepositManualReviewReasonEnum）
 * @property string $result 處理結果（DepositManualReviewResultEnum）
 * @property int $allow_credit 可否手動上分 0:不可 1:可
 * @property array<string, mixed>|null $proof 金流商給的依據，僅供查證
 * @property Carbon|null $resolved_at 確認處理結果的時間
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|DepositManualReview newModelQuery()
 * @method static Builder<static>|DepositManualReview newQuery()
 * @method static Builder<static>|DepositManualReview query()
 *
 * @mixin \Eloquent
 */
class DepositManualReview extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'proof' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * 建立待處理列（不可上分）。必須與建立充值訂單同一個交易。
     *
     * 刻意不先查再寫：該列本來就不該存在，對不存在的鍵下鎖會拿 gap lock；重複寫入由 uk_order 擋下並拋錯，讓整個交易 rollback。
     */
    public static function createPending(Order $order, AgentCashFlow $agentCashFlow, DepositManualReviewReasonEnum $reason): self
    {
        self::assertInTransaction();

        return self::query()->create([
            'order_id' => $order->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'cash_flow_id' => $agentCashFlow->cash_flow_id,
            'reason' => $reason->value,
            ...self::resultAttributes(DepositManualReviewResultEnum::PENDING),
        ]);
    }

    /**
     * 寫入處理結果。$review 必須是同一個交易內以 lockForUpdate() 讀出的列。
     *
     * @param  array<string, mixed>  $proof
     */
    public function resolve(DepositManualReviewResultEnum $result, array $proof): void
    {
        self::assertInTransaction();

        $this->update([
            ...self::resultAttributes($result),
            'proof' => $proof,
        ]);
    }

    public function isPending(): bool
    {
        return $this->result === DepositManualReviewResultEnum::PENDING->value;
    }

    /**
     * @throws LogicException 一律拋出：刪列會讓舊代理後台的鎖失效
     */
    public function delete(): ?bool
    {
        throw new LogicException('deposit_manual_reviews 禁止刪列：刪掉後舊代理後台會放行手動上分');
    }

    /**
     * @return array{result: string, allow_credit: int, resolved_at: Carbon|null}
     */
    private static function resultAttributes(DepositManualReviewResultEnum $result): array
    {
        return [
            'result' => $result->value,
            'allow_credit' => $result->allowsCredit() ? 1 : 0,
            'resolved_at' => $result->isResolved() ? now() : null,
        ];
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('deposit_manual_reviews 必須在交易內寫入，與充值訂單的變更同進同退');
        }
    }
}
