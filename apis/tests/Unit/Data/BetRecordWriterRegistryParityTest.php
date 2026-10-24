<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * TBR／SBR writer 清單的版控守衛（多幣別錢包 第零期／階段 0.1 交付）。
 *
 * Gate B（簡報 Gate 2）的解除條件是「找全所有 writer → 指定 owner → 同一 vendor×時段
 * fencing → 驗證覆蓋率」。本測試守住其中「找全」與「指定 owner」兩項：版控內新增了寫
 * total_bet_records 的 stored procedure 而沒有登記，測試就紅。
 *
 * 跨 repo（RD-Studio-01／API_External）的部分無法由本 repo 的測試守衛，
 * 走 artisan bet-record:audit-writers 的人工複核流程（Task 6）。
 *
 * php artisan test tests/Unit/Data/BetRecordWriterRegistryParityTest.php
 */
class BetRecordWriterRegistryParityTest extends TestCase
{
    /**
     * database/procedures/ 中寫入 total_bet_records 的 SP 支數（2026-08-31 複驗）。
     *
     * 72 支 `SP_transfer_bet_record_*` ＋ 1 支 `SP_transfer_event_record_pgs`。
     */
    private const int SP_WRITING_TOTAL_BET_RECORDS = 73;

    /**
     * owner 的值域。與 tables.php 的 VALID_OWNERS 不同——這裡記的是「誰觸發這個 writer」，
     * 不是「寫入邏輯放在哪個 repo」，所以多了 MySQL-Event 與 manual 兩種。
     *
     * MySQL-Event  由資料庫的 information_schema.EVENTS 排程呼叫
     * manual       版控內查無任何自動呼叫者，只能手動 CALL（不是「查不到」，是「查到了沒有」）
     * 待查         尚未查明，會讓 test_every_registered_writer_declares_owner 轉紅
     */
    private const array VALID_OWNERS = [
        'APIs', 'RD-Studio-01', 'API_External', 'API_Agent', 'API_Frontend', 'MySQL-Event', 'manual', '待查',
    ];

    /**
     * member_wallets 只會出現在 external 段——API_Agent 的錢包直寫點不是 TBR／SBR writer，
     * 但簡報第 9 節要求一併登記（第一期要同步改造），所以納入值域。
     */
    private const array VALID_TARGETS = [
        'total_bet_records', 'summary_bet_records', 'event_total_bet_records', 'member_wallets',
    ];

    /**
     * external 段的 key 允許的 repo 前綴。四個舊 repo 的本機 checkout 都是
     * `~/Workspace/<repo>/_release/`，所以路徑部分一律以 `_release/` 開頭。
     */
    private const array VALID_EXTERNAL_REPOS = ['RD-Studio-01', 'API_External', 'API_Agent', 'API_Frontend'];

    /**
     * 掃到但逐檔覆核後確認「只讀不寫」的檔案。
     *
     * ⚠️ 這是**檔案層級**的白名單，代價是該檔日後新增了真正的寫入也不會被抓到。
     *    覆核時的判斷依據記在各筆後面，日後這些檔案有大改要重新覆核。
     */
    private const array NON_WRITER_FILES = [
        // 讀 TotalBetRecord 取跑馬燈名單，寫入打在 MarqueeWinPointRecord
        'app/Http/Controllers/Frontend/MarqueeController.php',
        // 讀 SummaryBetRecord 產報表，寫入打在 members
        'app/Http/Controllers/Frontend/MemberController.php',
        // 讀 summary 類資料，寫入打在 members 與 unlimit 獎金表
        'app/Http/Controllers/Frontend/UnlimitHierarchyController.php',
        // 只以 exists() 檢查注單是否已進 TotalBetRecord（已進者拒絕退款），
        // 寫入打在 fumasw_bet_records、bet_record_recovery_logs、錢包與帳變流水
        'app/Console/Commands/BetRecordRecovery/RefundUnsettled.php',
    ];

    private const array VALID_OPS = ['insert', 'upsert', 'update', 'delete', 'mixed'];

    /**
     * fencing 的機制前綴值域（2026-08-31 Task 6 定版，RB 拍板採「機制＋觸發源」粒度）：
     *   schedule:  由排程獨佔，後接「觸發專案:命令名@排程時機」
     *   realtime:  即時寫入，沒有時段獨佔，靠唯一鍵或列鎖
     *   manual:    人工觸發，或排程宣告已停用／查無登記
     */
    private const array VALID_FENCING_PREFIXES = ['schedule:', 'realtime:', 'manual:'];

