<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use App\Enums\QueueJobKeyEnum;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Services\BotNotifyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SBR 完成標記缺口稽核（target/tickets#4257 收尾）
 *
 * 回看補洞（SummarizeBetRecordHour）只涵蓋近 6 小時；當排程或佇列整條靜默中斷
 * 超過視窗（Horizon 未啟動、long-running 佇列塞死、dispatch 連續缺失），缺格
 * 會永久留下且無任何告警——與 2026-08-28 事故「三層各自無錯、全程靜默」同型態。
 *
 * 本命令直接掃 queue_job_runs（job_key=summary_bet_record）判缺，不依賴 Redis／Horizon，是唯一能
 * 偵測「整條佇列掛掉」的防線；發現缺口即 BotNotify 並以失敗狀態結束
 * （command_logs 留下可稽核紀錄）。
 *
 * **補跑一律走 Olympus「操作 → 注單彙總」**（{@see \App\Filament\Admin\Pages\Operations\SummaryBetRecord}），
 * 一律禁止改以 `summary_bet_record` CLI 裸跑：後台頁有權限控管與操作紀錄，CLI 兩者皆無。
 * 後台以「整天」為單位重跑，所以告警明細必須聚合到**遊戲商 × 日期**（日期取各遊戲商自己的
 * 對帳時區，與後台的時區選項同一口徑），不列小時——完整小時明細只進 log。
 * 告警文字一律用營運看得懂的說法（收訊的是 TG 群不是開發），不要把「完成標記」「回看自癒視窗」
 * 這類內部名詞寫進去。
 *
 * ⚠️ **缺口一律只通知、不自動補跑**：逾 14:00 執行的補跑另有系統外義務（須填寫補帳 Excel），
 * 自動補會把那一步靜默跳過。要改成自動補跑，必須先確認該義務由誰承接。
 *
 * @knowledge rules/summary-bet-record
 */
class AuditSummaryBetRecordRunsCommand extends Command
{
    /**
     * 稽核起點距now的安全時差（小時）：早於此的 bucket 才判缺。
     *
     * trigger bucket 於整點後 30 分派發、retry backoff 最長 660 秒，加上後續整點
     * 的回看自癒，正常情況 4 小時內必然補齊；更年輕的 bucket 尚在自癒窗口內，
     * 納入稽核會誤報。
     */
    public const int SAFE_LAG_HOURS = 4;

    /*
     * 預設視窗 48 小時（每日排程下每段被掃兩次）：audit 自己也可能被 scheduler
     * 靜默跳過，固定 24 小時視窗漏跑一輪就會留下永久未稽核區間；48 小時重疊
     * 讓單次漏跑仍由下一輪兜底。
     */
    protected $signature = 'summary_bet_record:audit
        {--hours=48 : 稽核視窗長度（小時），自安全時差起往回掃}';

    protected $description = '稽核 SBR 完成標記缺口（queue_job_runs；回看視窗外的靜默漏桶偵測，不依賴 Redis/Horizon）';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $configs = SummaryBetRecordRunner::scheduledConfigs(config('summary_bet_record'));

        $buckets = [];
        for ($i = self::SAFE_LAG_HOURS; $i < self::SAFE_LAG_HOURS + $hours; $i++) {
            $buckets[] = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        }

        $missing = QueueJobRun::missingPairs(QueueJobKeyEnum::SUMMARY_BET_RECORD, $buckets, array_keys($configs));

        if ($missing === []) {
            $this->info(sprintf(
                '過去 %dH 遊戲商投注彙總排程檢測：無缺漏（%s ～ %s，%d 家遊戲商）',
                $hours,
                end($buckets),
                $buckets[0],
                count($configs),
            ));

            return self::SUCCESS;
        }

        // 判缺粒度是 UTC 小時，補跑粒度是後台的「整天」：這裡把缺格收斂成
        // 遊戲商 × 日期，日期用該商的對帳時區換算，營運照著填後台就不必自己換算
        $datesByVendor = [];
        foreach ($missing as $bucket => $missingVendorIds) {
            foreach ($missingVendorIds as $vendorId) {
                $offsetHours = SummaryBetRecordRunner::reconcileTimezone($configs[$vendorId]);
                $datesByVendor[$vendorId][Carbon::parse($bucket)->addHours($offsetHours)->toDateString()] = true;
            }
        }

        ksort($datesByVendor);
        // 告警看的人要去後台選遊戲商，所以印 vendors.name（後台選單同一個名字），
        // 不是 id 也不是 third_party_code；一次撈完，不在迴圈裡查
        $vendorNames = Vendor::query()->whereIn('id', array_keys($datesByVendor))->pluck('name', 'id');
        $details = [];
        $runCount = 0;
        foreach ($datesByVendor as $vendorId => $dateSet) {
            $dates = array_keys($dateSet);
            sort($dates);
            $runCount += count($dates);

            $details[] = sprintf(
                '%s (%d, %s) : %s',
                $vendorNames[$vendorId] ?? $configs[$vendorId]['third_party_code'],
                $vendorId,
                SummaryBetRecordRunner::timezoneOption(SummaryBetRecordRunner::reconcileTimezone($configs[$vendorId])),
                implode('、', $dates),
            );
        }
        // 一家遊戲商一行：TG 上擠成一段會看不出有幾家、哪家對哪些日期
        $detail = implode(PHP_EOL, $details);

        // 摘要先行、明細殿後：BotNotify 會截到 1,000 字元（大規模缺口如未預塞
        // 的首跑可達數千字），規模資訊必須在訊息頭部；完整明細恆在 log
        $summary = sprintf(
            '%d 家遊戲商、共 %d 天沒有彙總資料',
            count($datesByVendor),
            $runCount,
        );

        $this->error("過去 {$hours}H 遊戲商投注彙總排程檢測：{$summary}" . PHP_EOL . $detail);
        Log::error('summary_bet_record:audit 發現完成標記缺口', ['missing' => $missing]);
        app(BotNotifyService::class)->send(
            "[過去 {$hours}H 遊戲商投注彙總排程檢測] {$summary}。" . PHP_EOL
            . '請至 Olympus「操作 → 注單彙總」依下列遊戲商與日期重跑整天。' . PHP_EOL
            . '逾 14:00 執行須另外填寫補帳 Excel。' . PHP_EOL
            . $detail
        );

        return self::FAILURE;
    }
}
