<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberRestriction;

use App\Http\Requests\AbstractRequest;

class StoreRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 限制類型 1:会员管理(單一會員) 2:会员群组
             *
             * @example 1
             */
            'type' => 'required|in:1,2',
            /**
             * @var string 會員帳號（type=1 必填）
             *
             * @example "demo001"
             */
            'username' => 'required_if:type,1|nullable|string',
            /**
             * @var int 會員群組編號（type=2 必填）
             *
             * @example 3
             */
            'member_group_id' => 'required_if:type,2|nullable|integer',
            /**
             * @var string 內部備註
             *
             * @example "疑似套利"
             */
            'remark' => 'nullable|string|max:191',
            /**
             * @var int 規則總開關 1:啟用 0:關閉
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
            /**
             * @var int 是否停用（1:禁止登入）
             *
             * @example 0
             */
            'is_disabled' => 'required|in:0,1',
            /**
             * @var int 提款鎖定（1:禁止申請提現）
             *
             * @example 0
             */
            'withdrawal_locked' => 'required|in:0,1',
        ];
    }
}
