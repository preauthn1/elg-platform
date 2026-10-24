<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

/** 代理後台會員稽核流水列表（target/tickets#4438，由 API_Agent member/flowLimit 移植） */
class MemberFlowLimitListRequest extends AbstractRequest
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
             * @var int 頁數
             *
             * @example 1
             */
            'page' => 'sometimes|integer|min:1',
            /**
             * @var int 單頁筆數
             *
             * @example 20
             */
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
