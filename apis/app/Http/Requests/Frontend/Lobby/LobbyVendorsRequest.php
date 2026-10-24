<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Lobby;

use App\Http\Requests\AbstractRequest;

class LobbyVendorsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'domain' => 'required|string',
            'vendor_ids' => 'array',
            'vendor_ids.*' => 'required|integer',
            'type_id' => 'nullable|integer', // 遊戲類型
        ];
    }
}
