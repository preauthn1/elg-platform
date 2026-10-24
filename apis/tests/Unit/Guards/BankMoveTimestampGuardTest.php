<?php

declare(strict_types=1);

namespace Tests\Unit\Guards;

use Tests\TestCase;

/**
 * 原始碼守衛：銀行搬移類寫入不得蓋掉 FK 表的 `updated_at`
 *
 * 這幾張表的 `updated_at` 帶 `on update CURRENT_TIMESTAMP`，只寫業務欄位會被 MySQL
 * 一併蓋成執行時間。`withdrawal_orders.updated_at` 是前端訂單排序欄，2026-08-27 因此
 * 造成生產訂單順序混亂（六站 2,927 筆）。修法是把欄位陣列包一層
 * `PreservesRowTimestamps::preservingUpdatedAt()`。
 *
 * 本測試掃原始碼而非跑查詢：新增寫入點的人不會想到要跑哪支整合測試，但這支會直接紅。
 *
 * ⚠️ 掃不到動態表名（如 `DB::table($table)`）——`RenameBankCommand::repointTable()`
 * 屬此類，已人工套用；新增同類寫法需自行確認。
 */
class BankMoveTimestampGuardTest extends TestCase
{
    /** `updated_at` 帶 on update、且會被銀行搬移改指的表 */
    private const GUARDED_TABLES = [
        'withdrawal_orders',
        'member_bank_accounts',
        'realname_validation_requests',
        'agent_bank_accounts',
        'agent_real_name_validation_requests',
    ];

    /**
     * 只掃銀行收斂／正名相關目錄：業務路徑的 update 本來就該更新 updated_at。
     *
     * @var list<string>
     */
    private const SCANNED_PATHS = [
        'app/Services/CashFlow/Bank',
        'app/Console/Commands/CashFlow/Bank',
        'app/Console/Commands/CashFlow/Guard',
    ];

    public function test_bank_move_writes_preserve_updated_at(): void
    {
        $violations = [];
        $scanned = 0;

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::GUARDED_TABLES as $table) {
                foreach ($this->updateStatementsFor($source, $table) as $line => $statement) {
                    $scanned++;

                    if (str_contains($statement, 'preservingUpdatedAt')) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d 對 %s 的 update 未經 preservingUpdatedAt()',
                        str_replace(base_path() . '/', '', $file),
                        $line,
                        $table,
                    );
                }
            }
        }

        // 目錄搬移或正則失效會讓掃描結果變空，那時本測試會假綠——下限守住這件事。
        // 2026-08-27 實測命中 10 個敘述（BankActiveDedupService 3／DedupRollback 4／
        // MergeWintotoDriftBanks 2／RenameBankCommand 1）。
        $this->assertGreaterThanOrEqual(10, $scanned, '掃描命中數異常偏低，正則或掃描路徑可能已失效');

        $this->assertSame([], $violations, "銀行搬移類寫入必須保留 updated_at：\n" . implode("\n", $violations));
    }

    /**
     * 每個 `DB::table('<表>')` 起算到該敘述結尾（`;`）的片段，只回含 `->update(` 者。
     *
     * @return array<int, string> 行號 => 敘述片段
     */
    private function updateStatementsFor(string $source, string $table): array
    {
        $needle = sprintf("DB::table('%s')", $table);
        $found = [];
        $offset = 0;

        while (($pos = strpos($source, $needle, $offset)) !== false) {
            $offset = $pos + strlen($needle);
            $end = strpos($source, ';', $pos);

            if ($end === false) {
                continue;
            }

            $statement = substr($source, $pos, $end - $pos);

            if (!str_contains($statement, '->update(')) {
                continue;
            }

            $found[substr_count(substr($source, 0, $pos), "\n") + 1] = $statement;
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SCANNED_PATHS as $path) {
            $dir = base_path($path);

            if (!is_dir($dir)) {
                continue;
            }

            /** @var \SplFileInfo $entry */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
