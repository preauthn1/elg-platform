<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Game;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequest extends FormRequest
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
            'vendor_id' => [Rule::anyOf(['numeric', 'array'])],
            'vendor_id.*' => 'numeric',
            'type_id' => 'numeric',
            'game_name' => 'string',
            'page' => 'required|numeric',
            'per_page' => 'required|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id' => __('error_message.1401'),
            'type_id.numeric' => __('error_message.1402'),
            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008'),
        ];
    }
}
