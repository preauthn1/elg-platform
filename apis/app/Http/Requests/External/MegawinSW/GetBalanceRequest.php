<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MegawinSW;

use App\Vendors\MegawinSW\Enums\ErrorCodeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GetBalanceRequest extends FormRequest
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
            'uid' => 'required|string|max:32',
            'utoken' => 'required|string|size:32',
            'siteId' => 'required|numeric',
            'merchantId' => 'required|numeric',
            'firmId' => 'required|numeric',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'ret' => ErrorCodeEnum::PARAM_ERROR,
            'data' => $validator->errors(),
        ], 200));
    }
}
