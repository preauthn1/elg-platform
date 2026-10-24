<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

use App\Http\Requests\AbstractRequest;

class ViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 群組編號
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
