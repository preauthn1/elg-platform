<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一次性工具指令：清理各連線中已棄用的 log 表（主表及所有年月分表）。
 *
 * # ⛔ 2026-07-29 起禁止執行——改造為兩階段前不得 rollout
 *
 * 圓桌會審（MR !1850）＋跨六站唯讀實測證實：**`xsgcasino_log.command_logs` 是唯一副本，
 * 主庫一列都沒有**。現況直接 DROP 會永久銷毀約 **70 萬列**排程稽核軌跡（含 `trace_id`）。
 *
 * | 站 | log 庫列數 | log 庫 id／時間 | 主庫 id 起點／時間起點 |
 * |---|---|---|---|
 * | VWorld | 104,192 | 1～104,192／2025-10-29 ～ 11-20 | 1,758,774／2026-04-30 |
 * | TPower | 27,156 | 1～27,156／2025-11-12 ～ 11-20 | 1,450,462／2026-04-30 |
 * | Yes2Win | 27,396 | 1～27,396／2025-11-12 ～ 11-20 | 1,450,310／2026-04-30 |
 * | Alfawin77 | 197,020 | 1～197,020／2025-09-17 ～ 11-20 | 1,618,651／2026-04-30 |
 * | Me77 | 156,008 | 1～156,008／2025-10-01 ～ 11-20 | 1,584,354／2026-04-30 |
 * | VPlus33 | 196,908 | 1～196,908／2025-09-17 ～ 11-20 | 1,615,757／2026-04-30 |
 *
 * **六站 id 與時間範圍全部零重疊**：log 庫 id 一律自 1 起（獨立序列），時間一律終止於
 * 2025-11-20 19:32～19:33 ＝ `b4c2775d9`（revert: 取消遷移 logs 表）的時刻。
 *
 * **根因**：回遷工具 `db:move-tables`（已於本案刪除）是 `CREATE TABLE LIKE` ＋ `INSERT SELECT`
 * 且「目標已存在就跳過」，而主庫 `command_logs` 自 migration `2025_09_08_074831` 起一直存在
 * → **主表永遠被跳過、從未複製**。對照組：VWorld `command_logs_202510` 兩邊同為 11,361 列
 * ＝當時主庫無此分表，複製成功。
 *
 * ## 🔧 待改造：兩階段（RB 2026-07-29 拍板）
 * 1. **階段一** `RENAME TABLE <t> TO _deprecated_<t>`——瞬時、不佔額外空間、隨時可改回
 * 2. **階段二** 觀察期（建議 ≥3 個月）屆滿且無人索取後，才真正 DROP
 *
 * 併同要補：`--connection` 改必填、反轉預設為 dry-run（實刪需 `--execute`）、
 * `confirmExecutionEnvironment()` 環境閘、`resolvePrefixes()` 單元測試。
 * 待辦追蹤見 `docs/未完成計劃/RB/TODO.md` §2.2。
 *
 * ---
 *
 * ## 2026-07-28 修復的兩個缺陷（修復前這支從未真正刪掉任何表）
 *
 * 1. **白名單漏了 `log` 連線**：`vendor_api_errors`／`http_logs` 實際位於 `xsgcasino_log`
 *    （`config/database.php` 的 `log` 連線），而白名單其餘連線的 **`database` 全部繼承主庫
 *    `xsgcasino`**，因此掃 INFORMATION_SCHEMA 永遠掃不到目標。
 * 2. **涵蓋範圍不足**：殘留量九成以上是 `command_logs` 族（跨六站約 263MB），舊版沒列入。
 *
 * ## 🚫 為何 `command_logs`／`activity_logs` 只能對 `log` 連線清
 * 這兩張表在 **log 庫無寫入者**，但在 **主庫 `xsgcasino` 是活躍的**——`App\Models\CommandLog`
 * 未指定 `$connection`，寫的就是主庫那份（VWorld 逾 98 萬列），且 `archive:command_logs`
 * 排程持續在主庫建 `_YYYYMM` 分表。故以 {@see self::resolvePrefixes()} 隔離。
 *
 * > ⚠️ **隔離強度低於字面**：該方法寫的是「連線名為 `log` **且** database 以 `_log` 結尾」，
 * > 但 log 連線的 database 由 `env('DB_DATABASE') . '_log'` 串接產生、**必然**以 `_log` 結尾
 * > ⇒ 第二個條件恆真，**實際只有連線名在把關**。目前隔離仍有效（實測 `--connection=mysql`
 * > 回「無符合的資料表」、主庫 98 萬列未被列入），但別把它當兩道獨立防線。
 *
 * ## 🚫 不可整庫 drop `xsgcasino_log`
 * 該庫仍有活躍寫入者：`access_log_third_party_*`（舊系統 `API_Frontend` 的
 * `ThirdPartyAuthenticate` middleware）、`access_log_agent_*`（`api-internal`／`API_Agent`
 * 的 `Helpers/Common.php`）。
 *
 * > ⚠️ **保護來自 prefix 白名單，不是後綴位數**。舊版註解宣稱「regexp 只匹配 6 位 `_YYYYMM`，
 * > 不會誤觸 8 位 `_YYYYMMDD`」——**該前提不成立**：`API_Frontend/Controller.php:156` 就有
 * > `access_log_frontend_` . `date('Ym')` 這種 6 位月分表。目前打不穿只因
 * > `access_log_frontend` 不在 prefix 清單；**擴充 prefix 前務必重新評估**。
 *
 * ## ⚠️ 執行環境限制
 * `log` 連線只覆寫 `database`，**host／帳密全部繼承 `$mysqlCommon`（`DB_HOST` 等 env）**；
 * 白標連線則 **`read` 與 `write` 兩者都覆寫**且 host 各自獨立。因此：
 * - `docker exec -e DB_CONNECTION=<白標>` **不會**讓 `log` 連線改指到該白標——2026-07-28 實測：
 *   切 `VPlus33` 後 `--connection=log` 列出的仍是本地 Dev 的表。
 * - **要清某站生產的 `xsgcasino_log`，必須在該站自己的 pod 內執行**。
 * - 白標連線的列表（`select`）走 **read** host、DDL 走 **write** host，兩者可能不是同一台機器。
 *
 * ## 用法（目前僅允許 dry-run）
 *   php artisan patch:drop-deprecated-log-tables --dry-run              # 試跑（只列表）
 *   php artisan patch:drop-deprecated-log-tables --connection=log --dry-run
 *
 * ## 退役條件
 * 兩階段改造完成、六站＋Dev 都跑完階段一且觀察期屆滿、階段二執行後回報
 * 「無符合的資料表」，本檔才可刪除。
 */
