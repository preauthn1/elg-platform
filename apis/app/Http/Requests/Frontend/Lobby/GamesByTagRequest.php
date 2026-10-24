<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Lobby;

use App\Http\Requests\AbstractRequest;

class GamesByTagRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'domain' => 'required|string',
            'tag' => 'required|string',
        ];
    }
}
