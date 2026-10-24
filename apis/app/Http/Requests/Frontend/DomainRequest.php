<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend;

use App\Http\Requests\AbstractRequest;

class DomainRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
        ];
    }
}
