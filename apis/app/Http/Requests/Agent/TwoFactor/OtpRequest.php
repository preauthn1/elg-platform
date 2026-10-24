<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\TwoFactor;

use App\Http\Requests\AbstractRequest;

/**
 * 只帶本人驗證碼（2fa/enable）
 */
class OtpRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'secret' => 'required|string|max:10',
        ];
    }
}
