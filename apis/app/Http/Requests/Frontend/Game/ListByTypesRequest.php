<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Game;

use Illuminate\Foundation\Http\FormRequest;

class ListByTypesRequest extends FormRequest
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
            'domain' => 'required|string',
            'type_counts' => 'required|array',
            'type_counts.*' => 'required|integer|min:1',
            'vendor_ids' => 'nullable|array',
            'vendor_ids.*' => 'nullable|numeric',
        ];
    }
}
