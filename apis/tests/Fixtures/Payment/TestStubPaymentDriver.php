<?php

declare(strict_types=1);

namespace Tests\Fixtures\Payment;

use App\Models\AgentCashFlow;

/**
 * 僅供 PaymentService::getProvider 單元測試使用
 */
final class TestStubPaymentDriver
{
    public function __construct(public AgentCashFlow $agentCashFlow) {}
}
