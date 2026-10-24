<?php

declare(strict_types=1);

namespace App\Console\Commands\Ggr;

use App\Console\Commands\Ggr\Concerns\ResolvesGgrPlanOption;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\StatusEnum;
use App\Helpers\Common;
use App\Jobs\GgrSettleJob;
use App\Models\GgrPlan;
use App\Services\Agent\GgrSettlement\PeriodResolver;
use App\Services\Agent\GgrSettlement\SettlementRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GGR 結算觸發器（target/tickets#4121 批次六）。
 *
 * 排程每日 GMT+8 15:00 觸發：逐啟用中方案 dispatch GgrSettleJob（Horizon
 * long-running 佇列），job 內 catch-up 該方案所有「已完整結束且未結算」
 * 的期間——任何一輪觸發缺失（節點汰換、dispatch 失敗）都由下一日自動治癒。
 *
 * --direct 同步執行不入 queue（後台 Olympus 補跑用，比照 FetchBetRecord）：
 * 任一方案結算失敗即以非 0 作收，admin_operation_logs 才不會把失敗標成成功。
 *
 * --dry-run 同步試算不落庫（不寫 ggr_settlements、不記完成標記、不 promote
 * 週期切換），供驗收比對數字；多期連續 dry-run 的 carry 鏈以 DB 現值為準
 * （前期未實際落庫，後期的 carry_in 不會反映前期試算結果）。
 */
class GgrSettleCommand extends Command
{
    use ResolvesGgrPlanOption;

    protected $signature = 'ggr:settle
        {--plan= : 只跑指定方案 id}
        {--date= : 結算截至該業務日(含)的期間，預設且最晚為昨日(GMT+8)}
        {--direct : 同步執行不入 queue（後台補跑用，失敗回非 0）}
        {--dry-run : 同步試算不落庫}';

    protected $description = 'GGR 推廣代理結算：對啟用中方案產生 ggr_settlements（4121）';

