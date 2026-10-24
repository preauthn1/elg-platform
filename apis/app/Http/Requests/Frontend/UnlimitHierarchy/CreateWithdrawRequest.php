<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\UnlimitHierarchy;

use App\Http\Requests\AbstractRequest;

class CreateWithdrawRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => __('error_message.2002'),
            'amount.numeric' => __('error_message.2002'),
            'amount.min' => __('error_message.2002'),
        ];
    }
}