class DropDeprecatedLogTables extends Command
{
    protected $signature = 'patch:drop-deprecated-log-tables
                            {--dry-run : 只列出將刪除的資料表，不實際執行}
                            {--connection= : 只處理指定連線名稱}';

    protected $description = '⛔【暫停執行】DROP 已棄用 log 表：vendor_api_errors／http_logs（所有連線）＋ command_logs／activity_logs（僅 log 庫）及年月分表——改造為兩階段前只可 --dry-run';

    /** 專放已棄用 log 表的連線名（其 database＝主庫名 + `_log`） */
    private const string LOG_CONNECTION = 'log';

    /** 所有連線都清的前綴（這兩張表僅存在於 log 庫，對主庫掃描為無害的空集合） */
    private array $prefixes = [
        'vendor_api_errors',
        'http_logs',
    ];

    /**
     * 🚫 **只對 log 庫清的前綴**——主庫同名表由 `App\Models\CommandLog` 等持續寫入，刪了會炸線上。
     * 納入條件見 {@see self::resolvePrefixes()}。
     */
    private array $logOnlyPrefixes = [
        'command_logs',
        'activity_logs',
    ];

    /**
     * 只處理這些專案連線（對應 config/database.php 中各專案的 key）
     * 明確白名單，避免誤刪 Laravel 框架注入的預設連線（如 mariadb、sqlite）
     */
    private array $projectConnections = [
        'mysql',
        self::LOG_CONNECTION,
        'VWorld',
        'TPower',
        'VPlus33',
        'Yes2Win',
        'Alfawin77',
        'Me77',
    ];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        // ⛔ 2026-07-29 起暫停實際執行（理由見 class docblock）：`xsgcasino_log` 的
        // `command_logs` 是唯一副本，直接 DROP 會永久銷毀六站約 70 萬列排程稽核軌跡。
        // 改造為兩階段（RENAME → 觀察期 → DROP）前只放行 dry-run。
        if (!$isDryRun) {
            $this->error('⛔ 本命令已暫停執行（2026-07-29）');
            $this->line('   原因：xsgcasino_log.command_logs 是唯一副本（六站約 70 萬列，與主庫 id／時間零重疊），DROP 不可逆。');
            $this->line('   須改造為兩階段（RENAME → 觀察期 ≥3 個月 → DROP）後才可執行。');
            $this->line('   詳見 class docblock 與 docs/未完成計劃/RB/TODO.md §2.2。');
            $this->newLine();
            $this->comment('   目前只允許：php artisan patch:drop-deprecated-log-tables --dry-run');

            return self::FAILURE;
        }

