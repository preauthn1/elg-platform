<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventLuckyDraw;

use App\Http\Requests\AbstractRequest;

class IssueDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 抽獎活動期數ID
             *
             * @example 1
             */
            'issue_id' => 'required|integer',
        ];
    }
}
