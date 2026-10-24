<?php

declare(strict_types=1);

namespace App\Http\Requests\External\WcasinoSW;

use Illuminate\Foundation\Http\FormRequest;

class RollbackRequest extends FormRequest
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
            'amount' => 'required|numeric',
            'notifyid' => 'required|string',
            'type' => 'required|integer',
            'serialnumber' => 'required|string',
            'sign' => 'required|string',
        ];
    }
}
