<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Enums\GgrPayoutStatusEnum;
use App\Enums\StatusEnum;
use App\Enums\Transaction\TransactionLogAgentPlatformEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\TransactionLogAgentTypeEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Models\TransactionLogAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GGR 發放入帳（target/tickets#4121 批次七，決議⑧）：把 ggr_settlements 的
 * UNPAID 列依 payable_amount（發揚沖抵後實發金額）入帳到代理餘額，標 PAID。
 *
 * 冪等＝「鎖來源單＋檢查來源單狀態」（照 PaymentService::updateAgentBalance
 * 的代理儲值入帳正典）：單列一個 DB 交易，持鎖後 payout_status 非 UNPAID 即
 * 跳過——交易紀錄表無 unique key，冪等只靠這道守門，順序不可對調。
 *
 * 交易紀錄寫 transaction_log_agents（代理帳變正表，2026-09-02 使用者定案）：
 * type=GGR_PROFIT_PAYOUT、from=結算列快照的總代（純歸屬不動餘額，舊代理後台
 * 報表 join 需 from 在總代線內）、to=被發代理；「入帳後餘額」照現行慣例塞
 * to_limit_of_credit（表無 before/after 欄）；remark 帶 settlement id＋期間
 * 作對帳錨點（表無 transaction_no）。
 *
 * 邊界：方案停用不發（設定端唯一的止血手段，留 UNPAID）；payable<=0（發揚
 * 沖抵發 0／負實得）直接標 PAID 不入帳；代理停用或不存在留 UNPAID 下輪重試
 * （永久停用＝列長掛 UNPAID，符合呆帳續留語意）。
 */
class PayoutService
{
    /** 已入帳 */
    public const string MODE_PAID = 'paid';

    /** 實發 0 元，僅標記 PAID 不入帳 */
    public const string MODE_ZERO_PAID = 'zero_paid';

    /** 持鎖後已非 UNPAID（重複觸發被冪等守門擋下） */
    public const string MODE_ALREADY_PAID = 'already_paid';

    /** 結算列在取號與發放之間消失（結算重跑刪除不再達階的 UNPAID 列） */
    public const string MODE_MISSING = 'missing';

    /** 代理停用/不存在，留 UNPAID 下輪重試 */
    public const string MODE_AGENT_SKIPPED = 'agent_skipped';

    /** 方案停用/不存在，留 UNPAID（營運停用方案即可攔住尚未發放的列） */
    public const string MODE_PLAN_SKIPPED = 'plan_skipped';

    /** 金額運算精度：agents.balance 為 decimal(18,4) */
    private const int AMOUNT_SCALE = 4;

    /** @var array<int, string> 發放只需的結算欄位（略過 calc_snapshot 這顆大 JSON） */
    private const array SETTLEMENT_COLUMNS = [
        'id', 'plan_id', 'agent_id', 'general_agent_id',
        'payable_amount', 'payout_status', 'period_start', 'period_end',
    ];

    /** @var array<int, string> general_agent_id => 餘額快照（純歸屬欄位，一輪讀一次） */
    private array $generalBalances = [];

