<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;

/**
 * 比對 TPower 與 Yes2Win（或其他 connection）之 shareholder 相關資料表欄位有無差異。
 *
 * php artisan db:compare-shareholder-schema
 * php artisan db:compare-shareholder-schema TPower Yes2Win
 * php artisan db:compare-shareholder-schema TPower Yes2Win --include-profit-share
 */
class CompareShareholderSchemaCommand extends Command
{
    protected $signature = 'db:compare-shareholder-schema
                            {from=TPower : 來源 connection 名稱（config/database.php）}
                            {to=Yes2Win : 目標 connection 名稱（config/database.php）}
                            {--include-profit-share : 一併比對 profit_share 相關資料表}';

    protected $description = '比對兩個 MySQL 資料庫 shareholder 相關資料表欄位有無差異（不比較欄位順序與型別定義）';

    public function handle(): int
    {
        $tablesLike = $this->option('include-profit-share')
            ? 'shareholder,profit_share'
            : 'shareholder';

        $this->line('<comment>比對範圍：表名含 ' . str_replace(',', ' 或 ', $tablesLike) . '</comment>');
        $this->newLine();

        return $this->call('db:compare-schema', [
            'from' => (string) $this->argument('from'),
            'to' => (string) $this->argument('to'),
            '--only-column-presence' => true,
            '--tables-like' => $tablesLike,
        ]);
    }
}
