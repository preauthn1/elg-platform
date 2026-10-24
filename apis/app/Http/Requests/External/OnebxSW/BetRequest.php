<?php

declare(strict_types=1);

namespace App\Http\Requests\External\OnebxSW;

use App\Http\Requests\AbstractRequest;

class BetRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'bettranstion_id' => 'required|string',
            'username' => 'required|string',
            'match_play' => 'required|array',
            'choose_bet' => 'required|string',
            'price' => 'required|string',
            'amount_bet' => 'required|numeric',
            'deduct_amount' => 'required|numeric',
            'tor_side' => 'required|string',
            'round' => 'required|string',
            'sport_id' => 'required|numeric',
            'sport_name' => 'required|string',
            'price_type' => 'required|string',
            'timestamp' => [
                'required',
                function ($attribute, $value, $fail) {
                    return is_string($value) && is_numeric($value);
                },
            ],
            'signature' => 'required|string',
        ];
    }
}
