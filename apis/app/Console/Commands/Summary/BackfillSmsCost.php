<?php

declare(strict_types=1);

namespace App\Console\Commands\Summary;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 回補 `sms_send_records` 的 `sms_gateway_info_id` 與 `cost`（欄位上線前的歷史列）target/tickets#4424。
 *
 * 還原方式與發送當下的挑選條件同一套（見 `AbstractValidationCodeService::getSmsGatewayInfo()`）：
 * 代理的通道組 ＋ `sms_gateway_id` ＋ 國碼是 `mobile` 的前綴 ＋ 設定與國家皆啟用。
 *
 * **只在單價唯一時才寫入**：候選設定不只一列、但單價相同時仍然算唯一（取 id 較小者，金額無差）；
 * 單價真的不同才視為無法還原，該列維持 `sms_gateway_info_id = 0` 並計入報告，交人工處理。
 *
 * ⚠️ **回補歷史一律加 `--include-disabled`**。不加的話條件會限定 `sms_gateway_infos.status = 1`，
 * 那回答的是「現在會挑哪一列」；通道停用之後，當時確實用過的那一列就對不上，該列連候選都撈不到，
 * 既不算可還原也不算互斥，**會被靜默跳過、不出現在報告裡**。2026-09-30 首次全量回補即因此漏掉
 * VPlus33 2,775 筆（gateway 18 已停用，單價 0）與 Me77 3,247 筆（gateway 18 單價 0、gateway 7 單價 0.1100）。
 *
 * `--fallback-group=` 是給「代理換過通道組」的殘留列用的：會員所屬代理現在的通道組查不到任何設定
 * （連停用的都沒有）時，改用指定通道組的設定來還原。**這是最後手段，用之前要先確認那個通道組確實
 * 涵蓋這些列的通道與國碼**，否則只是把別組的費率硬套上去。兩組都查得到而單價不同時仍算互斥、不寫入。
 * 實例（2026-09-30）：VWorld 有 43 筆的代理落在通道組 740，而該組完全沒有設定；通道組 9 恰好涵蓋
 * 那三支通道（13／21／2）與全部國碼（含 886、856 這類少見的），研判是代理原本就在 9、後來才被改到 740。
 *
 * ```bash
 * php artisan sms:backfill-cost --from=2026-01-01 --include-disabled --dry-run
 * php artisan sms:backfill-cost --from=2026-01-01 --to=2026-09-30 --include-disabled
 * php artisan sms:backfill-cost --from=2026-01-01 --include-disabled --fallback-group=9 --dry-run
 * ```
 *
 * 逐日一個 UPDATE，不整段吞：長區間的多表 UPDATE 會把鎖壓在 `sms_send_records` 上太久。
 * 冪等，可重跑——條件限定 `sms_gateway_info_id = 0`，已回補的列不會再被動到。
 */
class BackfillSmsCost extends Command
{
    protected $signature = 'sms:backfill-cost
        {--from= : 起日（+8 日曆日，YYYY-MM-DD，必填）}
        {--to= : 迄日（+8 日曆日，含；預設今天）}
        {--include-disabled : 連已停用的通道設定也納入候選（回補停用前的歷史列時需要）}
        {--fallback-group= : 會員所屬代理的通道組查不到設定時，改用這個通道組的設定（代理換過組時用）}
        {--dry-run : 只統計可還原與無法還原的筆數，不寫入}';

    protected $description = '回補 sms_send_records 的 sms_gateway_info_id 與 cost（target/tickets#4424）';

    private const string TZ = '+08:00';

