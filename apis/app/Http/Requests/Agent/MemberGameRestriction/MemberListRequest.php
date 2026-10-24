<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGameRestriction;

use App\Http\Requests\AbstractRequest;

/**
 * 會員遊戲限制－會員遠端搜尋 工單4126
 *
 * 表單的會員選擇為遠端搜尋下拉：下線動輒數萬筆，未輸入關鍵字不查、且限量回傳。
 */
class MemberListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 會員帳號關鍵字（模糊比對）
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
