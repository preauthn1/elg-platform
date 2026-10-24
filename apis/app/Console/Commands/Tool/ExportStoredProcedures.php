<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * php artisan db:export-procedures --database=VWorld
 */
class ExportStoredProcedures extends Command
{
    protected $signature = 'db:export-procedures {--output=database/procedures} {--database=mysql}';

    protected $description = 'Export all stored procedures to individual SQL files';

    public function handle()
    {
        $connection = $this->option('database');
        $site = $connection === 'mysql' ? 'Dev' : $connection;
        $database = config("database.connections.{$connection}.database");
        $outputDir = sprintf('%s_%s_%s', $this->option('output'), $site, date('Ymd_His'));

        // 建立輸出目錄
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        // 取得所有 procedure 名稱
        $procedures = DB::connection($connection)->select(
            "SELECT ROUTINE_NAME
             FROM information_schema.ROUTINES
             WHERE ROUTINE_TYPE = 'PROCEDURE'
               AND ROUTINE_SCHEMA = ?
               AND ROUTINE_NAME NOT LIKE 'ADMIN_%'",
            [$database]
        );

        if (empty($procedures)) {
            $this->warn("No procedures found in database: {$database}");

            return 0;
        }

        $this->info('Found ' . count($procedures) . ' procedures. Exporting...');
        $bar = $this->output->createProgressBar(count($procedures));

        foreach ($procedures as $proc) {
            $procName = $proc->ROUTINE_NAME;
            $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $procName);
            $filePath = "{$outputDir}/{$safeName}.sql";

            try {
                // 取得 CREATE PROCEDURE 語句
                $result = DB::connection($connection)->select("SHOW CREATE PROCEDURE `{$procName}`");
                $createSql = $result[0]->{'Create Procedure'};

                // 移除 DEFINER（支援不同引號或無引號情況），並置換為單一空格以避免黏在一起
                $createSql = preg_replace(
                    '/\s*DEFINER\s*=\s*(?:`[^`]+`@`[^`]+`|[^\s;]+)\s*/i',
                    ' ',
                    $createSql
                );

                // 正規化 CREATE PROCEDURE 的多重空白（含換行、tab）
                $createSql = preg_replace('/CREATE\s+PROCEDURE/i', 'CREATE PROCEDURE', $createSql);

                $content = $createSql;

                // 組合完整 SQL（包含 DELIMITER）
                // $content = "/* Procedure: {$procName} */\n";
                // $content .= "DELIMITER $$\n";
                // $content .= "{$createSql}\n";
                // $content .= "$$\n";
                // $content .= "DELIMITER ;\n";

                File::put($filePath, $content);
                $bar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to export {$procName}: " . $e->getMessage());
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Files saved to: {$outputDir}");

        return 0;
    }
}
