<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\General\ContextKeyEnum;
use App\Services\Agent\GgrSettlement\PayoutService;
use App\Services\BotNotifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * GGR 發放 job（target/tickets#4121 批次七）：把 ggr_settlements 的 UNPAID 列
 * 入帳到代理餘額（PayoutService，單列一交易、狀態守門冪等）。
 *
 * 全域單飛（WithoutOverlapping 'ggr-payout'）：發放不需並行，序列化避免
 * 同代理多列併發搶鎖。單列失敗不中斷其他列，收尾統一拋出觸發 retry——
 * 已 PAID 的列被守門跳過，重試只補殘餘；maxExceptions 耗盡落 failed_jobs
 * 並 BotNotify，殘餘 UNPAID 由隔日排程 catch-up。
 *
 * 不接 QueueJobRun：發放不是 bucket 型工作，冪等單位＝結算列自身的
 * payout_status，完成標記反而與「缺列＝缺資料」契約不合。
 */
class GgrPayoutJob implements ShouldQueue
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
        /** 只發指定方案；null＝全部啟用方案的 UNPAID 列 */
        public ?int $planId = null,
    ) {
        // 走長時 job 專用連線（retry_after=3660 > timeout），與結算 job 同棲
        $this->onConnection('redis-long-running');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ggr-payout'))
                ->releaseAfter(60)
                ->expireAfter(660),
        ];
    }

    public function handle(PayoutService $service): void
    {
        Context::add(ContextKeyEnum::FLOW->value, 'ggr_payout');

        $stats = $service->payoutPending($this->planId);

        Log::info('GGR 發放完成', [
            'plan_id' => $this->planId,
            ...$stats['counts'],
            'failed' => $stats['failed'],
        ]);

        if ($stats['failed'] > 0) {
            throw new RuntimeException('GGR 發放部分失敗: ' . implode('; ', $stats['failures']));
        }
    }

    /** maxExceptions 耗盡（或 timeout 等框架層失敗）：殘餘 UNPAID 由隔日排程 catch-up 接手 */
    public function failed(?Throwable $exception): void
    {
        Log::error('GgrPayoutJob 最終失敗', [
            'plan_id' => $this->planId,
            'error' => $exception?->getMessage(),
        ]);

        app(BotNotifyService::class)->send(
            '[GgrPayoutJob error] GGR 發放重試耗盡最終失敗，殘餘未發放列由隔日排程 catch-up 接手: '
            . ($exception?->getMessage() ?? 'unknown')
        );
    }
}
