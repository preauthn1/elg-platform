<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\DomainManage;

use App\Http\Requests\AbstractRequest;

class ViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 域名編號
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
