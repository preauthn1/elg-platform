<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\SystemNotify;

use App\Http\Requests\AbstractRequest;

class DetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'data_name' => 'required',
            'data_id' => 'required|numeric',
            'type' => 'required|numeric',
            'page' => 'required|numeric',
            'per_page' => 'required|numeric',
        ];
    }
}
