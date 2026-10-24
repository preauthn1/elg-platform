<?php

declare(strict_types=1);

namespace App\Http\Requests\External\UUSlotSW;

use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CashBonusRequest extends FormRequest
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
            'OperatorId' => 'required|string|max:20',
            'RequestDateTime' => 'required|string|max:50',
            'Signature' => 'required|string|max:50',
            'PlayerId' => 'required|string|max:50',
            'Currency' => 'required|string|max:10',
            'AuthToken' => 'string|max:500|nullable',

            'TranId' => 'required|string|max:25',
            'BonusId' => 'required|string|max:50',
            'BonusName' => 'required|string|max:50',
            'Payout' => 'required|numeric',
            'ExchangeRate' => 'required|numeric',
            'TranDateTime' => 'required|date_format:Y-m-d H:i:s',

            'ProviderTimeZone' => 'required|string|max:50',
            'ProviderTranDt' => 'required',
            'Result' => 'required|string|max:100',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(
            [
                'Status' => StatusCodeEnum::OPERATOR_INCOMING_REQUEST_INFO_INCOMPLETE,
                'Description' => $validator->errors(),
                'ResponseDateTime' => date('Y-m-d H:i:s'),
            ],
            200
        ));
    }
}
