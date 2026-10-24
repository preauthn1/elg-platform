<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

/**
 * 掛上後 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()} 會在進 action 前
 * 以 {@see \App\Helpers\Common::verifyIPWhitelistByVendorId()} 驗來源 IP，不過就拋
 * {@see \App\Exceptions\IpNotAllowedException}。
 *
 * 🔴 掛上的同時必須刪掉子類 callAction 內自己的 IP 檢查，否則每個請求會查兩次 vendors。
 */
interface ShouldVerifyIpWhitelist {}