    /**
     * 候選設定：把每一筆待回補的發送紀錄，對上發送當下條件能撈到的 sms_gateway_infos。
     *
     * `cost_kinds` 是不同單價的數量：1 代表金額可唯一決定（候選可能不只一列但同價），
     * 大於 1 代表單價互斥、無法還原。
     *
     * `$includeDisabled` 為 true 時不限定 `sms_gateway_infos.status = 1`。**回補歷史列必須開這個**：
     * 預設條件抄的是發送當下的挑選邏輯，那回答的是「現在會挑哪一列」；通道停用之後，
     * 當時確實用過的那一列就再也對不上，該列連候選都撈不到——既不算可還原也不算互斥，
     * 會被靜默跳過、不出現在報告裡。實例（2026-09-30）：VPlus33 的 gateway 18 與 Me77 的
     * gateway 7／18 都已停用，六站共 6,000 餘筆因此補不到，其中 Me77 的 gateway 7 單價 0.1100 有實際金額。
     */
    private function candidateSql(bool $includeDisabled, ?int $fallbackGroup): string
    {
        $statusCondition = $includeDisabled ? '' : "\n                                    AND sgi.`status` = 1";
        $groupCondition = $fallbackGroup === null
            ? 'sgi.sms_gateway_group_id = a.sms_gateway_group_id'
            : 'sgi.sms_gateway_group_id IN (a.sms_gateway_group_id, ?)';

        return <<<SQL
            SELECT r.id AS record_id,
                   MIN(sgi.id) AS info_id,
                   MIN(sgi.cost) AS cost,
                   COUNT(DISTINCT sgi.cost) AS cost_kinds
              FROM sms_send_records r
              JOIN members m ON m.id = r.member_id
              JOIN agents a ON a.id = m.parent
              JOIN sms_gateway_infos sgi ON {$groupCondition}
                                        AND sgi.sms_gateway_id = r.sms_gateway_id{$statusCondition}
              JOIN sms_gateway_info_countries sgic ON sgic.sms_gateway_info_id = sgi.id
              JOIN countries c ON c.id = sgic.country_id
                              AND c.`status` = 1
                              AND r.mobile LIKE CONCAT(c.country_code, '%')
             WHERE r.sms_gateway_info_id = 0 AND r.created_at >= ? AND r.created_at < ?
             GROUP BY r.id
            SQL;
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');

        if ($from === '') {
            $this->error('--from 必填（YYYY-MM-DD）');

            return self::FAILURE;
        }

        $fromDay = Carbon::parse($from, self::TZ)->startOfDay();
        $toDay = Carbon::parse((string) ($this->option('to') ?: Carbon::now(self::TZ)->toDateString()), self::TZ)->startOfDay();

        if ($toDay->lt($fromDay)) {
            $this->error('--to 不可早於 --from');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeDisabled = (bool) $this->option('include-disabled');
        $fallbackGroup = $this->option('fallback-group') === null ? null : (int) $this->option('fallback-group');

        $this->info(sprintf(
            '連線：%s／區間（+8）：%s〜%s／模式：%s／停用設定：%s／備援通道組：%s',
            DB::getDefaultConnection(),
            $fromDay->toDateString(),
            $toDay->toDateString(),
            $dryRun ? '乾跑' : '執行',
            $includeDisabled ? '納入' : '排除',
            $fallbackGroup ?? '無',
        ));

        $totalDone = 0;
        $totalStuck = 0;

        for ($day = $fromDay->copy(); $day->lte($toDay); $day->addDay()) {
            $startUtc = $day->copy()->utc()->toDateTimeString();
            $endUtc = $day->copy()->addDay()->utc()->toDateTimeString();

            [$done, $stuck] = $this->processDay($day->toDateString(), $startUtc, $endUtc, $dryRun, $includeDisabled, $fallbackGroup);
            $totalDone += $done;
            $totalStuck += $stuck;
        }

        $this->info(sprintf('合計：%s %d 筆，單價互斥無法還原 %d 筆', $dryRun ? '可還原' : '已回補', $totalDone, $totalStuck));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [可還原／已回補筆數, 單價互斥筆數]
     */
    private function processDay(string $label, string $startUtc, string $endUtc, bool $dryRun, bool $includeDisabled, ?int $fallbackGroup): array
    {
        $candidateSql = $this->candidateSql($includeDisabled, $fallbackGroup);
        // 備援通道組的綁定值出現在 JOIN 條件，位置早於 WHERE 的兩個時間參數
        $bindings = $fallbackGroup === null ? [$startUtc, $endUtc] : [$fallbackGroup, $startUtc, $endUtc];

        $stats = DB::selectOne(
            'SELECT SUM(x.cost_kinds = 1) AS resolvable, SUM(x.cost_kinds > 1) AS stuck FROM (' . $candidateSql . ') x',
            $bindings,
        );

        $resolvable = (int) ($stats->resolvable ?? 0);
        $stuck = (int) ($stats->stuck ?? 0);

        if ($resolvable === 0 && $stuck === 0) {
            return [0, 0];
        }

        if (!$dryRun && $resolvable > 0) {
            // 單價唯一（cost_kinds = 1）才寫入；互斥的留在原地等人工處理
            DB::affectingStatement(
                'UPDATE sms_send_records r
                   JOIN (' . $candidateSql . ') x ON x.record_id = r.id AND x.cost_kinds = 1
                    SET r.sms_gateway_info_id = x.info_id, r.cost = x.cost',
                $bindings,
            );
        }

        $this->line(sprintf(
            '  %s：%s %d 筆%s',
            $label,
            $dryRun ? '可還原' : '已回補',
            $resolvable,
            $stuck > 0 ? sprintf('，單價互斥 %d 筆（未處理）', $stuck) : '',
        ));

        return [$resolvable, $stuck];
    }
}
