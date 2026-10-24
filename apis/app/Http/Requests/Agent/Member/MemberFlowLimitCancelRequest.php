<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

/**
 * 代理後台取消會員稽核流水（target/tickets#4438，由 API_Agent member/cancel_flow_limit 移植）。
 * 單筆帶 id；取消該會員全部未通過帶 member_id（兩者擇一）。
 */
class MemberFlowLimitCancelRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 稽核流水編號（取消單筆）
             *
             * @example 987
             */
            'id' => 'required_without:member_id|prohibits:member_id|integer|min:1',
            /**
             * @var int 會員系統編號（取消該會員全部未通過）
             *
             * @example 12345
             */
            'member_id' => 'required_without:id|integer|min:1',
            /**
             * @var string 備註
             *
             * @example 客服人工解除
             */
            'remark' => 'nullable|string|max:100',
        ];
    }
}
