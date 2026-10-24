<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\PlatformAnnouncement;

use App\Http\Requests\AbstractRequest;

class ListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'domain' => 'required|string',
            'page' => 'nullable|numeric',
            'per_page' => 'nullable|numeric',
        ];
    }
}
