<?php

declare(strict_types=1);

namespace App\Http\Requests\External\Bot;

use Illuminate\Foundation\Http\FormRequest;

class GetBalanceByUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => (string) $this->route('username', ''),
        ]);
    }

    public function rules(): array
    {
        return [
            // 涵蓋手填與系統產生的帳號：
            // - 長度 4~30（關閉模式自動產生的「前綴-uniqid」最長 30）
            // - 只能英數與減號
            // - 必須至少包含一個英文字母（不可全數字）
            'username' => ['required', 'string', 'between:4,30', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9-]+$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => 'username',
        ];
    }
}
