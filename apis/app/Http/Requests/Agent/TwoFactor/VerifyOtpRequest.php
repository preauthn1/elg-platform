<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\TwoFactor;

use App\Http\Requests\AbstractRequest;

/**
 * 驗證指定代理的驗證碼（2fa/auth）
 */
class VerifyOtpRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|min:1',
            'secret' => 'required|string|max:10',
        ];
    }
}
