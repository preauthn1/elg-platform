<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberDetail;

use App\Http\Requests\AbstractRequest;

/** 抽屜區塊接口共用：只收 member_id（username 開啟時由 view 接口先行解析） */
class MemberDetailMemberIdRequest extends AbstractRequest
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
        ];
    }
}
