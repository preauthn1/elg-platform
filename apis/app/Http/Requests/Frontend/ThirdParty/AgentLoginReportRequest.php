<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ThirdParty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 客服後台回報代理登入失敗／成功（agent-login-failed、agent-login-succeeded 共用）。
 */
class AgentLoginReportRequest extends FormRequest
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
            'from' => 'required|string|between:4,30',
        ];
    }
}
