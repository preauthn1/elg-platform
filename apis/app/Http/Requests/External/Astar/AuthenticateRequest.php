<?php

declare(strict_types=1);

namespace App\Http\Requests\External\Astar;

use App\Vendors\Astar\Enums\ErrorCodeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AuthenticateRequest extends FormRequest
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
            'gametoken' => 'required|string|size:96|regex:/^[a-fA-F0-9]+$/',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => [
                'code' => ErrorCodeEnum::BAD_PARAMETERS->value,
                'message' => 'Invalid parameters',
                'datetime' => now()->tz('-04:00')->toRfc3339String(),
                'tracecode' => '',
            ],
            'data' => $validator->errors(),
        ], 200));
    }
}
