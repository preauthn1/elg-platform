<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MotivationSW;

use App\Http\Requests\AbstractRequest;

class EndroundRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'account' => 'required|string|max:36',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'data' => 'required|json',
            'data.*.mtcode' => 'required|string|max:70',
            'data.*.amount' => 'required|numeric|min:0',
            'data.*.eventtime' => 'required|string|max:35',
            'data.*.validbet' => 'required|numeric|min:0',
            'createTime' => 'required|string|max:35',
            'freegame' => 'nullable|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
            'luckydraw' => 'nullable|numeric|min:0',
            'jackpot' => 'nullable|numeric|min:0',
            'jackpotcontribution' => 'nullable|array',
            'freeticket' => 'nullable|boolean',
        ];
    }
}
