<?php

declare(strict_types=1);

namespace App\Http\Requests\External\LivechatBot;

use Illuminate\Foundation\Http\FormRequest;

class GetPromotionDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:50'],
            'promotionName' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => (string) $this->route('username'),
            'promotionName' => (string) $this->route('promotionName'),
        ]);
    }

    public function username(): string
    {
        return (string) $this->input('username');
    }

    public function promotionName(): string
    {
        return (string) $this->input('promotionName');
    }
}
