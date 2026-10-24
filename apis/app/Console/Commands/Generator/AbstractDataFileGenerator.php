<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class AbstractDataFileGenerator extends Command
{
    protected $signature;

    protected $description;

    protected string $tableName;

    protected array $tableColumns = [];

    public function __construct()
    {
        $this->signature = 'generator:' . $this->tableName;
        $this->description = '根據 ' . $this->tableName . ' 資料表產生 database/data/' . $this->tableName . '.php 檔案';
        parent::__construct();
    }

    public function handle(): void
    {
        $content = $this->generator();
        $filePath = database_path('data/' . $this->tableName . '.php');
        file_put_contents($filePath, $content);
        $this->info($filePath . ' 檔案已產生');
    }

    /**
     * 統一 generator 實作，動態取得欄位
     */
    protected function generator(): string
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";

        // 若 $tableColumns 未設定則自動取得所有欄位
        $columns = $this->tableColumns;
        if (empty($columns)) {
            $columns = Schema::getColumnListing($this->tableName);
        }

        $rows = DB::table($this->tableName)->select($columns)->get();

        foreach ($rows as $row) {
            $fields = (array) $row;
            $content .= "    [\n";
            foreach ($fields as $field => $value) {
                $escapedValue = var_export($value, true);
                $content .= "        '{$field}' => {$escapedValue},\n";
            }
            $content .= "    ],\n";
        }
        $content .= "];\n";

        return $content;
    }
}
