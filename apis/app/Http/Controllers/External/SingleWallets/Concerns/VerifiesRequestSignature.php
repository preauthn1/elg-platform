<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Concerns;

use App\Exceptions\SignatureVerificationException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyRequestSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * 回調前置檢查：驗簽。由 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()}
 * 在子類實作 {@see ShouldVerifyRequestSignature} 時呼叫。
 *
 * 簽名演算法由子類的 isRequestSignatureValid() 提供；本 trait 只負責驗不過時記 warning 並拋例外。
 * warning 內的參數一律經 {@see Common::maskCredentials()}；簽名類欄位刻意不遮，保留從 log 重算簽名的排查能力。
 *
 * @property string $actionPrefix
 */
trait VerifiesRequestSignature
{
    /**
     * @throws SignatureVerificationException
     */
    protected function assertSignatureValid(Request $request): void
    {
        if (!$this instanceof ShouldVerifyRequestSignature) {
            throw new LogicException(static::class . ' must implement ' . ShouldVerifyRequestSignature::class);
        }

        if ($this->isRequestSignatureValid($request)) {
            return;
        }

        Log::warning($this->actionPrefix . 'signature_verify_failed', [
            'params' => Common::maskCredentials($request->all()),
        ]);

        throw new SignatureVerificationException('Invalid request');
    }
}
