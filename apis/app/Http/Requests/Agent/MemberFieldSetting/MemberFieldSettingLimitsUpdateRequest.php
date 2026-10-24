<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberFieldSetting;

use App\Http\Requests\AbstractRequest;

class MemberFieldSettingLimitsUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員欄位設置組別ID(agent_groups.id, agent_group_type=6)
             *
             * @example 166
             */
            'group_id' => 'required|integer',
            /**
             * 註冊限制；主檔 upsert，自訂逐值整組重建
             *
             * @var array<int, array{field_key:string, platform_enabled:int, platform_max_limit:int|null, custom_enabled:int, custom_values:array<int, array{value:string, max_limit:int}>}>
             */
            'limits' => 'required|array|min:1',
            'limits.*' => 'array',
            'limits.*.field_key' => 'required|string',
            'limits.*.custom_values' => 'nullable|array',
        ];
    }
}
