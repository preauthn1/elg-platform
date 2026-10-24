<?php

declare(strict_types=1);

namespace App\Http\Requests\External\VplusSW;

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
            'userId' => 'required|integer',
            'username' => 'required|string',
            'transactionId' => 'required|string|size:32',
            'gameId' => 'required|integer',
            'gameRoundId' => 'required|string',
            'completed' => 'required|integer|in:0,1',
            'timestamp' => 'required|integer',
            'balance' => 'required|string',
            'type' => 'required|integer|in:0',
        ];
    }
}
