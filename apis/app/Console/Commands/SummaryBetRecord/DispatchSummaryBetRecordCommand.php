<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use App\Jobs\SummarizeBetRecordHour;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 整點排程觸發器：只派發帶明確 hour bucket 的 SBR 彙總 job（target/tickets#4257）
 *
 * 排程本體不再同步執行彙總——2026-08-28 節點汰換使 `schedule:run` 遲到 4 分鐘、
 * 整點命令永久跳過且全程靜默。改為本命令秒級完成 dispatch；即使本命令的觸發
 * 也被跳過（或 Redis 派發失敗、由 command_logs 留下失敗紀錄），缺漏的 bucket
 * 由下一個整點 job 的回看視窗（SummarizeBetRecordHour::LOOKBACK_HOURS）自動補齊。
 *
 * 手動補跑指定日期／小時請用 `summary_bet_record`（同步執行、支援 --dry-run）。
 *
 * @knowledge rules/summary-bet-record
 */
class DispatchSummaryBetRecordCommand extends Command
{
    protected $signature = 'summary_bet_record:dispatch';

    protected $description = '派發前一小時 SBR 彙總 job（Horizon long-running；job 內建回看補洞）';

    public function handle(): int
    {
        $bucket = date('Y-m-d H:00:00', strtotime('-1 hour'));

        SummarizeBetRecordHour::dispatch($bucket);

        Log::info('summary_bet_record:dispatch 已派發彙總 job', ['bucket' => $bucket]);
        $this->info(sprintf('已派發 SummarizeBetRecordHour bucket=%s（UTC）', $bucket));

        return 0;
    }
}
