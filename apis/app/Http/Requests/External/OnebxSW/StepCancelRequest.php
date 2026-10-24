<?php

declare(strict_types=1);

namespace App\Http\Requests\External\OnebxSW;

use App\Http\Requests\AbstractRequest;

class StepCancelRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'item' => 'required|json',

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
