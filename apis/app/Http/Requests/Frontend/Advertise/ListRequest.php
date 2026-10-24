<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Advertise;

use App\Http\Requests\AbstractRequest;

class ListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'domain' => 'required|string',
            'path_id' => 'required|digits_between:0,10',
        ];
    }
}
