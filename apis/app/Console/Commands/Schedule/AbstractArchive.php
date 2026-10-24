<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * 將上個月的資料備份到分表, 主表清除超過三個月前的資料, 並清除超過保留期的舊分表。
 *
 * ## 完整生命週期
 * 1. {@see self::prepareTable()} 建立當期分表 `<table>_YYYYMM`
 * 2. {@see self::migrateData()} 將目標區間資料 upsert 進分表
 * 3. {@see self::deleteOldData()} 刪除**主表**中 `$deleteMonths` 個月前的資料
 * 4. {@see self::prunePartitions()} 清理超過 `$keepPartitionMonths` 保留期的**分表**
 *
 * > ⚠️ 步驟 3 執行後，該區間資料的**唯一副本就是分表**。因此步驟 4 涉及不可逆的資料銷毀，
 * > 保留期由各子類自行宣告；未宣告（`null`）者跳過步驟 4，維持舊行為。
 *
 * ## 為何只有步驟 2 在交易內
 * - **步驟 3（主表刪除）**：批次化的用意就是讓每批各自提交。整段包進單一交易時 undo log 會
 *   隨刪除量無上限膨脹——cutoff 只在月初跳一格，那一次要刪掉整個月（實測 VWorld
 *   `activity_logs` 約 450 萬列），代價遠大於原子性換來的好處。且步驟 2 與步驟 3 處理的月份
 *   區間不重疊、步驟 2 走 upsert 可重跑，兩者本就不需要同生共死。
 * - **步驟 4（分表清理）**：`DROP TABLE` / `RENAME TABLE` 都是 DDL，在 MySQL 會**隱式提交**
 *   當前交易，放進交易區塊只會讓該交易提前結束。
 *
 * ## 補檔月份必須落在保留期內
 * `--month=` 會被 {@see self::backfillRange()} 擋在保留窗口與當月之間。補一個已過保留期的
 * 月份，等於建一張下一輪就該被刪掉的分表；擋在入口後，步驟 4 不需要為補檔開任何特例。
 */
abstract class AbstractArchive extends Command
{
    /** 分表清理的預演旗標；刻意不叫 `--dry-run`，理由見子類的 signature 宣告 */
    private const string PRUNE_DRY_RUN_OPTION = 'dry-run-partitions';

    /** 步驟 2 歸檔的每批列數；整批列會搬進 PHP 再 upsert，故不宜過大 */
    protected int $batchSize = 1000;

    /**
     * 步驟 3 主表刪除的每批列數；刻意大於 {@see self::$batchSize}。
     *
     * 刪除是單一 DELETE 語句、不必把列搬回 PHP，批次開太小只會讓語句數與批間延遲成比例暴增
     * （月初要刪掉整個月時，1000 列一批 ＝ 數千次來回 ＋ 數百秒純 sleep）。
     */
    protected int $deleteBatchSize = 10000;

    protected int $deleteMonths = 3; // 刪除月份數

    /**
     * 分表保留月數（含當月）；`null` ＝ 不清理分表。
     *
     * 預設 `null` 是刻意的安全預設：本類別的子類會由排程自動執行，未經明確宣告
     * 不應該自動 DROP TABLE。子類要啟用時覆寫成正整數。
     */
    protected ?int $keepPartitionMonths = null;

    protected string $table;

    protected string $dateColumn = 'day'; // 用於歸檔的日期欄位，預設為 'day'

    public function handle(): int
    {
        if (!Schema::hasTable($this->table)) {
            throw new RuntimeException("歸檔失敗，主表不存在：{$this->table}");
        }

        [$startDay, $endDay, $yearMonth] = $this->resolveTargetRange();

        $this->info("開始歸檔 {$startDay} ~ {$endDay} 的資料...");

        $tableName = $this->prepareTable($yearMonth);

        // 只有搬資料需要原子性；主表刪除與分表清理刻意在交易外，理由見 class docblock
        DB::transaction(fn () => $this->migrateData($tableName, $startDay, $endDay));

        $this->info('歸檔流程完成');

        $this->deleteOldData();
        $this->prunePartitions();

        return self::SUCCESS;
    }

