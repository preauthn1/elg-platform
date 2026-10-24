<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\TgBot;

use Illuminate\Foundation\Http\FormRequest;

class WebAppLoginRequest extends FormRequest
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
            'init_data' => 'required|string',
            'brand_code' => 'required|string',
            'code' => 'nullable|string',
            'domain' => 'nullable|string',
        ];
    }
}
