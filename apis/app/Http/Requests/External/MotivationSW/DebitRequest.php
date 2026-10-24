<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MotivationSW;

use App\Http\Requests\AbstractRequest;

class DebitRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
        ];
    }
}
