<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow;

use PHPUnit\Framework\TestCase;

/**
 * 金流商配置檔（Data/providers/*.php）的 credentials 只放欄位結構，私密憑證一律留空字串，
 * 實值由上線人員在後台填進 agent_cash_flows.info（見 AddAgentCashFlow 的「憑證實值永不進配置檔」）。
 */
class ProviderDataCredentialsTest extends TestCase
{
    public function test_private_credentials_are_empty(): void
    {
        $providersDir = dirname(__DIR__, 5) . '/app/Console/Commands/CashFlow/Data/providers';

        $topPay = require $providersDir . '/TopPay.php';
        $akPay = require $providersDir . '/AkPay.php';
        $payMate = require $providersDir . '/PayMate.php';

        $this->assertSame('', $topPay['credentials']['MERCHANT_PRIVATE_KEY']);
        $this->assertSame('', $akPay['credentials']['MERCHANT_TOKEN']);
        $this->assertSame('', $payMate['credentials']['MD5_KEY']);
    }
}
