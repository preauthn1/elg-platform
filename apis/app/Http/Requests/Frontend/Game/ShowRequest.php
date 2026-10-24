<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Game;

use Illuminate\Foundation\Http\FormRequest;

class ShowRequest extends FormRequest
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
            'game_id' => 'required|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'game_id.required' => __('error_message.1401'),
            'game_id.numeric' => __('error_message.1402'),
        ];
    }
}
