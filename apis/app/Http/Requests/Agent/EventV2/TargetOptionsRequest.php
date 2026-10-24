<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class TargetOptionsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 活動的代理商帳號（agents.id）；選項只列這條線裡的會員／群組
             *
             * @example 4249
             **/
            'agent_id' => 'required|integer',
            /**
             * @var int 目標用戶類型(1:指定會員 2:會員群組)，見 MemberRestrictionTargetEnum
             *
             * @example 2
             **/
            'target_type' => 'required|integer|in:1,2',
            /**
             * @var string|null 會員帳號關鍵字（前綴比對）；指定會員時必填才會回資料，群組不看
             *
             * @example "max07"
             **/
            'keyword' => 'nullable|string|max:50',
        ];
    }
}
