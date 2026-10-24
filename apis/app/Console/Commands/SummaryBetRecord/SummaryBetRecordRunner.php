<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\SP\SP_summary_bet_record_team;
use App\Console\Commands\SummaryBetRecord\SP\SP_summary_rolling_chip_record;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Helpers\Traits\DistributedLock;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\Game;
use App\Models\QueueJobRun;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * SBR 小時彙總執行核心（自 SummaryBetRecordCommand 抽出，CLI 與 queue job 共用）
 *
 * 職責：
 * - 載入所有廠商共用資料（排除遊戲、V-Jackpot 抽水、廠商盤費）
 * - 單一 vendor × hour bucket 的彙總執行（named lock ＋ DB transaction）
 * - SP 彙總結算（全民代理 team ＋ 滾碼結算），含各自粒度的鎖
 *
 * 編排（時間範圍展開、關帳彙整時點、逐廠商迭代與失敗彙整）仍由呼叫端負責：
 * CLI／後台補跑走 SummaryBetRecordCommand、整點排程走 SummarizeBetRecordHour job
 * （target/tickets#4257 排程 Queue 化）。
 *
 * 設定 output 時（CLI）照常輸出進度；未設定時（queue job）僅寫 Log。
 *
 * @knowledge rules/summary-bet-record
 */
class SummaryBetRecordRunner
{
    use DistributedLock;

    /**
     * 廠商執行鎖 TTL（秒）：粒度 = vendor × 小時。
     *
     * 必須大於 queue job timeout（600，見 SummarizeBetRecordHour），否則單一廠商
     * 執行中鎖先到期，job retry／CLI 手動補跑可併入同一 (vendor, 小時)——
     * 調帳模式是「比對現值寫差額」，併發計算會重複寫入 correction。
     * worker 被硬砍時鎖最多殘留至 TTL 到期，由 job 的 backoff 錯開重試。
     */
    private const int VENDOR_LOCK_TTL = 660;

    /**
     * 結算彙總（team／滾碼）鎖 TTL（秒）。
     *
     * 與 VENDOR_LOCK_TTL 同理必須大於 queue job timeout（600）：結算也在 job 內執行
     * （trigger bucket 保底結算），team 彙總（SP_summary_bet_record_team）逐層重建可能久跑，鎖中途過期
     * 會讓 CLI 手動補跑併入同一台北日的刪除重建足跡，交錯產生重複列
     * （summary_bet_records_team 無唯一鍵，DB 層無兜底）。
     */
    private const int SETTLEMENT_LOCK_TTL = 660;

    /** 對帳時區預設值（與 UTC 的時差小時數）：對帳 SQL 未列入清單的遊戲商一律 +8 */
    public const int DEFAULT_RECONCILE_TIMEZONE = 8;

    /**
     * 回看補洞判缺時不算數的完成標記來源：手動補跑可能在該小時結束前或遲到注單
     * 補進前執行，若算數，整點排程會跳過該格、停在手動當下的結果
     *
     * @var array<int, QueueJobRunSourceEnum>
     */
    public const array BACKFILL_IGNORED_SOURCES = [QueueJobRunSourceEnum::MANUAL];

    /** @var array<int> valid_bet_rate = 0 的遊戲 ID 清單 */
    private array $excludedGameIds = [];

    /** @var array<int, array<string|int, mixed>> [agent_group_id][vendor_id|'DEFAULT'] => rate */
    private array $vJackpotRates = [];

    /** @var array<int, array<int, mixed>> [customized_setting_group_id][vendor_id] => cost */
    private array $gameFeeRates = [];

    /** CLI 進度輸出；null（queue job）時僅寫 Log */
    private ?OutputStyle $output = null;

    public function __construct(
        private readonly SP_summary_bet_record_team $teamWriter = new SP_summary_bet_record_team,
        private readonly SP_summary_rolling_chip_record $rollingChipWriter = new SP_summary_rolling_chip_record,
    ) {}

