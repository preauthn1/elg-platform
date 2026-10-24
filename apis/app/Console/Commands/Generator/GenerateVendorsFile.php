<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateVendorsFile extends Command
{
    protected string $tableName = 'vendors';

    protected $signature;

    protected $description;

    public function __construct()
    {
        $this->signature = 'generator:' . $this->tableName;
        $this->description = '根據 ' . $this->tableName . ' 資料表產生 database/data/' . $this->tableName . '.php 檔案';
        parent::__construct();
    }

    public function handle(): void
    {
        $content = $this->generator();

        file_put_contents(database_path('data/' . $this->tableName . '.php'), $content);

        $this->info($this->tableName . '.php 檔案已產生');
    }

    protected function generator(): string
    {
        // 取得 enum 常數 value=>name 對應表
        $enumConstants = VendorIdEnum::cases();
        $valueToName = [];
        foreach ($enumConstants as $case) {
            $valueToName[$case->value] = $case->name;
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Enums\\Vendor\\VendorIdEnum;\n\nreturn [\n";

        $rows = DB::table($this->tableName)
            ->select([
                'id',
                'name',
                'name',
                'code',
                'tname',
                'third_party_code',
                'open_window',
                'image',
                'app_icon',
            ])->get();

        foreach ($rows as $row) {
            $fields = (array) $row;
            $keyValue = $row->id ?? null;
            $enumKey = isset($valueToName[$keyValue]) ? "VendorIdEnum::{$valueToName[$keyValue]}->value" : var_export($keyValue, true);
            $content .= "    {$enumKey} => [\n";
            foreach ($fields as $field => $value) {
                if ($field === 'id') {
                    continue;
                }
                $escapedValue = var_export($value, true);
                $content .= "        '{$field}' => {$escapedValue},\n";
            }
            $content .= "    ],\n";
        }
        $content .= "];\n";

        return $content;
    }
}