    /**
     * 本次要歸檔的區間，以及分表用的年月。
     *
     * ## 為何不分「每日／每月模式」
     * 兩者的差別只有區間寬度是一天或一個月——分表一律是 `<table>_YYYYMM`、步驟 3／4 完全
     * 相同。原本用 `isDailyMode()` ＋ 兩處分支表達這件事，代價是：`getTargetMonth()` 每次
     * 執行被算 5 次（`handle()` 一次，`prepareTable()` 與 `migrateData()` 各透過
     * `isDailyMode()` 再算兩次）、而 `isDailyMode()` 的 `strlen === 10` 判斷永遠被前面的
     * `option('daily')` 短路，屬死代碼。改為在此一次解出 `[起, 迄]` 往下傳。
     *
     * ## `--month` 與 `--daily` 的優先順序
     * 以 `match` 明文化。原本 `--month` 決定目標字串、`--daily` 另外決定區間寬度，兩者同時
     * 給時會走每日分支對 `2025-09` 做 `strtotime()` ⇒ **靜默只搬該月 1 號那一天**，卻回報
     * 「已將 2025-09 的 N 筆資料歸檔」。補檔正是用來修漏搬資料的操作，不能這樣錯。
     *
     * @return array{0: string, 1: string, 2: string} [起, 迄, 分表年月 YYYYMM]
     */
    protected function resolveTargetRange(): array
    {
        [$start, $end] = match (true) {
            (bool) $this->option('month') => $this->backfillRange((string) $this->option('month')),
            (bool) $this->option('daily') => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            // 預設整個上個月；`NoOverflow` 是必要的——3/31 減一個月會溢位成 3/3，
            // 後面的 startOfMonth() 因此落在 3/1，整月偏掉
            default => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ],
        };

        // day 是 date、created_at 是 datetime
        $format = $this->dateColumn === 'created_at' ? 'Y-m-d H:i:s' : 'Y-m-d';

