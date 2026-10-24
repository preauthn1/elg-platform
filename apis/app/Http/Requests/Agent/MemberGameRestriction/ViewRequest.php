<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGameRestriction;

use App\Http\Requests\AbstractRequest;

/**
 * 會員遊戲限制單筆詳情 工單4126
 */
class ViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 限制單編號
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
