<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Enums\MemberStatusEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

/**
 * 代理後台切換會員狀態（target/tickets#4438，由 API_Agent member/edit_status 移植）。
 * 備註照舊後台後端規則選填；新後台前端強制要填。
 */
class MemberStatusUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => 'required|integer|min:1',
            /**
             * @var int 目標狀態（0:停用 1:啟用 2:鎖定），與現值相同會被擋下
             *
             * @example 0
             */
            'status' => ['required', 'integer', Rule::enum(MemberStatusEnum::class)],
            /**
             * @var string|null 備註，寫進狀態紀錄
             *
             * @example "風控停用"
             */
            'remark' => 'nullable|string|max:500',
        ];
    }
}
