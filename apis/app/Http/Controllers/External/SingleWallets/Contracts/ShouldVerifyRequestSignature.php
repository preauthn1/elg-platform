<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

use Illuminate\Http\Request;

/**
 * 掛上後 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()} 會在解析完帳號與商戶之後
 * 呼叫 {@see self::isRequestSignatureValid()}，回 false 就記 warning（參數經遮罩）並拋
 * {@see \App\Exceptions\SignatureVerificationException}。
 *
 * 排在帳號解析之後，是因為密鑰多半放在 vendor_infos.info，要先知道是哪個商戶才取得到。
 * 簽名演算法每家不同（digest、hash、wtoken、JWT），由子類實作；共用層只管「驗不過怎麼辦」。
 *
 * 🔴 掛上的同時必須刪掉子類 callAction 內自己的驗簽呼叫。
 */
interface ShouldVerifyRequestSignature
{
    public function isRequestSignatureValid(Request $request): bool;
}
