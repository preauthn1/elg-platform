<?php

declare(strict_types=1);

namespace App\Console\Commands\Copy;

/**
 * 複製 SMS 文本數據命令
 *
 * 此命令用於將 SMS 文本數據從一個群組 ID 複製到另一個群組 ID。
 * 用法：php artisan copy:sms_texts {from_group_id} {to_group_id}
 * 用法：php artisan copy:sms_texts 7 1 -t
 */
class CopySmsTexts extends AbstractCopyCommand
{
    protected $signature = 'copy:sms_texts {from_group_id} {to_group_id}  {--t|test : 測試模式，所有操作不會寫入資料庫}';

    protected $description = '複製 SMS 文本數據從一個群組 ID 到另一個';

    /**
     * 獲取表名
     */
    protected function getTableName(): string
    {
        return 'sms_texts';
    }

    /**
     * 獲取用於區分來源和目標的字段名
     */
    protected function getGroupField(): string
    {
        return 'sms_gateway_group_id';
    }

    /**
     * 獲取需要複製的字段列表
     */
    protected function getFieldsToCopy(): array
    {
        return [
            'agent_id',
            'sms_gateway_group_id',
            'type_id',
            'country_id',
            'text',
            'status',
            'created_at',
            'updated_at',
            'admin',
        ];
    }

    protected function getFieldOverrides(): array
    {
        return [
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            'status' => 0,
        ];
    }
}
