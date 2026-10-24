<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\General\ContextKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\StatusEnum;
use App\Models\GgrPlan;
use App\Services\Agent\GgrSettlement\PeriodResolver;
use App\Services\Agent\GgrSettlement\SettlementRunner;
use App\Services\BotNotifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 單一 GGR 方案的結算 job（target/tickets#4121 批次六）。
 *
 * 一顆 job＝一個方案：catch-up 該方案所有「已完整結束且未結算」的期間，
 * 依時序逐期結算（後期的 carry 鏈依賴前期落庫值，不可並行、不可跳期），
 * 全數完成後 promote 待生效的週期切換。單期失敗即中止（拋出觸發 retry；
 * 已完成的期有 QueueJobRun 標記，重試只補殘餘）。
 *
 * 防護參數照 SummarizeBetRecordHour：WithoutOverlapping per plan
 * （與 CLI dry-run 的 Runner 鎖再疊一層 plan×period cache lock）、
 * tries=0（release 重入不算失敗）、maxExceptions=3、timeout=600。
 */
class GgrSettleJob implements ShouldQueue
{
    use Queueable;

    /** attempts 不設上限：WithoutOverlapping 取不到鎖會 release 重入，不可計為失敗 */
    public int $tries = 0;

    /** 真實例外重試上限：耗盡落 failed_jobs 並 BotNotify */
    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [120, 660];

    public int $timeout = 600;

    public function __construct(
        public int $planId,
        /** 結算截至業務日（含，Y-m-d，GMT+8）：期末 ≤ 此日的期間才結算——與 --date、後台頁同語意 */
        public string $throughBusinessDate,
    ) {
        // 走長時 job 專用連線（retry_after=3660 > timeout）：整批 catch-up 可能跑
        // 超過預設連線的 retry_after=90 秒被重複派發
        $this->onConnection('redis-long-running');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ggr-settle-plan:' . $this->planId))
                ->releaseAfter(60)
                ->expireAfter(660),
        ];
    }

    /**
     * 「今天」業務日錨＝結算截至日 +1：PeriodResolver 只結「期末早於錨」的期間。
     * 截至日 → 錨 的換算只在這裡，Command／後台頁／其他呼叫端一律傳截至日。
     */
    public static function anchorFor(Carbon $throughBusinessDate): Carbon
    {
        return $throughBusinessDate->copy()->addDay()->startOfDay();
    }

    public function handle(PeriodResolver $resolver, SettlementRunner $runner): void
    {
        Context::add(ContextKeyEnum::FLOW->value, 'ggr_settlement');

        $plan = GgrPlan::query()->find($this->planId);

        if ($plan === null || (int) $plan->status !== StatusEnum::ENABLE->value) {
            Log::info('GgrSettleJob: 方案不存在或已停用，跳過', ['plan_id' => $this->planId]);

            return;
        }

        $anchor = self::anchorFor(Carbon::parse($this->throughBusinessDate));

        foreach ($resolver->pendingPeriods($plan, $anchor) as $period) {
            $runner->runPlanPeriod(
                plan: $plan,
                periodStart: $period['start'],
                periodEnd: $period['end'],
                cycle: $period['cycle'],
                source: QueueJobRunSourceEnum::SCHEDULE,
            );
        }

        // 全部期間結清後才落地週期切換（「當期照原週期跑完」）
        $resolver->promoteIfDue($plan, $anchor);
    }

    /** maxExceptions 耗盡（或 timeout 等框架層失敗）：缺漏期間由下一日排程 catch-up 接手 */
    public function failed(?Throwable $exception): void
    {
        Log::error('GgrSettleJob 最終失敗', [
            'plan_id' => $this->planId,
            'through' => $this->throughBusinessDate,
            'error' => $exception?->getMessage(),
        ]);

        app(BotNotifyService::class)->send(
            "[GgrSettleJob error] 方案 {$this->planId} 結算重試耗盡最終失敗，缺漏期間由下一日排程 catch-up 接手: "
            . ($exception?->getMessage() ?? 'unknown')
        );
    }
}
