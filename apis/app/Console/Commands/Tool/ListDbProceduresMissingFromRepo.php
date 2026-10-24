<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * 列出資料庫已存在、但 database/procedures 目錄內無對應 .sql 檔名的 stored procedure（僅比對名稱）。
 *
 * php artisan db:list-procedures-missing-from-repo
 * php artisan db:list-procedures-missing-from-repo --database=Yes2Win --skip-admin
 * php artisan db:list-procedures-missing-from-repo --skip-admin
 */
class ListDbProceduresMissingFromRepo extends Command
{
    protected $signature = 'db:list-procedures-missing-from-repo
                            {--database=mysql : 資料庫 connection 名稱}
                            {--path=database/procedures : 相對於專案根目錄的 procedure SQL 目錄}
                            {--skip-admin : 略過名稱為 ADMIN_% 的 procedure（與 db:export-procedures 一致）}';

    protected $description = '列出 DB 有但 database/procedures 無對應檔名的 stored procedure（僅名稱比對）';

    public function handle(): int
    {
        $connection = $this->option('database');
        $relativePath = (string) $this->option('path');
        $proceduresDir = base_path($relativePath);

        if (!File::isDirectory($proceduresDir)) {
            $this->error("目錄不存在：{$proceduresDir}");

            return self::FAILURE;
        }

        $database = (string) config("database.connections.{$connection}.database");
        if ($database === '') {
            $this->error("無法取得 connection「{$connection}」的 database 名稱。");

            return self::FAILURE;
        }

        $skipAdmin = (bool) $this->option('skip-admin');
        $adminClause = $skipAdmin ? 'AND ROUTINE_NAME NOT LIKE ?' : '';
        $bindings = ['PROCEDURE', $database];
        if ($skipAdmin) {
            $bindings[] = 'ADMIN_%';
        }

        $rows = DB::connection($connection)->select(
            "SELECT ROUTINE_NAME
             FROM information_schema.ROUTINES
             WHERE ROUTINE_TYPE = ?
               AND ROUTINE_SCHEMA = ?
               {$adminClause}",
            $bindings
        );

        $namesOnDisk = $this->collectProcedureNamesFromDisk(proceduresDir: $proceduresDir);

        $missing = [];
        foreach ($rows as $row) {
            $name = $row->ROUTINE_NAME;
            if (!isset($namesOnDisk[$name])) {
                $missing[] = $name;
            }
        }

        sort($missing);

        $this->info(sprintf(
            'Connection: %s | Schema: %s | SQL 目錄: %s%s',
            $connection,
            $database,
            $relativePath,
            $skipAdmin ? ' | 已略過 ADMIN_%' : ''
        ));
        $this->info(sprintf('DB procedure 總數：%d | 目錄內 .sql 檔數：%d', count($rows), count($namesOnDisk)));
        $this->newLine();

        if ($missing === []) {
            $this->info('沒有缺檔：所有 DB procedure 在目錄內皆有同名 .sql。');

            return self::SUCCESS;
        }

        $this->warn('以下 procedure 在 DB 存在，但目錄內無同名 .sql（名稱需完全一致，含大小寫）：');
        foreach ($missing as $name) {
            $this->line($name);
        }
        $this->newLine();
        $this->info('共 ' . count($missing) . ' 筆');

        return self::SUCCESS;
    }

    /**
     * @return array<string, true> procedure 名稱 => true
     */
    private function collectProcedureNamesFromDisk(string $proceduresDir): array
    {
        $finder = Finder::create()
            ->files()
            ->in($proceduresDir)
            ->name('*.sql')
            ->depth('== 0');

        $names = [];
        foreach ($finder as $file) {
            $base = $file->getBasename('.sql');
            $names[$base] = true;
        }

        return $names;
    }
}