    public function setOutput(?OutputStyle $output): static
    {
        $this->output = $output;

        return $this;
    }

    /**
     * 過濾出隨排程執行的廠商設定（`schedule_enabled`，未設定視為啟用）。
     *
     * 排程觸發（SummarizeBetRecordHour）、CLI 全廠商執行與缺口稽核共用此規則——
     * 條件散落多處會分岔出「CLI 跑了、job 沒跑」的難查落差。
     *
     * @param  array<int, array{third_party_code: string, time_field: string, schedule_enabled?: bool}>  $configs
     * @return array<int, array{third_party_code: string, time_field: string, schedule_enabled?: bool}>
     */
    public static function scheduledConfigs(array $configs): array
    {
        return array_filter($configs, fn (array $config): bool => $config['schedule_enabled'] ?? true);
    }

    /**
     * 遊戲商的對帳時區（config `reconcile_timezone`，未設視為 +8；target/tickets#4357）。
     *
     * @param  array{reconcile_timezone?: int}  $config
     */
    public static function reconcileTimezone(array $config): int
    {
        return (int) ($config['reconcile_timezone'] ?? self::DEFAULT_RECONCILE_TIMEZONE);
    }

    /**
     * 時差小時數 → `summary_bet_record --timezone` 與 Olympus 時區選項共用的鍵（0 → 'UTC'，其餘 '+08:00' 形式）。
     */
    public static function timezoneOption(int $offsetHours): string
    {
        return $offsetHours === 0 ? 'UTC' : sprintf('%+03d:00', $offsetHours);
    }

    /**
     * 載入所有廠商共用資料；已載入過則跳過（允許同一實例重複執行）。
     */
    public function prepareSharedData(): void
    {
        $this->loadExcludeGames();
        $this->loadVJackpotRate();
        $this->loadGameFeeRate();
    }

