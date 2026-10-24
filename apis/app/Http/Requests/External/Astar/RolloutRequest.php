<?php

declare(strict_types=1);

namespace App\Http\Requests\External\Astar;

use App\Vendors\Astar\Enums\ErrorCodeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RolloutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gametoken' => 'required|string|size:96|regex:/^[a-fA-F0-9]+$/',
            'id' => 'required|string',
            'mtcode' => 'required|string',
            'round' => 'required|string',
            'amount' => 'required|string',
            'datetime' => 'required|string|date_format:Y-m-d\TH:i:sP',
            'gamecode' => 'required|string',
            'ip' => 'required|string|ip',
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
