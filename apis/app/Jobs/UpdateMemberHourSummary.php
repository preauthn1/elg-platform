<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Helpers\Common;
use App\Jobs\Concerns\SafelyDispatchable;
use App\Models\MemberStatistic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代收／代付／人工開洗分成功時，累加該會員該整點的存提匯總（member_hourly_statistics），
 * 並同步更新 member_statistics 的 last_deposit_at / last_withdraw_at。
 *
 * 帶 $source（{@see MemberHourlySourceEnum}）時同一句 upsert 同時累加總額欄與該來源的明細欄（target/tickets#4333 拆六類）；
 * 不帶時只累加總額欄（相容舊呼叫端，明細由每小時排程重算補齊）。
 * 並發安全：單句 INSERT ... ON DUPLICATE KEY UPDATE 原子累加，無「先讀再寫」空窗。
 */
class UpdateMemberHourSummary implements ShouldQueue
{
    use Queueable;
    use SafelyDispatchable;

    public const string TYPE_DEPOSIT = 'deposit';

    public const string TYPE_WITHDRAWAL = 'withdrawal';

    /**
     * 來源明細類別（MemberHourlySourceEnum 的 value）；null 只累加總額。
     * 刻意不用 constructor promotion 而給類別層預設值：部署切換瞬間 queue 裡還排著舊版序列化的 job，
     * 反序列化時沒有這個屬性，promotion 的 typed property 會是「未初始化」而在 handle() 讀取時拋 Error；
     * 類別層預設 null 讓舊 payload 照常執行（只累加總額，明細由排程補）。
     */
    public ?string $source = null;

    /**
     * @param  string  $point  平台點數（orders / withdrawal_orders.point，已含 rate 正規化）
     * @param  string  $occurredAt  分桶時間（代收 pay_time、代付 payment_time、人工帳變 created_at）
     * @param  string|null  $source  來源明細類別（MemberHourlySourceEnum 的 value）；null 只累加總額
     */
    public function __construct(
        public int $memberId,
        public string $type,
        public string $point,
        public string $occurredAt,
        ?string $source = null,
    ) {
        $this->source = $source;
    }

    public function handle(): void
    {
        // 最後一道守衛：member_id = 0 是代理自助洗分／上分，不屬會員統計（呼叫端已先擋，此處防其他入口漏接）
        if ($this->memberId <= 0) {
            Log::warning('UpdateMemberHourSummary skipped: member_id <= 0', ['type' => $this->type, 'point' => $this->point]);

            return;
        }

        Log::info('UpdateMemberHourSummary handling', [
            'member_id' => $this->memberId,
            'type' => $this->type,
            'source' => $this->source,
            'point' => $this->point,
            'occurred_at' => $this->occurredAt,
        ]);

        $summaryHour = Carbon::parse($this->occurredAt)->startOfHour()->toDateTimeString();
        // 外部數值入境正規化（金額紅線）
        $point = Common::numAdd('0', $this->point, 4);

        $isDeposit = $this->type === self::TYPE_DEPOSIT;
        $countColumn = $isDeposit ? 'deposit_count' : 'withdrawal_count';
        $amountColumn = $isDeposit ? 'deposit_amount' : 'withdrawal_amount';

        // 首次新增：count 起始 1、amount 起始為本筆金額
        $insert = [
            'member_id' => $this->memberId,
            'summary_hour' => $summaryHour,
            $countColumn => 1,
            $amountColumn => $point,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        // 命中既有行：原子累加 count + 1、amount + 本筆金額（VALUES 取上方待插入值）
        $update = [
            $countColumn => DB::raw("{$countColumn} + 1"),
            $amountColumn => DB::raw("{$amountColumn} + VALUES({$amountColumn})"),
            'updated_at' => now(),
        ];

        $source = $this->resolveSource($isDeposit);
        if ($source !== null) {
            // 欄位名來自 Enum 常數，非外部輸入
            $sourceCount = $source->countColumn();
            $sourceAmount = $source->amountColumn();
            $insert[$sourceCount] = 1;
            $insert[$sourceAmount] = $point;
            $update[$sourceCount] = DB::raw("{$sourceCount} + 1");
            $update[$sourceAmount] = DB::raw("{$sourceAmount} + VALUES({$sourceAmount})");
        }

        // 原子 UPSERT 累加（單句 INSERT ... ON DUPLICATE KEY UPDATE）：
        // 取代原「insertOrIgnore + lockForUpdate + save」兩段式——後者在高併發同會員同整點時，
        // 多個 job 的 insert intention lock 與 lockForUpdate 排他鎖互等而 deadlock(1213)。
        // 依 unique_index(member_id, summary_hour) 命中既有行時原子累加；金額走 decimal(18,4) 精確加。
        DB::table('member_hourly_statistics')->upsert($insert, ['member_id', 'summary_hour'], $update);

        // 同步更新 member_statistics 的最後代收/代付時間（單純設值，不需鎖行）
        MemberStatistic::insertOrIgnore([
            'member_id' => $this->memberId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MemberStatistic::query()
            ->where('member_id', $this->memberId)
            ->update([
                $isDeposit ? 'last_deposit_at' : 'last_withdraw_at' => $this->occurredAt,
            ]);
    }

    /**
     * 來源與總額型別必須同側（充值來源配 deposit、提現來源配 withdrawal）；不同側視為呼叫端 bug，
     * 記 warning 後只累加總額，讓排程重算把明細補正，不讓整筆 job 失敗。
     */
    private function resolveSource(bool $isDeposit): ?MemberHourlySourceEnum
    {
        if ($this->source === null) {
            return null;
        }

        $source = MemberHourlySourceEnum::tryFrom($this->source);
        if ($source === null || $source->isDeposit() !== $isDeposit) {
            Log::warning('UpdateMemberHourSummary source ignored: unknown or mismatched', [
                'member_id' => $this->memberId,
                'type' => $this->type,
                'source' => $this->source,
            ]);

            return null;
        }

        return $source;
    }
}
