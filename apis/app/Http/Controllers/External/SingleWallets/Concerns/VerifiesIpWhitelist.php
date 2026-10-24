<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Concerns;

use App\Exceptions\IpNotAllowedException;
use App\Helpers\Common;

/**
 * 回調前置檢查：來源 IP 白名單。由 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()}
 * 在子類實作 {@see \App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyIpWhitelist} 時呼叫。
 *
 * 白名單規則沿用 {@see Common::verifyIPWhitelistByVendorId()}：只在 production 生效、vendors.whitelist 為空一律放行。
 *
 * @property int $vendorId
 */
trait VerifiesIpWhitelist
{
    /**
     * @throws IpNotAllowedException
     */
    protected function assertIpAllowed(): void
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            throw new IpNotAllowedException(sprintf('IP %s not allow', Common::getIp()));
        }
    }
}
