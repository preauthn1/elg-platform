<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

use Illuminate\Support\Facades\DB;

class GenerateAgentMenusFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'agent_menus';

    protected array $tableColumns = [
        'id',
        'code',
        'name',
        'parent',
        'url',
        'symbol',
        'action',
        'sort',
        'status',
    ];

    protected function generator(): string
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";

        $rows = DB::table($this->tableName)
            ->select($this->tableColumns)->get();

        // 取得 parent 欄位對應的 code
        $parentMap = [];
        foreach ($rows as $row) {
            $parentMap[$row->id] = $row->code;
        }

        // 將 $rows 的 parent 欄位的 id 轉換為 $parentMap 對應的 code
        $rows->each(function ($row) use ($parentMap) {
            if (isset($parentMap[$row->parent])) {
                $row->parent = $parentMap[$row->parent];
            } else {
                $row->parent = '0'; // 如果找不到對應的 parent，則設為 0
            }
        });

        foreach ($rows as $row) {
            $fields = (array) $row;
            $content .= "    [\n";
            foreach ($fields as $field => $value) {
                // 不需要 id 欄位, 取出是為了轉換 parent
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
