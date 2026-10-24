<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Helpers\Common;
use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlowOrderType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 依審計 log 還原「被 ACF 下架連動關閉」的 acfot。
 *
 * 【分類】Maintenance — 可複用維運工具，與 {@see \App\Observers\AgentCashFlowObserver} 成對。
 *
 * ## 發生原因
 * ACF 下架（status 1→0）時，Observer 會連動關閉旗下 active acfot 以免留下孤兒。
 * ACF 重新啟用時**刻意不自動連動開回**——連動開回會一併打開營運刻意個別停用的渠道，
 * 因此還原設計成顯式操作。
 *
 * ## 修正目的
 * 把 `acf_cascade_deactivate_logs` 中尚未還原（`restored_by_log_id IS NULL`）的
 * `cascade_deactivate` 事件，逐筆把 acfot.status 寫回當初記錄的 `from_status`。
 * **只還原 log 記錄過的列**，營運自行停用的 acfot 從未進 log，永遠不會被誤開。
 *
 * ## 觸發時機
 * ACF 重新啟用之後，確認要一併恢復當初被連動關閉的渠道時。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 還原後回填原 log 的 `restored_by_log_id` 形成閉環，
 * 重跑不會重複處理（即使該 acfot 事後又被人工關掉，也不會再被開回）。
 * 保護層：`--dry-run` 預覽、還原前列清單並 `confirm()`、環境確認、寫入包 `DB::transaction`。
 *
 * ## 關聯
 * - 連動下架來源：{@see \App\Observers\AgentCashFlowObserver}
 * - 其他成因的孤兒（L1/L2）：`check:cashflow-orphans` / `fix:cashflow-orphans`
 *
 * 用法：
 *   php artisan fix:acf-cascade-restore --dry-run
 *   php artisan fix:acf-cascade-restore --acf=394
 *   php artisan fix:acf-cascade-restore --force --no-interaction
 */
class AcfCascadeRestore extends Command
{
    protected $signature = 'fix:acf-cascade-restore
                            {--acf= : 只還原指定 agent_cash_flows.id（省略＝全部待還原）}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 略過環境與還原確認}';

    protected $description = '依審計 log 還原被 ACF 下架連動關閉的 acfot（status 寫回 from_status）';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if ($isDryRun) {
            $this->warn('⚠️  乾跑模式：執行後不會寫入資料庫');
            $this->newLine();
        }

        if (!$isDryRun && !$isForce && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $pending = $this->findPendingLogs();

        if ($pending->isEmpty()) {
            $this->info('✅ 沒有待還原的連動下架紀錄');

            return self::SUCCESS;
        }

        $this->renderPreview($pending, $isDryRun);

        if ($isDryRun) {
            $this->newLine();
            $this->comment('正式執行請移除 --dry-run');

            return self::SUCCESS;
        }

        if (!$isForce && !$this->confirm(sprintf('確定還原 %d 筆 acfot？', $pending->count()), false)) {
            $this->warn('已取消');

            return self::FAILURE;
        }

        return $this->executeRestore($pending);
    }

    private function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');
        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));

        return $this->confirm('確定要在此環境執行？', false);
    }

    /**
     * 取出待還原的連動下架事件（可依 --acf 收斂範圍）
     *
     * @return Collection<int, AcfCascadeDeactivateLog>
     */
    private function findPendingLogs(): Collection
    {
        $acfId = $this->option('acf');

        return AcfCascadeDeactivateLog::query()
            ->pendingRestore()
            ->when($acfId !== null, fn ($q) => $q->where('agent_cash_flow_id', (int) $acfId))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, AcfCascadeDeactivateLog>  $pending
     */
    private function renderPreview(Collection $pending, bool $isDryRun): void
    {
        $this->info($isDryRun ? '🔍 預覽（DRY-RUN）：' : '🔍 預覽：將把以下 acfot 的 status 寫回 from_status');
        $this->table(
            ['log_id', 'acf_id', 'acfot_id', 'order_type_id', 'from_status', '下架時間'],
            $pending->map(fn (AcfCascadeDeactivateLog $log): array => [
                $log->id,
                $log->agent_cash_flow_id,
                $log->agent_cash_flow_order_type_id,
                $log->order_type_id,
                $log->from_status,
                $log->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        $this->newLine();
        $this->line(sprintf(
            '影響：%d 筆 acfot（涵蓋 %d 個 AgentCashFlow）',
            $pending->count(),
            $pending->unique('agent_cash_flow_id')->count(),
        ));
    }

    /**
     * @param  Collection<int, AcfCascadeDeactivateLog>  $pending
     */
    private function executeRestore(Collection $pending): int
    {
        try {
            $restored = DB::transaction(function () use ($pending): int {
                $now = now();
                $connection = (string) config('database.default');
                $count = 0;

                foreach ($pending as $log) {
                    $acfot = AgentCashFlowOrderType::query()
                        ->where('id', $log->agent_cash_flow_order_type_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$acfot) {
                        $this->warn(sprintf('  ⚠️  acfot %d 已不存在，略過（log %d）', $log->agent_cash_flow_order_type_id, $log->id));

                        continue;
                    }

                    $acfot->update(['status' => $log->from_status]);

                    $restoreLog = AcfCascadeDeactivateLog::create([
                        'connection' => $connection,
                        'agent_cash_flow_id' => $log->agent_cash_flow_id,
                        'agent_cash_flow_order_type_id' => $log->agent_cash_flow_order_type_id,
                        'order_type_id' => $log->order_type_id,
                        'action' => AcfCascadeDeactivateLog::ACTION_RESTORE,
                        'from_status' => (int) $acfot->getOriginal('status'),
                        'source' => AcfCascadeDeactivateLog::SOURCE_COMMAND,
                        'detail' => sprintf('依 log %d 還原 status→%d', $log->id, $log->from_status),
                        'created_at' => $now,
                    ]);

                    // 回填閉環：已還原的事件不再進 pendingRestore
                    $log->update(['restored_by_log_id' => $restoreLog->id]);
                    $count++;
                }

                return $count;
            });

            Log::info('acf-cascade: acfot restored', [
                'restored_count' => $restored,
                'log_ids' => $pending->pluck('id')->all(),
                'executed_by' => 'artisan:fix:acf-cascade-restore',
            ]);

            $this->newLine();
            $this->info(sprintf('✅ 已還原 %d 筆 acfot', $restored));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('acf-cascade: restore failed', [
                'log_ids' => $pending->pluck('id')->all(),
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $this->error(sprintf('❌ 執行失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
