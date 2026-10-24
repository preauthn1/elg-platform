<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Checkin;

use App\Http\Requests\AbstractRequest;

class CheckinUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 打卡活動ID
             *
             * @example 16
             */
            'id' => 'required|integer',
            /**
             * @var int 代理ID
             *
             * @example 4249
             */
            'agent_id' => 'required|integer',

            /**
             * @var int 稽核流水倍數
             *
             * @example 5
             */
            'flow_limit_multiple' => 'required|integer',

            /**
             * @var int 阻擋重複IP
             *
             * @example 1
             **/
            'ip_block' => 'integer',

            /**
             * @var int 阻擋重複手機號
             *
             * @example 0
             **/
            'phone_block' => 'integer',
            /**
             * @var int 阻擋重複真實姓名
             *
             * @example 1
             **/
            'account_block' => 'integer',

            /**
             * @var string 活動開始日期（格式：Y-m-d）
             *
             * @example "2025-06-01"
             */
            'start_date' => 'required|date',

            /**
             * @var string 活動結束日期（必須晚於 start_date）
             *
             * @example "2025-06-30"
             */
            'end_date' => 'required|after:start_date',

            /**
             * @var array 活動獎勵天數
             *
             * @example [1, 2, 3, 4, 5, 6, 7]
             */
            'day' => 'required|array',

            /**
             * @var array 獎勵類型（1=平台點數，2=洗碼量）
             *
             * @example [1, 2]
             */
            'prize_type' => 'required|array',

            /**
             * @var array 對應的獎勵金額
             *
             * @example [100, 200]
             */
            'prize_amount' => 'required|array',
        ];
    }
}
