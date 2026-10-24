<?php

declare(strict_types=1);

namespace App\Http\Requests\External\OnebxSW;

use App\Http\Requests\AbstractRequest;

class RollbackRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'bettranstion_id' => 'required|string',
            'username' => 'required|string',
            'choose_bet' => 'required|string',
            'tor_side' => 'required|string',
            'price' => 'required|string',
            'amount_bet' => 'required|numeric',
            'credit_amount' => 'required|numeric',
            'round' => 'required|string',
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
