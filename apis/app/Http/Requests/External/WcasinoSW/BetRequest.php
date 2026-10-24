<?php

declare(strict_types=1);

namespace App\Http\Requests\External\WcasinoSW;

use Illuminate\Foundation\Http\FormRequest;

class BetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'appid' => 'required|string',
            'username' => 'required|string',
            'amount' => 'required|numeric|max:0', // negative
            'notifyid' => 'required|string',
            'type' => 'required|integer|in:1',
            'serialnumber' => 'required|string',
            'sign' => 'required|string',
            'bets' => 'json',
        ];
    }
}
