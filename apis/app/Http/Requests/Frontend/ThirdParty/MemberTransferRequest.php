<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ThirdParty;

use Illuminate\Foundation\Http\FormRequest;

class MemberTransferRequest extends FormRequest
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
            'operator' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'tax' => 'required|numeric|min:0',
        ];
    }
}