    public function test_registry_has_all_three_sections(): void
    {
        $registry = $this->registry();

        foreach (['sp', 'php', 'external'] as $section) {
            $this->assertArrayHasKey($section, $registry, "writers.php 缺少 {$section} 區段");
            $this->assertIsArray($registry[$section]);
        }
    }

    public function test_every_sp_writing_total_bet_records_is_registered(): void
    {
        $registered = array_keys($this->registry()['sp']);
        $scanned = $this->scanProceduresWritingTotalBetRecords();

        $unregistered = array_values(array_diff($scanned, $registered));
        $stale = array_values(array_diff($registered, $scanned));

        $this->assertSame([], $unregistered, sprintf(
            "以下 stored procedure 寫入 total_bet_records 但未登記於 writers.php：\n  %s\n"
            . '新增或改寫 SP 時必須同步登記，否則第四期會漏改 SELECT，'
            . '該路徑寫進去的 currency_id 會是 NULL／DEFAULT。',
            implode("\n  ", $unregistered),
        ));

        $this->assertSame([], $stale, sprintf(
            "writers.php 登記了已不存在或已不再寫入 total_bet_records 的 SP：\n  %s",
            implode("\n  ", $stale),
        ));
    }

    public function test_scanned_sp_count_matches_measured_baseline(): void
    {
        $this->assertCount(
            self::SP_WRITING_TOTAL_BET_RECORDS,
            $this->scanProceduresWritingTotalBetRecords(),
            'database/procedures/ 中寫入 total_bet_records 的 SP 數量與 2026-08-31 複驗基線（73）不符；'
            . '若為刻意增減，請同步更新本常數並複核第四期的改造範圍。',
        );
    }

    public function test_every_entry_has_complete_shape(): void
    {
        $problems = [];

        foreach (['sp', 'php', 'external'] as $section) {
            foreach ($this->registry()[$section] as $name => $entry) {
                $problems = [...$problems, ...$this->entryProblems($section . '.' . $name, $entry)];
            }
        }

        $this->assertSame([], $problems, "writers.php 條目結構不完整：\n  " . implode("\n  ", $problems));
    }

    /**
     * external 段的 key 必須是 `<repo>:_release/<路徑>:<行號>`。
     *
     * 跨 repo 的檔案本 repo 掃不到，唯一能守的就是「位置寫得回查得到」——repo 名打錯、
     * 漏了 `_release/`、或沒寫行號，第四期照這份清單改造時就找不到檔案。
     */
    public function test_external_writer_keys_are_locatable(): void
    {
        $problems = [];

        foreach (array_keys($this->registry()['external']) as $location) {
            $parts = explode(':', $location);

            if (count($parts) !== 3) {
                $problems[] = sprintf('%s 不是 <repo>:<路徑>:<行號> 三段式', $location);

                continue;
            }

            [$repo, $path, $line] = $parts;

            if (!in_array($repo, self::VALID_EXTERNAL_REPOS, true)) {
                $problems[] = sprintf('%s 的 repo「%s」不在值域內', $location, $repo);
            }

            if (!str_starts_with($path, '_release/')) {
                $problems[] = sprintf('%s 的路徑未以 _release/ 開頭，對不上本機 checkout 結構', $location);
            }

            if (preg_match('/^[1-9]\d*$/', $line) !== 1) {
                $problems[] = sprintf('%s 的行號不是正整數', $location);
            }
        }

        $this->assertSame([], $problems, sprintf(
            "writers.php 的 external 段有無法回查的位置：\n  %s",
            implode("\n  ", $problems),
        ));
    }

    public function test_no_unreviewed_php_writer_candidate(): void
    {
        $registeredFiles = [];

        foreach (array_keys($this->registry()['php']) as $location) {
            $registeredFiles[explode(':', $location)[0]] = true;
        }

        $unreviewed = [];

        foreach ($this->scanPhpWriterCandidates() as $file) {
            if (isset($registeredFiles[$file]) || in_array($file, self::NON_WRITER_FILES, true)) {
                continue;
            }

            $unreviewed[] = $file;
        }

        $this->assertSame([], $unreviewed, sprintf(
            "以下檔案同時出現注單彙總表的符號與寫入動詞，但既未登記為 writer、也未列入非寫入白名單：\n  %s\n"
            . '請逐檔覆核：真的寫入就登記到 writers.php 的 php 段（key 用 路徑:行號），'
            . '只是讀取就加進 NON_WRITER_FILES。',
            implode("\n  ", $unreviewed),
        ));
    }

