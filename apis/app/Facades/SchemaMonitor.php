<?php

declare(strict_types=1);

namespace App\Facades;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Prompts\Output\ConsoleOutput;

class SchemaMonitor extends Schema
{
    /**
     * 是否免列數檢查。名單與加表條件見 config/custom.php 的
     * schema_monitor.row_count_exempt_tables。
     *
     * 只有 table() 的 ALTER 會問這裡；dropIfExists() 刻意不吃這份白名單：
     * DROP TABLE 一張大稽核表是不可逆的，「表太大」正是該停下來讓人確認的訊號。
     */
    protected static function isRowCountExempt(string $table): bool
    {
        $exemptTables = (array) config('custom.schema_monitor.row_count_exempt_tables', []);

        return in_array($table, $exemptTables, true);
    }

    protected static function checkRowCount(string $table, int $maxRows, ConsoleOutput $output): bool
    {
        try {
            $rowCount = DB::table($table)->count();
            $output->writeln("資料表 {$table} 目前有 {$rowCount} 筆資料");

            if ($rowCount > $maxRows) {
                $output->writeln("資料表 {$table} 的資料筆數超過 {$maxRows}，操作已取消");
                BotNotify::channel('warn')->send("資料表 {$table} 的資料筆數超過 {$maxRows}，請人工處理");

                return false;
            }

            return true;
        } catch (\Exception $e) {
            $output->writeln("檢查表 {$table} 資料筆數時發生錯誤: {$e->getMessage()}");
            BotNotify::channel('warn')->send("檢查表 {$table} 資料筆數時發生錯誤: {$e->getMessage()}");

            return false;
        }
    }

    public static function create(string $table, Closure $callback): void
    {
        $output = new ConsoleOutput;

        if (Schema::hasTable($table)) {
            $output->writeln("資料表 {$table} 已存在，操作已取消");
            BotNotify::channel('warn')->send("資料表 {$table} 已存在，操作已取消");

            return;
        }

        try {
            Schema::create($table, $callback);
            $output->writeln("資料表 {$table} 已成功建立");
        } catch (\Exception $e) {
            $output->writeln("建立資料表 {$table} 時發生錯誤: {$e->getMessage()}");
            BotNotify::channel('warn')->send("建立資料表 {$table} 時發生錯誤: {$e->getMessage()}");
            throw $e;
        }
    }

    public static function table(string $table, Closure $callback, int $maxRows = 500000): void
    {
        $output = new ConsoleOutput;

        if (app()->isProduction()) {
            if (self::isRowCountExempt($table)) {
                $output->writeln("資料表 {$table} 在列數檢查白名單中，略過筆數檢查");
            } elseif (!self::checkRowCount($table, $maxRows, $output)) {
                return;
            }
        }

        try {
            Schema::table($table, $callback);
            $output->writeln("資料表 {$table} 結構更新完成");
        } catch (\Exception $e) {
            $output->writeln("更新資料表 {$table} 結構時發生錯誤: {$e->getMessage()}");
            BotNotify::channel('warn')->send("更新資料表 {$table} 結構時發生錯誤: {$e->getMessage()}");
            throw $e;
        }
    }

    public static function dropIfExists(string $table, int $maxRows = 500000): void
    {
        $output = new ConsoleOutput;

        if (app()->isProduction() && !self::checkRowCount($table, $maxRows, $output)) {
            return;
        }

        try {
            Schema::dropIfExists($table);
            $output->writeln("資料表 {$table} 已刪除");
        } catch (\Exception $e) {
            $output->writeln("刪除資料表 {$table} 時發生錯誤: {$e->getMessage()}");
            BotNotify::channel('warn')->send("刪除資料表 {$table} 時發生錯誤: {$e->getMessage()}");
            throw $e;
        }
    }
}
