<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Main;

use App\Http\Requests\AbstractRequest;

/**
 * @deprecated 隨 `POST agent/api/login` 一併停用（target/tickets#4548），原因與重啟條件見 {@see \App\Http\Controllers\Agent\MainController}。
 */
class LoginRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'action' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'domain' => 'nullable||string|exists:domains,domain',
            'mobile_auth' => 'nullable|bool',
            'secret' => 'nullable|string',
        ];
    }
}
