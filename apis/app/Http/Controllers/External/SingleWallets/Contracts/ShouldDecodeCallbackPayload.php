<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

use Illuminate\Http\Request;

/**
 * 掛上後 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()} 會在 IP 檢查之後、
 * 解析帳號之前呼叫 {@see self::decodeCallbackPayload()}，讓子類把加密或編碼過的 payload 解開並寫回 request
 * （例如 $request->merge()），後續的帳號解析與驗簽才讀得到明文欄位。
 *
 * 純 hook，沒有共用實作；解不開時由子類自行拋例外。
 */
interface ShouldDecodeCallbackPayload
{
    public function decodeCallbackPayload(Request $request): void;
}