    /**
     * 發放全部（或指定方案的）UNPAID 結算列；單列失敗不中斷其他列。
     *
     * @return array{counts: array<string, int>, failed: int, failures: array<int, string>}
     */
    public function payoutPending(?int $planId = null): array
    {
        $ids = $this->pendingQuery($planId)->pluck('id')->all();

        $counts = array_fill_keys(self::modes(), 0);
        $failed = 0;
        $failures = [];

        foreach ($ids as $id) {
            try {
                $counts[$this->payoutOne((int) $id)]++;
            } catch (Throwable $e) {
                $failed++;
                $failures[] = sprintf('settlement %d: %s', $id, $e->getMessage());
                Log::error('GGR 發放單列失敗', [
                    'settlement_id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        return ['counts' => $counts, 'failed' => $failed, 'failures' => $failures];
    }

    /** 發放單一結算列（單列一個 DB 交易，冪等）；回傳 MODE_* */
    public function payoutOne(int $settlementId): string
    {
        return DB::transaction(function () use ($settlementId): string {
            /** @var GgrSettlement|null $settlement */
            $settlement = GgrSettlement::query()->lockForUpdate()->find($settlementId, self::SETTLEMENT_COLUMNS);

            // 取號與發放之間被結算重跑刪掉（不再達階的 UNPAID 列）
            if ($settlement === null) {
                Log::warning('GGR 發放時結算列已不存在（結算重跑刪除）', ['settlement_id' => $settlementId]);

                return self::MODE_MISSING;
            }

            // 冪等守門：持鎖後再驗狀態，重複觸發（併發、retry、手動重跑）到此為止
            if ((int) $settlement->payout_status !== GgrPayoutStatusEnum::UNPAID->value) {
                return self::MODE_ALREADY_PAID;
            }

            // 方案停用＝營運的止血手段：尚未發放的列不得入帳（payoutOne 為公開入口，
            // 不倚賴 pendingQuery 的過濾）
            /** @var GgrPlan|null $plan */
            $plan = GgrPlan::query()->whereKey($settlement->plan_id)->first(['id', 'status']);
            if ($plan === null || (int) $plan->status !== StatusEnum::ENABLE->value) {
                Log::warning('GGR 發放跳過停用或不存在的方案，留 UNPAID', [
                    'settlement_id' => $settlement->id,
                    'plan_id' => $settlement->plan_id,
                ]);

                return self::MODE_PLAN_SKIPPED;
            }

            $payable = $this->amount($settlement->payable_amount);

            // 實發 0 元（發揚沖抵發 0／負實得）：發放流程已處理，僅標記不入帳
            if (Common::numCompare($payable, '0') <= 0) {
                $this->markPaid($settlement);

                return self::MODE_ZERO_PAID;
            }

            /** @var Agent|null $agent */
            $agent = Agent::query()->lockForUpdate()
                ->find($settlement->agent_id, ['id', 'username', 'balance', 'status']);

            // 代理停用/不存在：留 UNPAID 下輪重試（呆帳續留，恢復後自動補發）
            if ($agent === null || (int) $agent->status !== StatusEnum::ENABLE->value) {
                Log::warning('GGR 發放跳過代理，留 UNPAID 下輪重試', [
                    'settlement_id' => $settlement->id,
                    'agent_id' => $settlement->agent_id,
                    'reason' => $agent === null ? 'agent_not_found' : 'agent_disabled',
                ]);

                return self::MODE_AGENT_SKIPPED;
            }

            $balance = Common::numAdd($this->amount($agent->balance), $payable, self::AMOUNT_SCALE);
            $agent->update(['balance' => $balance]);

            $generalAgentId = (int) $settlement->general_agent_id;
            TransactionLogAgent::create([
                'from_agent_id' => $generalAgentId,
                'to_agent_id' => $agent->id,
                'type' => TransactionLogAgentTypeEnum::GGR_PROFIT_PAYOUT->value,
                'amount' => $payable,
                // 表無 before/after 欄，照現行慣例（ThirdPartyController）塞「入帳後餘額」；
                // 總代餘額不因本次發放變動，僅為歸屬快照
                'from_limit_of_credit' => $this->generalBalance($generalAgentId),
                'from_used_credit' => 0,
                'to_limit_of_credit' => $balance,
                'to_used_credit' => 0,
                'operator' => TransactionLogAgent::OPERATOR_SYSTEM,
                'platform' => TransactionLogAgentPlatformEnum::SYSTEM->value,
                'remark' => sprintf('ggr_settlement:%d %s~%s', $settlement->id, $settlement->period_start, $settlement->period_end),
                'status' => TransactionLogAgentStatusEnum::SUCCESS->value,
            ]);

            $this->markPaid($settlement);

            return self::MODE_PAID;
        });
    }

    /**
     * 將發放的清單（dry-run 用，唯讀）；mode 與實跑同一套判定，不另寫規則。
     *
     * @return array<int, array{id: int, agent_username: string, amount: string, mode: string}>
     */
    public function pendingRows(?int $planId = null): array
    {
        return $this->pendingQuery($planId)
            ->with('agent:id,username,status')
            ->get(self::SETTLEMENT_COLUMNS)
            ->map(function (GgrSettlement $row): array {
                /** @var Agent|null $agent */
                $agent = $row->agent;

                return [
                    'id' => (int) $row->id,
                    'agent_username' => $agent?->username ?? '',
                    'amount' => $this->amount($row->payable_amount),
                    'mode' => $this->classify($row->payable_amount, $agent),
                ];
            })
            ->all();
    }

    /**
     * 全部 MODE_*（統計初始化與輸出列舉的單一來源）。
     *
     * @return array<int, string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_PAID, self::MODE_ZERO_PAID, self::MODE_ALREADY_PAID,
            self::MODE_MISSING, self::MODE_AGENT_SKIPPED, self::MODE_PLAN_SKIPPED,
        ];
    }

    /** 中文說明（Command 輸出用） */
    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_PAID => '入帳',
            self::MODE_ZERO_PAID => '0元標記',
            self::MODE_ALREADY_PAID => '已發放略過',
            self::MODE_MISSING => '結算列已刪除',
            self::MODE_AGENT_SKIPPED => '跳過(代理停用或不存在)',
            self::MODE_PLAN_SKIPPED => '跳過(方案停用)',
            default => $mode,
        };
    }

    /**
     * 待發放列查詢（dry-run 與實跑共用，避免兩份條件漂移）：
     * UNPAID＋方案啟用中；索引走 idx_ggr_settlement_payout_plan。
     *
     * @return Builder<GgrSettlement>
     */
    private function pendingQuery(?int $planId): Builder
    {
        return GgrSettlement::query()
            ->where('payout_status', GgrPayoutStatusEnum::UNPAID->value)
            ->when($planId !== null, fn ($q) => $q->where('plan_id', $planId))
            ->whereHas('plan', fn ($q) => $q->where('status', StatusEnum::ENABLE->value))
            ->orderBy('id');
    }

    /** dry-run 的模式判定：與 payoutOne 的分支同源（方案停用已由 pendingQuery 濾除） */
    private function classify(mixed $payableAmount, ?Agent $agent): string
    {
        if (Common::numCompare($this->amount($payableAmount), '0') <= 0) {
            return self::MODE_ZERO_PAID;
        }

        return $agent === null || (int) $agent->status !== StatusEnum::ENABLE->value
            ? self::MODE_AGENT_SKIPPED
            : self::MODE_PAID;
    }

    private function markPaid(GgrSettlement $settlement): void
    {
        $settlement->forceFill([
            'payout_status' => GgrPayoutStatusEnum::PAID->value,
            'paid_at' => now(),
        ])->save();
    }

    /** 總代餘額快照（不因發放變動，一輪只讀一次） */
    private function generalBalance(int $generalAgentId): string
    {
        return $this->generalBalances[$generalAgentId] ??= $this->amount(
            Agent::query()->whereKey($generalAgentId)->value('balance')
        );
    }

    /** DB 讀出的數值入境即正規化（CLAUDE.md 金流紅線：禁止直接 (string) 後運算） */
    private function amount(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), self::AMOUNT_SCALE);
    }
}