    /**
     * 執行單一廠商單一 hour bucket 的彙總作業。
     *
     * - 以 named lock 阻擋同廠商「同一小時」並行執行（鎖粒度：vendor × 小時）
     *   不同小時可並發（例：排程跑前一小時與手動補昨天互不阻擋），同一 (vendor, 小時) 仍互斥以防 SBR 重寫競態
     * - 包裹 DB Transaction，失敗時 rollback 並上拋；告警責任在兩端——Service 層
     *   DB 例外由 reportError 即時通知（帶 vendor／bucket），queue 重試耗盡由
     *   job failed() 彙總通知，此處不再發（避免重複告警洗版）
     * - 成功時於同一交易寫入完成標記（queue_job_runs，job_key=summary_bet_record），
     *   供回看補洞判定；dry-run 不留標記
     * - `$skipIfCompleted = true`（queue 補洞用）：取鎖後發現已有完成標記即跳過重算
     *   （堵「補洞 job 查缺與取鎖之間，他人已補完」的窗口）；手動補跑的標記不算數
     *   （{@see self::BACKFILL_IGNORED_SOURCES}）；CLI 手動補跑保持 false 照常重算
     *
     * @param  array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>}  $config
     * @return array{mode: string, written: int, reversed: int}
     *
     * @throws Throwable 取鎖失敗（ApiException HTTP_LOCKED）或彙總執行失敗
     */
    public function runVendorBucket(
        int $vendorId,
        array $config,
        string $start,
        string $end,
        bool $dryRun = false,
        QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::MANUAL,
        bool $skipIfCompleted = false,
    ): array {
        $lockKey = $this->vendorLockKey($vendorId, $start);

        $this->output?->newLine();
        $this->line(sprintf('[VendorId %d]', $vendorId), 'info');
        $this->line('  遊戲商代碼：' . $config['third_party_code']);
        $this->line('  時間欄位：' . $config['time_field']);
        Log::info(
            sprintf('summary_bet_record start [%d:%s] %s~%s lock=%s', $vendorId, $config['third_party_code'], $start, $end, $lockKey),
            [
                'vendor_id' => $vendorId,
                'third_party_code' => $config['third_party_code'],
                'time_field' => $config['time_field'],
                'start' => $start,
                'end' => $end,
                'lock_key' => $lockKey,
            ]
        );

        $stats = ['mode' => 'none', 'written' => 0, 'reversed' => 0];
        $startedAt = microtime(true);

        $this->withLock(
            key: $lockKey,
            ttl: self::VENDOR_LOCK_TTL,
            callback: function () use ($start, $end, $vendorId, $config, $dryRun, $source, $skipIfCompleted, &$stats): void {
                if ($skipIfCompleted && QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, $vendorId, $start, self::BACKFILL_IGNORED_SOURCES)) {
                    $stats = ['mode' => 'skipped', 'written' => 0, 'reversed' => 0];

                    return;
                }

                $serviceClass = $config['service_class'] ?? SummaryBetRecordService::class;
                $service = new $serviceClass($this->serviceOutput());
                $service->setVendorId($vendorId);
                $service->setTimeField($config['time_field']);
                $service->setExcludedGameIds($this->excludedGameIds);
                $service->setVJackpotRates($this->vJackpotRates);
                $service->setGameFeeRates($this->gameFeeRates);

                DB::beginTransaction();
                try {
                    $service->process($start, $end);
                    $stats = $service->getLastStats();

                    if ($dryRun) {
                        DB::rollBack();
                    } else {
                        // 完成標記與 SBR 寫入同交易提交：rollback 時一併消失，
                        // 不會出現「有標記無資料」讓回看補洞漏補
                        QueueJobRun::markCompleted(
                            jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD,
                            workKey: $vendorId,
                            bucketTime: $start,
                            source: $source,
                        );
                        DB::commit();
                    }
                } catch (Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );

        $durationSec = round(microtime(true) - $startedAt, 3);
        Log::info(
            sprintf(
                'summary_bet_record end [%d:%s] %s~%s lock=%s mode=%s written=%d reversed=%d%s [%ss]',
                $vendorId,
                $config['third_party_code'],
                $start,
                $end,
                $lockKey,
                $stats['mode'],
                $stats['written'],
                $stats['reversed'],
                $dryRun ? ' (dry-run)' : '',
                $durationSec
            ),
            [
                'vendor_id' => $vendorId,
                'third_party_code' => $config['third_party_code'],
                'start' => $start,
                'end' => $end,
                'lock_key' => $lockKey,
                'mode' => $stats['mode'],
                'written' => $stats['written'],
                'reversed' => $stats['reversed'],
                'dry_run' => $dryRun,
                'duration_sec' => $durationSec,
            ]
        );

        return $stats;
    }

    /**
     * 以指定 summary_time 執行全民代理彙總與滾碼結算
     * （原 SP_summary_bet_record_team / SP_summary_rolling_chip_record，
     * 已忠實移植為 SP/ 目錄下的同名 PHP 類別，target/tickets#4257）。
     *
     * 未關帳範圍傳入該小時 start；已關帳範圍由呼叫端於所有範圍跑完後傳入關帳時間、只呼叫一次。
     */
    public function runSummarySettlement(string $summaryTime): void
    {
        $elapsed = microtime(true);
        $this->output?->newLine();
        $this->line('[彙總結算]', 'info');

        $this->runTeamSummary($summaryTime);
        $this->runRollingChipSummary($summaryTime);

        $elapsedSec = round(microtime(true) - $elapsed, 3);
        Log::info(
            sprintf('彙總結算完成 [%s] [%ss]', $summaryTime, $elapsedSec),
            ['start' => $summaryTime, 'elapsed_sec' => $elapsedSec]
        );

        if ($elapsedSec > 10) {
            $this->line(sprintf('  結算完成（%s s）⚠ 耗時過長', $elapsedSec), 'comment');
        } else {
            $this->line(sprintf('  結算完成（%s s）', $elapsedSec));
        }
    }

    /**
     * 載入排除 V-Jackpot 計算的遊戲 ID 清單（valid_bet_rate = 0）。
     */
    private function loadExcludeGames(): void
    {
        if (!empty($this->excludedGameIds)) {
            return;
        }

        $this->excludedGameIds = Game::whereRaw('valid_bet_rate = 0')->pluck('id')->toArray();
    }

    /**
     * 載入各代理組的 V-Jackpot 抽水比例。
     *
     * `agent_customized_settings.value` 為 JSON，可針對個別 vendor_id 設定 rate，
     * 未指定的廠商則使用 `DEFAULT` 作為後備。
     */
    private function loadVJackpotRate(): void
    {
        if (!empty($this->vJackpotRates)) {
            return;
        }

        $rates = AgentCustomizedSetting::where('setting_key', AgentCustomizedSettingKeyEnum::V_JACKPOT_RATE)
            ->select('agent_group_id', 'value')
            ->get();

        foreach ($rates as $row) {
            $decoded = json_decode($row->value, true);

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $this->vJackpotRates[$row->agent_group_id][strtoupper((string) $key)] = $value;
                }

                continue;
            }

            $this->vJackpotRates[$row->agent_group_id]['DEFAULT'] = $decoded;
        }
    }

    /**
     * 載入各代理組的廠商盤費（game fee）。
     *
     * 為避免改動下游使用端，仍以 `customized_setting_group_id` 為 key：
     * 1. 先依 `organization_id` 整理每間組織的盤費表
     * 2. 再以 `customized_setting_group_id` 反查組織，建立對應關係
     */
    private function loadGameFeeRate(): void
    {
        if (!empty($this->gameFeeRates)) {
            return;
        }

        // P0 修正：重新設計查詢以符合 ONLY_FULL_GROUP_BY
        // 使用 MAX() 確保每個組合只回傳一筆確定的資料
        $gameFeeRows = DB::table('vendor_infos as vi')
            ->join('agent_vendors as av', 'av.vendor_info_id', 'vi.id')
            ->join('agents as a', 'a.id', 'av.agent_id')
            ->select(
                'vi.vendor_id',
                'a.customized_setting_group_id',
                DB::raw('MAX(a.organization_id) as organization_id'), // 使用 MAX 確保 GROUP BY 相容
                DB::raw('MAX(vi.cost) as cost') // 使用 MAX 確保 GROUP BY 相容
            )
            ->groupBy(['vi.vendor_id', 'a.customized_setting_group_id'])
            ->orderBy('a.customized_setting_group_id', 'desc')
            ->orderBy('vi.vendor_id', 'desc')
            ->get();

        $organizationGameFeeRate = [];
        foreach ($gameFeeRows as $row) {
            $organizationGameFeeRate[$row->organization_id][$row->vendor_id] = $row->cost;
        }

        // P0 修正：Agent 查詢也需要符合 GROUP BY 規範
        $allGroups = Agent::select(
            'customized_setting_group_id',
            DB::raw('MAX(organization_id) as organization_id') // 使用 MAX 確保唯一值
        )
            ->where('customized_setting_group_id', '!=', 0)
            ->groupBy('customized_setting_group_id')
            ->get();

        $missingFeeConfig = [];

        foreach ($allGroups as $row) {
            if (!isset($organizationGameFeeRate[$row->organization_id])) {
                $missingFeeConfig[] = $row->organization_id . ':' . $row->customized_setting_group_id;

                continue;
            }

            $this->gameFeeRates[$row->customized_setting_group_id] = $organizationGameFeeRate[$row->organization_id];
        }

        if (!empty($missingFeeConfig)) {
            $this->line(sprintf('警告：%d 組代理無廠商盤費設定，將以 cost=0 計算', count($missingFeeConfig)), 'comment');
            $this->line('organization_id:customized_setting_group_id：' . implode(', ', $missingFeeConfig), 'comment');
        }
    }

    private function runTeamSummary(string $startTime): void
    {
        $elapsed = microtime(true);
        $this->withLock(
            key: $this->teamLockKey($startTime),
            ttl: self::SETTLEMENT_LOCK_TTL,
            callback: fn () => $this->teamWriter->write($startTime)
        );
        Log::info("SP_summary_bet_record_team('{$startTime}') end usage [" . round(microtime(true) - $elapsed, 3) . ' s]');
    }

    private function runRollingChipSummary(string $startTime): void
    {
        $elapsed = microtime(true);
        $this->withLock(
            key: $this->rollingChipLockKey($startTime),
            ttl: self::SETTLEMENT_LOCK_TTL,
            callback: fn () => $this->rollingChipWriter->write($startTime)
        );
        Log::info("SP_summary_rolling_chip_record('{$startTime}') end usage [" . round(microtime(true) - $elapsed, 3) . ' s]');
    }

    /**
     * 廠商彙總鎖 key：粒度 = vendor × 小時。
     *
     * `runVendorBucket` 對 `summary_bet_records` 按 (vendor, 小時) 刪重建，足跡即此粒度；
     * 不同小時可並發（排程跑前一小時與手動補昨天互不阻擋），同一 (vendor, 小時) 仍互斥。
     *
     * @param  string  $start  整點 UTC 時間（Y-m-d H:00:00）
     */
    private function vendorLockKey(int $vendorId, string $start): string
    {
        return "summary_bet_record_command_{$vendorId}_" . date('Ymd_H', strtotime($start));
    }

    /**
     * 全民代理彙總（team）鎖 key：粒度 = 台北日。
     *
     * team 彙總（SP_summary_bet_record_team）對多數 vendor 按 `summary_time=StartTime`（UTC 小時）刪重建，
     * 但 vendor 3/10/23（918/mega888/pussy888）按 `DATE(StartTime +8h)`（台北日）整日刪重建。
     * 故 key 必須以「台北日」為界：同一台北日的各小時互斥以保護整日 row，不同台北日才可並發。
     * ⚠️ 不可改用 UTC 日或小時——會放掉「同台北日、跨 UTC 日」那對小時造成整日 row 競態。
     *
     * @param  string  $startTime  UTC 時間（Y-m-d H:i:s）
     */
    private function teamLockKey(string $startTime): string
    {
        return 'summary_bet_record_team_' . date('Ymd', strtotime($startTime . ' +8 hours'));
    }

    /**
     * 滾碼結算（rolling chip）鎖 key：粒度 = UTC 小時。
     *
     * 滾碼彙總（SP_summary_rolling_chip_record）純按 `summary_time=StartTime`
     * （UTC 小時）刪重建、無整日特例，故以 UTC 小時為界即可。
     * ⚠️ 不可 +8h——足跡與 team 彙總不同，加 8h 會過度互斥。
     *
     * @param  string  $startTime  UTC 時間（Y-m-d H:i:s）
     */
    private function rollingChipLockKey(string $startTime): string
    {
        return 'summary_rolling_chip_records_' . date('Ymd_H', strtotime($startTime));
    }

    /**
     * 供 SummaryBetRecordService 使用的輸出：CLI 沿用命令輸出，queue job 靜默。
     */
    private function serviceOutput(): OutputStyle
    {
        return $this->output ?? new OutputStyle(new ArrayInput([]), new NullOutput);
    }

    /**
     * CLI 模式輸出一行進度；queue job（無 output）時靜默。
     */
    private function line(string $message, ?string $style = null): void
    {
        $this->output?->writeln($style !== null ? "<{$style}>{$message}</{$style}>" : $message);
    }
}
