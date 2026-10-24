<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Http\Requests\AbstractRequest;

class EmailGroupSettingViewRequest extends AbstractRequest
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
        ];
    }
}
