<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventV2;

use App\Http\Requests\AbstractRequest;

class ListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
        ];
    }
}
