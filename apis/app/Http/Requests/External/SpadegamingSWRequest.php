<?php

declare(strict_types=1);

namespace App\Http\Requests\External;

use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SpadegamingSWRequest extends FormRequest
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
            'serialNo' => 'required|string|max:50',
            'merchantCode' => 'required|string|max:10',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'ret' => ReturnStatusEnum::INVALID_REQUEST->value,
            'data' => $validator->errors(),
        ], 200));
    }
}