        return [$start->format($format), $end->format($format), $start->format('Ym')];
    }

    /**
     * `--month=YYYY-MM` 的區間，並把月份擋在 `[保留期起點, 當月]` 之間。
     *
     * 補一個已超過保留期的月份，等於建一張下一輪就該被刪掉的分表；擋在入口後，
     * 步驟 4 不需要為補檔開任何特例（保留期內的分表本來就不過期）。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function backfillRange(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException("月份格式錯誤，請使用 YYYY-MM 格式（例如：2025-09）：{$month}");
        }

        $start = Carbon::parse("{$month}-01")->startOfMonth();
        $yearMonth = $start->format('Ym');
        $thisMonth = now()->format('Ym');

        // 未來月份只會建出一張永遠空著的分表
        if ($yearMonth > $thisMonth) {
            throw new InvalidArgumentException("補檔月份不得晚於當月（{$thisMonth}）：{$month}");
        }

        if ($this->keepPartitionMonths !== null && $yearMonth < $this->partitionCutoff()) {
            throw new InvalidArgumentException(sprintf(
                '補檔月份 %s 已超過分表保留期（保留 %d 個月，即 %s 起），拒絕建立分表',
                $month,
                $this->keepPartitionMonths,
                $this->partitionCutoff()
            ));
        }

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * 準備分表
     */
    protected function prepareTable(string $yearMonth): string
    {
        $tableName = sprintf('%s_%s', $this->table, $yearMonth);

        // 檢查表是否已存在，若不存在則建立新表
        if (Schema::hasTable($tableName)) {
            $this->info("分表 {$tableName} 已存在，無需重建。");
        } else {
            DB::statement("CREATE TABLE {$tableName} LIKE {$this->table}");

            $this->info("分表 {$tableName} 已成功建立。");
        }

        return $tableName;
    }

    /**
     * 把 `[$startDay, $endDay]` 區間的資料 upsert 進分表。
     *
     * 區間由 {@see self::resolveTargetRange()} 一次解出並傳入——欄位型別（date／datetime）
     * 的格式差異也在那裡處理完，此處不再分支。
     */
    protected function migrateData(string $tableName, string $startDay, string $endDay): void
    {
        $this->info("歸檔區間：{$startDay} ~ {$endDay}");

        $total = 0;
        $query = DB::table($this->table)
            ->whereBetween($this->dateColumn, [$startDay, $endDay]);

        // 先用日期索引取區間的 id 下界，把游標直接定在區間起點。
        // chunkById 的第一頁沒有 `id > ?` 條件，MySQL 會挑 PRIMARY 從 id=1 一路掃到目標
        // 區間（實測 VWorld activity_logs 單日光第一頁就要 19 秒）；補上下界後每一頁都是
        // 純 PK range scan（同一頁降到 60 毫秒）。
        $minId = (clone $query)->min('id');

        if ($minId !== null) {
            // ⚠️ 必須是 chunkById（游標式 `id > ?`）而非 chunk（OFFSET 分頁）：
            // 區間條件走日期索引、排序卻是 id，chunk 的每一頁都會退化成
            // 「撈出整個區間的完整列 → filesort → 丟掉前 N 筆、只留這一頁」。
            // 實測 VWorld activity_logs 單日 20 萬列 ＝ 204 頁 × 每頁重掃 20 萬列，
            // 整支命令從個位數秒膨脹到 507 秒。
            $query->where('id', '>=', $minId)
                ->orderBy('id')
                ->chunkById($this->batchSize, function ($rows) use ($tableName, &$total) {
                    $insertData = $rows->map(fn ($row) => (array) $row)->toArray();
                    // 直接 upsert，避免重複插入
                    DB::table($tableName)->upsert($insertData, ['id']);
                    $total += count($insertData);

                    // 添加批次間延遲
                    usleep(50000); // 50ms
                    $this->info("已處理 {$total} 筆資料...");
                });
        }

        if ($total === 0) {
            $this->info('本區間無需歸檔的資料。');
        } else {
            $this->info("已將 {$total} 筆資料歸檔至 {$tableName}。");
        }
    }

    /**
     * 批次刪除主表中指定月份前的資料
     */
    protected function deleteOldData(): void
    {
        // 滾動日界：cutoff 每天前進一天，每次只刪一天的量。
        //
        // 原本是 `subMonths()->endOfMonth()`，cutoff 只在月初跳一格 ⇒ 7/31 的 cutoff 是 5/31、
        // 8/1 變成 6/30，**整個 6 月在一次執行內刪掉**（VWorld activity_logs 約 450 萬列，
        // 光批間 sleep 就數百秒）。改成 endOfDay 後那顆月初炸彈消失。
        //
        // 保留期只增不減：同一天的 cutoff 一定早於或等於原本的月底 cutoff（8/20 現況刪到
        // 6/30、改後只刪到 6/20），故無保存政策損失；峰值主表尺寸不變（月底本來就已累積
        // 約 61 天），只有平均值上升。部署當天 cutoff 往回走，第一次跑不會多刪任何東西。
        //
        // `NoOverflow` 是必要的：`subMonths()` 沿用 PHP DateTime 的溢位語意，目標月份天數
        // 不足時日期會滾進下個月（4/29 減 2 個月 ⇒ 3/1、4/30 ⇒ 3/2，而 5/1 又回到 3/1），
        // 使 cutoff 在月底幾天亂跳甚至倒退。加上之後單調不減。
        $cutoffDay = now()->subMonthsNoOverflow($this->deleteMonths)->endOfDay();
        $deleteBeforeDay = $this->dateColumn === 'created_at'
            ? $cutoffDay->toDateTimeString()
            : $cutoffDay->toDateString();

        $totalDeleted = 0;

        do {
            $deletedRows = DB::table($this->table)
                ->where($this->dateColumn, '<=', $deleteBeforeDay)
                ->limit($this->deleteBatchSize)
                ->delete();
            $totalDeleted += $deletedRows;

            // 每批次間暫停，減輕資料庫壓力
            if ($deletedRows > 0) {
                usleep(20000); // 20ms
            }
        } while ($deletedRows > 0);

        $this->info("已刪除主表中 {$totalDeleted} 筆 {$this->deleteMonths} 個月前的資料。");
    }

    /**
     * 刪除超過保留期的年月分表。
     *
     * 保留窗口為「含當月往回數 `$keepPartitionMonths` 個月」，年月後綴早於此窗口起點的即過期。
     * 例：`$keepPartitionMonths = 6`、當月 2026-07 ⇒ 保留 `_202602` ～ `_202607`，
     * `_202601` 及更早刪除。當月分表正由 `--daily` 模式持續寫入，永遠落在保留窗口內。
     *
     * DROP 失敗（鎖等待、權限）極罕見，且未捕捉的例外會經 `bootstrap/app.php` 的 `report()`
     * 進 AlertGate 分類降噪發 TG——比自己收集失敗清單再發一次 BotNotify 準確且不需要維護。
     */
    protected function prunePartitions(): void
    {
        if ($this->keepPartitionMonths === null) {
            return;
        }

        $cutoff = $this->partitionCutoff();
        $partitions = $this->findExpiredPartitions($cutoff);

        if ($partitions === []) {
            $this->info("無超過保留期的分表（保留 {$this->keepPartitionMonths} 個月，即 {$cutoff} 起）。");

            return;
        }

        $isDryRun = (bool) $this->option(self::PRUNE_DRY_RUN_OPTION);

        $this->info(sprintf(
            '找到 %d 個超過保留期的分表（保留 %d 個月，即 %s 起）：',
            count($partitions),
            $this->keepPartitionMonths,
            $cutoff
        ));

        foreach ($partitions as $partition) {
            $label = "{$partition->name}（約 {$partition->rows_estimate} 列 / {$partition->size_mb} MB）";

            if ($isDryRun) {
                $this->line("   [DRY RUN] 將刪除：{$label}");

                continue;
            }

            Schema::dropIfExists($partition->name);
            $this->line("   已刪除：{$label}");

            // 排程無人看管、DROP 不可逆，刪除一律留痕
            Log::warning('已刪除歸檔分表', [
                'table' => $partition->name,
                'rows_estimate' => $partition->rows_estimate,
                'size_mb' => $partition->size_mb,
                'keep_months' => $this->keepPartitionMonths,
            ]);
        }
    }

    /**
     * 保留窗口的起始年月（`YYYYMM`）；年月後綴早於此值者即過期。
     *
     * 必須先 `startOfMonth()` 再回推：`subMonths()` 沿用 PHP DateTime 的溢位語意，目標月份
     * 天數不足時日期會滾進下個月、`format('Ym')` 因此整月偏掉（實測 `2026-07-29` 減 5 個月
     * ⇒ `2026-03-03`，cutoff 誤為 `202603`，使保留窗口提早前進、多刪一個分表）。
     * 日號固定為 1 即永不溢位。
     *
     * 前提是 `$keepPartitionMonths >= 1`（1 時 cutoff 等於當月、當月分表因 `< cutoff` 被排除，
     * 是安全的最小值；0 會讓 cutoff 跑到次月，把本次剛寫入的當月分表一起刪掉）。該值是子類
     * 寫死的字面值，屬編譯期契約，由 ArchivePartitionRetentionTest 的政策測試守備。
     */
    private function partitionCutoff(): string
    {
        return now()->startOfMonth()->subMonths($this->keepPartitionMonths - 1)->format('Ym');
    }

    /**
     * 找出年月後綴早於 `$cutoffYearMonth` 的分表。
     *
     * 以 INFORMATION_SCHEMA 比對錨定的 pattern，避免誤觸同前綴的其他資料表
     * （如 `<table>_backup`）；並限定 `BASE TABLE` 以排除 view。
     *
     * @return list<object> 每筆含 name / rows_estimate / size_mb
     */
    private function findExpiredPartitions(string $cutoffYearMonth): array
    {
        return DB::select(
            "SELECT TABLE_NAME AS name,
                    COALESCE(TABLE_ROWS, 0) AS rows_estimate,
                    ROUND((COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) / 1024 / 1024, 1) AS size_mb
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME REGEXP ?
               AND SUBSTRING(TABLE_NAME, -6) < ?
             ORDER BY TABLE_NAME",
            ["^{$this->table}_[0-9]{6}$", $cutoffYearMonth]
        );
    }
}
