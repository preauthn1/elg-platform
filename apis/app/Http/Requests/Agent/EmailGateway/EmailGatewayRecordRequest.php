<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Enums\EmailSendStatusEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class EmailGatewayRecordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 發送編號(email_send_records.id)
             *
             * @example 1
             */
            'send_id' => 'nullable|integer',
            /**
             * @var int 電子郵件群組ID(agent_groups.id)
             *
             * @example 141
             */
            'agent_group_id' => 'nullable|integer',
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             */
            'agent_username' => 'nullable|string',
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'member_username' => 'nullable|string',
            /**
             * @var string 收件信箱(前綴模糊查詢)
             *
             * @example "user@example.invalid"
             */
            'receiver' => 'nullable|string',
            /**
             * @var int 發送狀態(0:未發送, 1:發送成功, 2:發送失敗)
             *
             * @example 1
             */
            'status' => ['nullable', 'integer', Rule::enum(EmailSendStatusEnum::class)],
            /**
             * @var string 開始時間
             *
             * @example "2026-07-29 00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string 結束時間
             *
             * @example "2026-07-29 23:59"
             */
            'end_time' => 'nullable|string',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 20
             */
            'per_page' => 'required|integer',
        ];
    }
}