    public function handle(PeriodResolver $resolver, SettlementRunner $runner): int
    {
        $planId = $this->resolveGgrPlanOption();

        if ($planId === false) {
            return self::FAILURE;
        }

        // 結算截至日（含）；「今天」錨＝截至日 +1（期末早於錨的期間才結算），換算集中在 Job
        $maxThrough = now()->toBackendTime()->subDay()->startOfDay();
        $through = $this->option('date') !== null
            ? Carbon::parse($this->option('date'))->startOfDay()
            : $maxThrough;

        // 截至日上限＝昨日。PeriodResolver 靠「期末須早於錨日」擋下今天所在的期間，
        // 但錨＝截至日 +1，--date 指定今天（或未來）會把錨推到明天以後，該保護就被架空：
        // 引擎會拿當下不完整的資料落庫，並連同完成標記與 settled_through 一起推進，
        // 當日 15:00 排程之後不會再重算該期，錯誤數字就此鎖死。dry-run 同樣擋——
        // 今天的資料本來就不完整，預覽沒有意義，一致的規則也不會讓人誤以為實跑可行。
        // 兩個 Carbon 時區不同時比大小是比絕對時點（UTC 的 00:00 會被判成晚於
        // +08:00 的同日 00:00），故以日期字串比對。
        if ($through->toDateString() > $maxThrough->toDateString()) {
            $this->error(sprintf(
                '--date 不可晚於 %s：今天（%s，GMT+8）所在的期間尚未結束，'
                . '結算會寫進不完整的資料並推進游標，之後排程不會再重算該期。',
                $maxThrough->toDateString(),
                now()->toBackendTime()->toDateString(),
            ));

            return self::FAILURE;
        }

        $anchor = GgrSettleJob::anchorFor($through);

        $plans = GgrPlan::query()
            ->where('status', StatusEnum::ENABLE->value)
            ->when($planId !== null, fn ($q) => $q->where('id', $planId))
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            $this->info('無啟用中的 GGR 方案。');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($plans as $plan) {
            $periods = $resolver->pendingPeriods($plan, $anchor);

            if ($this->option('dry-run')) {
                $this->dryRun($runner, $plan, $periods);

                continue;
            }

            $this->line($this->describePeriods($plan, $periods));

            if ($this->option('direct')) {
                if (!$this->settleDirect($resolver, $runner, $plan, $periods, $anchor)) {
                    $failed++;
                }

                continue;
            }

            // 無期可結仍派發：job 內 promoteIfDue 讓已到生效日的週期切換落地
            GgrSettleJob::dispatch($plan->id, $through->toDateString());
            $this->line('  已派發 GgrSettleJob');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 同步逐期結算＋promote；失敗回 false（其他方案繼續跑，整體以失敗作收）。
     *
     * @param  array<int, array{start: Carbon, end: Carbon, cycle: \App\Enums\Agent\GgrSettlementCycleEnum}>  $periods
     */
    private function settleDirect(
        PeriodResolver $resolver,
        SettlementRunner $runner,
        GgrPlan $plan,
        array $periods,
        Carbon $anchor,
    ): bool {
        try {
            foreach ($periods as $period) {
                $stats = $runner->runPlanPeriod(
                    plan: $plan,
                    periodStart: $period['start'],
                    periodEnd: $period['end'],
                    cycle: $period['cycle'],
                    source: QueueJobRunSourceEnum::MANUAL,
                );

                $this->line(sprintf(
                    '  %s ～ %s：%s，結算 %d 位代理、跳過 %d 位',
                    $period['start']->toDateString(),
                    $period['end']->toDateString(),
                    $stats['mode'],
                    $stats['settled'],
                    $stats['skipped_agents'],
                ));
            }

            $resolver->promoteIfDue($plan, $anchor);

            return true;
        } catch (Throwable $e) {
            Log::error('ggr:settle --direct 方案結算失敗', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->error(sprintf('  方案 %d (%s) 結算失敗：%s', $plan->id, $plan->name, $e->getMessage()));

            return false;
        }
    }

    /**
     * @param  array<int, array{start: Carbon, end: Carbon, cycle: \App\Enums\Agent\GgrSettlementCycleEnum}>  $periods
     */
    private function describePeriods(GgrPlan $plan, array $periods): string
    {
        if ($periods === []) {
            return sprintf('方案 %d (%s)：無待結算期間', $plan->id, $plan->name);
        }

        return sprintf(
            '方案 %d (%s)：%d 期待結算（%s ～ %s）',
            $plan->id,
            $plan->name,
            count($periods),
            $periods[0]['start']->toDateString(),
            $periods[count($periods) - 1]['end']->toDateString(),
        );
    }

    /**
     * @param  array<int, array{start: Carbon, end: Carbon, cycle: \App\Enums\Agent\GgrSettlementCycleEnum}>  $periods
     */
    private function dryRun(SettlementRunner $runner, GgrPlan $plan, array $periods): void
    {
        if ($periods === []) {
            $this->line(sprintf('方案 %d (%s)：無待結算期間', $plan->id, $plan->name));

            return;
        }

        foreach ($periods as $period) {
            $stats = $runner->runPlanPeriod(
                plan: $plan,
                periodStart: $period['start'],
                periodEnd: $period['end'],
                cycle: $period['cycle'],
                source: QueueJobRunSourceEnum::MANUAL,
                dryRun: true,
                skipIfCompleted: false,
            );

            $this->line(sprintf(
                '[dry-run] 方案 %d (%s) %s ～ %s：結算 %d 位代理、跳過 %d 位',
                $plan->id,
                $plan->name,
                $period['start']->toDateString(),
                $period['end']->toDateString(),
                $stats['settled'],
                $stats['skipped_agents'],
            ));

            foreach ($stats['agents'] as $row) {
                $this->line(sprintf(
                    '  - %s：加權總和 %s／判斷值 %s／條件%% %s／最終 %s／實發 %s（結轉入 %s → 出 %s）',
                    $row['agent_username'],
                    $row['calc_snapshot']['weighted_total'],
                    $row['calc_snapshot']['decision_value'],
                    $row['condition_rate'],
                    $row['final_amount'],
                    $row['payable_amount'],
                    $row['carry_in_amount'],
                    $row['carry_out_amount'],
                ));
            }
        }
    }
}
