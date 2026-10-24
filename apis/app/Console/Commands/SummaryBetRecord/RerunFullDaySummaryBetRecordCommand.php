<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 每日早上以對帳時區重跑前一整天注單彙總的派發器（target/tickets#4357）
 *
 * 對 config/summary_bet_record.php 設 `next_day_full_rerun => true` 的遊戲商，
 * 依其對帳時區 `reconcile_timezone`（未設 +8）取「該時區的昨天」整天重跑，
 * 🔴 **逐商序列執行於本命令進程內，禁止改成一商一個 queue job 併發**：本命令會跑彙總結算
 * （{@see SummaryBetRecordRunner::runSummarySettlement}），而結算的兩把鎖**不含 vendor**
 * ——team 鎖粒度是台北日（整天 24 格共用一把）、滾碼鎖粒度是 UTC 小時，且 `withLock` 的
 * waitSeconds=0（搶不到立刻拋）。併發時各商必然互撞：2026-10-02 vwb2c 的 145 / 253 各失敗
 * 14 次、雙雙 exit 1。該結算重算的是「那個小時的全部廠商」、與 vendor 無關，各商跑的本來
 * 就是同一份工，序列化無損失。單一進程逐商跑即天然序列，不需要任何鎖。
 *
 * 單商失敗只記錄並續跑下一商，全部跑完若有失敗則回非 0 exit code，
 * 由 {@see \App\Providers\ScheduleProvider} 發排程失敗告警；補跑走 Olympus 重跑注單彙總頁。
 * 作法同 Olympus「拉取注單」＋「重跑注單彙總」頁的整天模式：
 * - 轉帳錢包：先 `fetch_bet_record --direct --update` 同步拉回該日注單（窗口換算成 UTC，金額異動的既有列一併更新），
 *   再 `summary_bet_record`
 * - 單一錢包：注單已由回調即時記帳，只跑 `summary_bet_record`
 *
 * 排程於 UTC 00:25（UTC+8 08:25）觸發（routes/console.php），刻意排在 UTC 日界之後：
 * 「昨天」以各遊戲商對帳時區的當下時刻回推，永遠是該時區最近一個已結束的完整日，
 * 此時 +0 與 +8 時區算出的昨天是同一天 D（若排在 UTC 日界前，+0 只能算到 D-1）；-4 時區則為 D-1。
 *
 * @knowledge rules/summary-bet-record
 */
class RerunFullDaySummaryBetRecordCommand extends Command
{
    protected $signature = 'summary_bet_record:rerun-full-day';

    protected $description = '逐一以對帳時區重跑 next_day_full_rerun 遊戲商的前一整天注單彙總（轉帳錢包先拉注單）';

    public function handle(): int
    {
        $targets = self::rerunTargets(config('summary_bet_record', []));

        if ($targets === []) {
            $this->info('沒有設定 next_day_full_rerun 的遊戲商');

            return self::SUCCESS;
        }

        Context::add(ContextKeyEnum::FLOW->value, 'summary_bet_record_rerun_full_day');

        $singleWallet = Vendor::query()->whereKey(array_keys($targets))->pluck('is_single_wallet', 'id');
        $now = CarbonImmutable::now('UTC');
        $failed = [];

        foreach ($targets as $vendorId => $config) {
            $steps = self::steps($vendorId, $config, (int) $singleWallet->get($vendorId) === 1, $now);

            if (!$this->runVendor($vendorId, $steps)) {
                $failed[] = $vendorId;
            }
        }

        if ($failed !== []) {
            $this->error(sprintf('重跑失敗的遊戲商：%s，請至 Olympus 重跑注單彙總頁補跑', implode(', ', $failed)));

            return self::FAILURE;
        }

        $this->info(sprintf('共重跑 %d 個遊戲商的前一整天彙總', count($targets)));

        return self::SUCCESS;
    }

    /**
     * 依序執行單一遊戲商的步驟，前一步失敗即停並回報失敗（不中斷其他遊戲商）。
     *
     * @param  list<array{0: string, 1: array<string, string|bool>}>  $steps
     * @return bool 全部步驟都成功才為 true
     */
    private function runVendor(int $vendorId, array $steps): bool
    {
        $this->line(sprintf('vendor %d：%s', $vendorId, implode(' → ', array_map(
            fn (array $step): string => $step[0] . ' ' . json_encode($step[1], JSON_UNESCAPED_SLASHES),
            $steps,
        ))));

        foreach ($steps as [$command, $params]) {
            try {
                $output = new BufferedOutput;
                $exitCode = Artisan::call($command, $params, $output);

                Log::info('前一整天重跑步驟結束', [
                    'vendor_id' => $vendorId,
                    'command' => $command,
                    'params' => $params,
                    'exit_code' => $exitCode,
                    'output' => Str::limit($output->fetch(), 20000),
                ]);
            } catch (Throwable $e) {
                Log::error('前一整天重跑步驟拋出例外', [
                    'vendor_id' => $vendorId,
                    'command' => $command,
                    'params' => $params,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
                $this->error(sprintf('  vendor %d 的 %s 拋出例外：%s，略過後續步驟', $vendorId, $command, $e->getMessage()));

                return false;
            }

            if ($exitCode !== 0) {
                $this->error(sprintf('  vendor %d 的 %s 失敗（exit code %d），略過後續步驟', $vendorId, $command, $exitCode));

                return false;
            }
        }

        return true;
    }

    /**
     * 需每日重跑前一整天的遊戲商設定（`next_day_full_rerun => true`）。
     *
     * @param  array<int, array{next_day_full_rerun?: bool, reconcile_timezone?: int}>  $configs
     * @return array<int, array{next_day_full_rerun?: bool, reconcile_timezone?: int}>
     */
    public static function rerunTargets(array $configs): array
    {
        return array_filter($configs, fn (array $config): bool => $config['next_day_full_rerun'] ?? false);
    }

    /**
     * 單一遊戲商的重跑步驟（job 內依序執行、前一步失敗即停）。
     *
     * @param  array{reconcile_timezone?: int}  $config
     * @return list<array{0: string, 1: array<string, string|bool>}> [artisan 指令, 參數]
     */
    public static function steps(int $vendorId, array $config, bool $isSingleWallet, CarbonImmutable $nowUtc): array
    {
        $timezone = SummaryBetRecordRunner::timezoneOption(SummaryBetRecordRunner::reconcileTimezone($config));
        $day = $nowUtc->setTimezone($timezone)->subDay()->startOfDay();
        $steps = [];

        if (!$isSingleWallet) {
            $steps[] = ['fetch_bet_record', [
                '--vendor' => (string) $vendorId,
                '--start' => $day->setTimezone('UTC')->format('Y-m-d@H'),
                '--end' => $day->addDay()->setTimezone('UTC')->format('Y-m-d@H'),
                '--direct' => true,
                '--update' => true,
            ]];
        }

        $steps[] = ['summary_bet_record', [
            '--vendor' => (string) $vendorId,
            '--date' => $day->format('Y-m-d'),
            '--timezone' => $timezone,
        ]];

        return $steps;
    }
}
