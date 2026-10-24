<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 掛上後 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()} 會接住前置檢查與 action 內
 * 拋出的任何 Throwable，交給 {@see self::renderCallbackError()} 翻成該遊戲商的回應格式與錯誤碼。
 * 沒掛的子類例外原樣往外拋，行為不變。
 *
 * 掛了這個介面，子類就不需要再覆寫 callAction()：前置檢查與例外轉換都由 BaseController 統一跑，
 * 子類只剩「資料從哪來」（accountIdentifier、isRequestSignatureValid……）與「錯誤怎麼回」兩種方法。
 *
 * 錯誤碼依「三方收到後還會不會再送」挑，不依語意貼切（game-integration skill R22）。
 */
interface RendersCallbackErrors
{
    public function renderCallbackError(Throwable $e): Response;
}
