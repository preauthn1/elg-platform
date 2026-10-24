<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class WinlosesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => 'required|integer',
            'search_day' => 'required|string',
            'page' => 'required|integer',
            'per_page' => 'required|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => __('error_message.1415'),
            'vendor_id.digits_between' => __('error_message.1416'),

            'page.required' => __('error_message.2005'),
            'page.integer' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.integer' => __('error_message.2008'),
        ];
    }
}
