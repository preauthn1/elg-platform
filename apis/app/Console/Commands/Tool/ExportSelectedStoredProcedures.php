<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * php artisan db:export-selected-procedures
 * php artisan db:export-selected-procedures --database=Yes2Win
 */
class ExportSelectedStoredProcedures extends Command
{
    /**
     * @var list<string>
     */
    private const PROCEDURE_NAMES = [
        'ADMIN_get_customized_setting',
    ];

    protected $signature = 'db:export-selected-procedures {--output=database/procedures} {--database=mysql}';

    protected $description = '匯出指定清單的 stored procedure 為個別 SQL 檔（邏輯同 db:export-procedures）';

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $site = $connection === 'mysql' ? 'Dev' : $connection;
        $database = config("database.connections.{$connection}.database");
        $outputDir = sprintf('%s_%s_%s', $this->option('output'), $site, date('Ymd_His'));

        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $existing = $this->fetchExistingProcedureNames(connection: $connection, database: (string) $database);
        $toExport = [];
        foreach (self::PROCEDURE_NAMES as $name) {
            if (isset($existing[$name])) {
                $toExport[] = $name;
            } else {
                $this->warn("資料庫內找不到 procedure：{$name}");
            }
        }

        if ($toExport === []) {
            $this->error('沒有任何清單中的 procedure 可匯出。');

            return self::FAILURE;
        }

        $this->info('將匯出 ' . count($toExport) . ' 個 procedure 至：' . $outputDir);
        $bar = $this->output->createProgressBar(count($toExport));

        foreach ($toExport as $procName) {
            $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $procName);
            $filePath = "{$outputDir}/{$safeName}.sql";

            try {
                $result = DB::connection($connection)->select('SHOW CREATE PROCEDURE `' . str_replace('`', '``', $procName) . '`');
                $createSql = $result[0]->{'Create Procedure'};

                $createSql = preg_replace(
                    '/\s*DEFINER\s*=\s*(?:`[^`]+`@`[^`]+`|[^\s;]+)\s*/i',
                    ' ',
                    $createSql
                );

                $createSql = preg_replace('/CREATE\s+PROCEDURE/i', 'CREATE PROCEDURE', $createSql);

                File::put($filePath, $createSql);
            } catch (Throwable $e) {
                $this->newLine();
                $this->error("匯出失敗 {$procName}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("完成。檔案目錄：{$outputDir}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function fetchExistingProcedureNames(string $connection, string $database): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT ROUTINE_NAME
             FROM information_schema.ROUTINES
             WHERE ROUTINE_TYPE = 'PROCEDURE'
               AND ROUTINE_SCHEMA = ?",
            [$database]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->ROUTINE_NAME] = true;
        }

        return $map;
    }
}
