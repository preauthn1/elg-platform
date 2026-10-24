<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 驗證 `database/procedures` 內每個 `.sql` 對應的 stored procedure 皆存在於目前連線資料庫（可多不可少）。
 *
 * 與 {@see \Database\Seeders\SyncProcedureSeeder} 相同：僅掃描目錄頂層、副檔名不分大小寫比對為 `sql`。
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/ProcedureDirectorySyncedWithDatabaseTest.php --compact
 * ```
 */
class ProcedureDirectorySyncedWithDatabaseTest extends TestCase
{
    private const string SEED_HINT = 'php artisan db:seed --class=SyncProcedureSeeder --env=testing';

    #[Test]
    public function every_procedure_sql_file_has_matching_database_routine(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需 MySQL 的 information_schema.ROUTINES（stored procedures）。');
        }

        $procedurePath = database_path('procedures');
        $this->assertDirectoryExists($procedurePath);

        $expectedNames = [];
        foreach (File::files($procedurePath) as $file) {
            if (strtolower($file->getExtension()) !== 'sql') {
                continue;
            }

            $expectedNames[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }

        $this->assertNotEmpty(
            $expectedNames,
            sprintf('目錄 `%s` 內應至少有一個 `.sql` procedure 檔。', $procedurePath)
        );

        $rows = DB::select(
            'SELECT ROUTINE_NAME FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = ?',
            ['PROCEDURE']
        );

        $dbNames = [];
        foreach ($rows as $row) {
            $dbNames[(string) $row->ROUTINE_NAME] = true;
        }

        $missing = [];
        foreach ($expectedNames as $name) {
            if (!isset($dbNames[$name])) {
                $missing[] = $name;
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "資料庫缺少下列 stored procedure（對應 `database/procedures` 內檔案），請執行：\n%s\n\n缺少：\n%s",
                self::SEED_HINT,
                implode("\n", $missing)
            )
        );
    }
}
