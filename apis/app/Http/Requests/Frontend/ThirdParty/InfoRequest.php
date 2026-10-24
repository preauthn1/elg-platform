<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ThirdParty;

use Illuminate\Foundation\Http\FormRequest;

class InfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'required|string|between:4,30',
        ];
    }
}
