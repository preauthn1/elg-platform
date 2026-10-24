<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 站內信表單的會員遠端搜尋 工單4152
 */
class MemberListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 會員帳號關鍵字（必填，未輸入不查）
             *
             * @example "max91"
             */
            'keyword' => 'required|string|min:1|max:50',
            /**
             * @var int 回傳筆數上限
             *
             * @example 50
             */
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }
}
