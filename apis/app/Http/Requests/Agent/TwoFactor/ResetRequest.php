<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\TwoFactor;

use App\Http\Requests\AbstractRequest;

/**
 * 重置他人 Google 2FA（2fa/reset-agent）：secret 是操作者本人的驗證碼
 */
class ResetRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|min:1',
            'secret' => 'required|string|max:10',
        ];
    }
}
