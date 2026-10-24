<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberFieldSetting;

use App\Enums\MemberFieldPageTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class MemberFieldSettingFieldsUpdateRequest extends AbstractRequest
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
             * @var int 頁面 1:註冊 2:登入 3:實名驗證
             *
             * @example 1
             */
            'page_type' => ['required', 'integer', Rule::enum(MemberFieldPageTypeEnum::class)],
            /**
             * 欄位設定；送來的列 upsert，沒送的列不動。
             * 註冊頁玩家帳號關閉時，帳號前綴放在玩家帳號那一列的 extra_config.username_prefix。
             *
             * @var array<int, array{field_key:string, field_group:int, sort:int, is_required:int, status:int, extra_config:array|null}>
             */
            'fields' => 'required|array|min:1',
            'fields.*' => 'array',
            'fields.*.field_key' => 'required|string',
            'fields.*.extra_config' => 'nullable|array',
        ];
    }
}