    public function test_registered_php_writer_locations_still_exist(): void
    {
        $problems = [];

        foreach (array_keys($this->registry()['php']) as $location) {
            [$file, $line] = explode(':', $location);
            $path = self::repoPath($file);

            if (!is_file($path)) {
                $problems[] = sprintf('%s 檔案不存在', $location);

                continue;
            }

            // 行號會隨代碼變動漂移。這條測試只保證「該行還在檔案範圍內」，
            // 內容是否仍是寫入語句由 note 記錄的語句摘要供人工比對。
            if (count(file($path)) < (int) $line) {
                $problems[] = sprintf('%s 行號超出檔案長度', $location);
            }
        }

        $this->assertSame([], $problems, sprintf(
            "writers.php 的 php 段有失效的位置：\n  %s\n第四期要照這份清單改，位置漂了就會漏改。",
            implode("\n  ", $problems),
        ));
    }

    public function test_every_registered_writer_declares_owner(): void
    {
        $missing = [];

        foreach ($this->registry() as $section => $entries) {
            foreach ($entries as $name => $entry) {
                $owners = is_array($entry['owner'] ?? null) ? $entry['owner'] : [];

                if ($owners === [] || in_array('待查', $owners, true)) {
                    $missing[] = sprintf('%s.%s', $section, $name);
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "以下 writer 尚未指定 owner，Gate B 無法解除：\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * fencing ＝「同一遊戲商×時段由誰獨佔寫入」，是 Gate B 第 3 項的落地。
     *
     * 只守三件事：有值、機制前綴在值域內、schedule 類要帶觸發時機。內容正確與否
     * 靠人工覆核（排程宣告會被改、被註解），但格式失守會讓稽核命令的退出碼失去意義——
     * 隨便填一個字串就能讓 Gate B 轉綠。
     */
    public function test_every_registered_writer_declares_fencing(): void
    {
        $problems = [];

        foreach ($this->registry() as $section => $entries) {
            foreach ($entries as $name => $entry) {
                $reference = sprintf('%s.%s', $section, $name);
                $fencing = $entry['fencing'] ?? null;

                if (!is_string($fencing) || trim($fencing) === '') {
                    $problems[] = sprintf('%s：尚未指定 fencing', $reference);

                    continue;
                }

                $prefix = current(array_filter(
                    self::VALID_FENCING_PREFIXES,
                    static fn (string $candidate): bool => str_starts_with($fencing, $candidate),
                ));

                if ($prefix === false) {
                    $problems[] = sprintf('%s：fencing 的機制前綴不在值域內（%s）', $reference, $fencing);

                    continue;
                }

                // schedule 類沒帶時機就等於沒回答「同一時段」這半邊，第四期排改造順序時
                // 看不出兩個專案會不會撞在同一分鐘。
                if ($prefix === 'schedule:' && !str_contains($fencing, '@')) {
                    $problems[] = sprintf('%s：schedule 類的 fencing 必須以 @ 標出觸發時機（%s）', $reference, $fencing);
                }
            }
        }

        $this->assertSame([], $problems, sprintf(
            "以下 writer 的並行防護規則不合格，Gate B 無法解除：\n  %s",
            implode("\n  ", $problems),
        ));
    }

    /**
     * 逐項驗證一筆 sp 條目的結構。
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function entryProblems(string $name, array $entry): array
    {
        $problems = [];

        foreach (['target' => self::VALID_TARGETS, 'op' => self::VALID_OPS] as $key => $domain) {
            if (!array_key_exists($key, $entry) || !is_string($entry[$key])) {
                $problems[] = sprintf('%s 的 %s 缺漏或不是字串', $name, $key);
            } elseif (!in_array($entry[$key], $domain, true)) {
                $problems[] = sprintf('%s 的 %s「%s」不在值域內', $name, $key, $entry[$key]);
            }
        }

        // owner 是陣列而非單值：SP_transfer_bet_record_pg 同時由 API_External 與 MySQL
        // 排程觸發，單值字串記不下這件事（與 tables.php 的 owner 同樣理由）。
        if (!array_key_exists('owner', $entry) || !is_array($entry['owner']) || $entry['owner'] === []) {
            $problems[] = sprintf('%s 的 owner 缺漏或不是非空陣列', $name);
        } else {
            foreach ($entry['owner'] as $owner) {
                if (!is_string($owner) || !in_array($owner, self::VALID_OWNERS, true)) {
                    $problems[] = sprintf(
                        '%s 的 owner 含不在值域內的值「%s」',
                        $name,
                        is_string($owner) ? $owner : get_debug_type($owner),
                    );
                }
            }
        }

        // fencing 與 note 允許 null（尚未指定／無備註），有值時必須是字串。
        foreach (['fencing', 'note'] as $nullable) {
            if (!array_key_exists($nullable, $entry)) {
                $problems[] = sprintf('%s 缺少 %s', $name, $nullable);
            } elseif ($entry[$nullable] !== null && !is_string($entry[$nullable])) {
                $problems[] = sprintf('%s 的 %s 必須是字串或 null', $name, $nullable);
            }
        }

        return $problems;
    }

    /**
     * 掃描 database/procedures/ 中寫入 total_bet_records 的 SP。
     *
     * ⚠️ 兩點與直覺不同，都是 2026-08-31 實測踩到的：
     *   1. **必須先剝掉註解**。SP_transfer_bet_record_obet33.sql 有一段被區塊註解包起來的
     *      `DELETE FROM total_bet_records`，不剝註解會被算成真的刪除路徑。
     *   2. **不能只掃 INSERT**。今天 73 支全是 INSERT ＋ ON DUPLICATE KEY UPDATE，但守衛的
     *      意義在於「以後有人加了 DELETE／UPDATE 也要被抓到」——只掃 INSERT 就漏了。
     *
     * @return list<string> 檔名（不含 .sql）
     */
    private function scanProceduresWritingTotalBetRecords(): array
    {
        $found = [];

        foreach (glob(self::repoPath('database/procedures/*.sql')) ?: [] as $path) {
            $sql = self::stripSqlComments((string) file_get_contents($path));

            $writes = '/(INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?total_bet_records`?\b/i';

            if (preg_match($writes, $sql) === 1) {
                $found[] = basename($path, '.sql');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 掃描 app/ 底下「同時出現注單彙總表符號與寫入動詞」的檔案。
     *
     * 這是**候選**不是結論——很多檔案的寫入動詞是打在別的表上，只是同檔剛好讀了
     * 彙總表。逐檔覆核後，真寫入的登記到 php 段，只讀的進 NON_WRITER_FILES。
     *
     * ⚠️ 符號必須涵蓋 `new Model`，不能只認 `Model::`。SummaryBetRecordService 就是
     *    `$record = new SummaryBetRecord` 之後 `$record->save()`，2026-08-31 實測時
     *    第一版掃描漏掉它。同理，覆核時要追變數：`$totalBetRecord->delete()` 這種
     *    寫法在 MotivationSW 與 OnebxSW 都有，光看符號那一行看不出來。
     *
     * ⚠️ 掃描範圍只有 app/：database/、routes/、config/ 三處實測皆為 0 個候選。
     *
     * @return list<string> 相對於專案根目錄的路徑
     */
    private function scanPhpWriterCandidates(): array
    {
        $models = '(?:TotalBetRecord|SummaryBetRecord|SummaryBetRecordTeam|EventTotalBetRecord)';
        $tables = '(?:total_bet_records|summary_bet_records|event_total_bet_records|summary_bet_records_team)';

        $symbols = "/{$models}::|new\s+{$models}\b|DB::table\('{$tables}'\)|->from\('{$tables}|table\('{$tables}'\)/";
        $writes = '/->update\(|->insert\(|->insertOrIgnore\(|->upsert\(|->delete\(|->forceDelete\(|->save\(\)'
            . '|updateOrCreate\(|firstOrCreate\(|::upsert\(|::insert\(|::create\(/';

        $root = self::repoPath('');
        $candidates = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repoPath('app')));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            if (preg_match($symbols, $content) === 1 && preg_match($writes, $content) === 1) {
                $candidates[] = str_replace($root, '', $file->getPathname());
            }
        }

        sort($candidates);

        return $candidates;
    }

    /** 剝掉 SQL 的區塊註解與行註解，避免把註解掉的語句當成真的寫入路徑。 */
    private static function stripSqlComments(string $sql): string
    {
        $sql = (string) preg_replace('#/\*.*?\*/#s', '', $sql);

        return (string) preg_replace('/^\s*(#|--).*$/m', '', $sql);
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function registry(): array
    {
        return require self::repoPath('database/data/bet_records/writers.php');
    }

    /**
     * 純 PHPUnit 測試不啟動 Laravel，database_path() 不可用（app() 回傳的 Container
     * 沒有 databasePath()）。比照 tests/Unit/Data/BankRegistryParityTest.php 以
     * __DIR__ 回推專案根目錄。
     */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
