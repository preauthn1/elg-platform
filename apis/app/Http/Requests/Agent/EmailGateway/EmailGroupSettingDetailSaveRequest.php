<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Http\Requests\AbstractRequest;

class EmailGroupSettingDetailSaveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 電子郵件設置組別ID(agent_groups.id, agent_group_type=7)
             *
             * @example 4249
             */
            'group_id' => 'required|integer',
            /**
             * @var int 驗證碼有效時間(分鐘), 正整數
             *
             * @example 10
             */
            'token_expired' => 'required|integer|min:1',
            /**
             * @var int 有效時間內最大發送次數, 正整數
             *
             * @example 5
             */
            'max_send' => 'required|integer|min:1',
        ];
    }
}
