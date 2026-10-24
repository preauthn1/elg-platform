<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberRestriction;

use App\Http\Requests\AbstractRequest;

class UpdateRequest extends AbstractRequest
{
    /**
     * 限制目標（type/username/member_group_id）建立後不可變，僅允許改以下欄位。
     */
    public function rules(): array
    {
        return [
            /**
             * @var int 規則編號
             *
             * @example 1
             */
            'id' => 'required|integer',
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