        $onlyConnection = $this->option('connection');

        $connections = $this->resolveConnections($onlyConnection);

        if (empty($connections)) {
            $this->error("找不到連線：{$onlyConnection}");

            return 1;
        }

        if ($isDryRun) {
            $this->warn('※ Dry-run 模式：只列出資料表，不實際執行');
            $this->newLine();
        }

        foreach ($connections as $connectionName) {
            $this->processConnection($connectionName, $isDryRun);
        }

        return 0;
    }

    private function resolveConnections(?string $only): array
    {
        $defined = array_keys(config('database.connections'));
        $project = array_intersect($this->projectConnections, $defined);

        if ($only !== null) {
            return in_array($only, $project, true) ? [$only] : [];
        }

        return array_values($project);
    }

    /**
     * 決定該連線要清哪些前綴。
     *
     * `command_logs`／`activity_logs` 只在 log 庫是死表，主庫同名表仍由程式持續寫入
     * （見 class docblock），因此要求**連線名為 `log`** 且 **database 以 `_log` 結尾**
     * 兩個條件同時成立才納入；任一不符即退回通用前綴——寧可少刪，不可誤刪。
     *
     * @return list<string>
     */
    private function resolvePrefixes(string $connectionName, string $database): array
    {
        if ($connectionName === self::LOG_CONNECTION && str_ends_with($database, '_log')) {
            return array_merge($this->prefixes, $this->logOnlyPrefixes);
        }

        return $this->prefixes;
    }

    private function processConnection(string $connectionName, bool $isDryRun): void
    {
        $this->info("── 連線：{$connectionName}");

        try {
            $db = DB::connection($connectionName);
            $database = $db->getDatabaseName();
            $totalDropped = 0;

            foreach ($this->resolvePrefixes($connectionName, $database) as $prefix) {
                $tables = $db->select(
                    'SELECT TABLE_NAME
                     FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = ?
                       AND TABLE_NAME REGEXP ?',
                    [$database, "^{$prefix}(_[0-9]{6})?$"]
                );

                foreach ($tables as $row) {
                    $totalDropped++;

                    if ($isDryRun) {
                        $this->line("   [DRY RUN] 將刪除：{$row->TABLE_NAME}");
                    } else {
                        Schema::connection($connectionName)->dropIfExists($row->TABLE_NAME);
                        $this->line("   已刪除：{$row->TABLE_NAME}");
                    }
                }
            }

            if ($totalDropped === 0) {
                $this->line('   （無符合的資料表，略過）');
            }
        } catch (\Throwable $e) {
            $this->error("   連線失敗，略過：{$e->getMessage()}");
        }

        $this->newLine();
    }
}
