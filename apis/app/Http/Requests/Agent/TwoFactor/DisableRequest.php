<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\TwoFactor;

use App\Http\Requests\AbstractRequest;

/**
 * 關閉 Google 2FA（2fa/disable）：agent_id 不帶＝關閉自己；secret 一律是操作者本人的驗證碼
 */
class DisableRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'nullable|integer|min:1',
            'secret' => 'required|string|max:10',
        ];
    }
}
