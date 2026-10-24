<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use Illuminate\Support\Facades\DB;

class GenerateAgentSettingDefinesFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'agent_setting_defines';

    protected array $tableColumns = [
        'setting_id',
        'parent',
        'type',
        'is_visible',
        'agent_edit',
        'memo',
        'language_text_code',
        'default_value',
        'data_type',
        'is_data_required',
        'enable_agents',
        'sort',
    ];

    protected function generator(): string
    {
        /**
         * 取得 enum 常數 value=>name 對應表
         * [
         *      2000 => 'CS_SETTING',
         *      2001 => 'CS_IS_CUSTOM_SERVICE_ICON_VISIBLE',
         *      ...
         * ]
         */
        $enumClass = new \ReflectionClass(AgentCustomizedSettingKeyEnum::class);
        $enumConstants = $enumClass->getConstants();
        $valueToName = array_flip($enumConstants);

        $content = "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Enums\\Agent\\AgentCustomizedSettingKeyEnum;\n\nreturn [\n";

        $rows = DB::table($this->tableName)
            ->select($this->tableColumns)->get();

        foreach ($rows as $row) {
            $fields = (array) $row;
            $keyValue = $row->setting_id ?? null;
            $enumKey = isset($valueToName[$keyValue]) ? "AgentCustomizedSettingKeyEnum::{$valueToName[$keyValue]}" : var_export($keyValue, true);
            $content .= "    {$enumKey} => [\n";
            foreach ($fields as $field => $value) {
                if ($field === 'setting_id') {
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
