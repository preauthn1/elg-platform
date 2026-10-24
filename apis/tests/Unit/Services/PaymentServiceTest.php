<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PaymentService;
use Exception;
use Illuminate\Support\Facades\Config;
use Tests\Fixtures\Payment\TestStubPaymentDriver;
use Tests\TestCase;

/**
 * PaymentService 測試（getProvider）
 *
 * php artisan test tests/Unit/Services/PaymentServiceTest.php
 */
class PaymentServiceTest extends TestCase
{
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'paysvc-' . uniqid('', true) . '.local';
        $this->service = app(PaymentService::class);
    }

    public function test_get_provider_throws_when_third_party_code_missing(): void
    {
        $agent = $this->createAgentAndDomain();
        [$cashFlow, $agentCashFlow] = $this->createDefaultCashFlow($agent, random_int(20000, 99999), 1);
        $cashFlow->update(['third_party_code' => null]);
        $agentCashFlow->refresh();
        $agentCashFlow->load('cashFlow');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('third_party_code');

        $this->service->getProvider($agentCashFlow);
    }

    public function test_get_provider_throws_when_provider_not_registered(): void
    {
        $agent = $this->createAgentAndDomain();
        [$cashFlow, $agentCashFlow] = $this->createDefaultCashFlow($agent, random_int(20000, 99999), 1);
        $cashFlow->update(['third_party_code' => 'DDNOPE']);
        $agentCashFlow->refresh();
        $agentCashFlow->load('cashFlow');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not ready or does not exist');

        $this->service->getProvider($agentCashFlow);
    }

    public function test_get_provider_returns_configured_driver(): void
    {
        Config::set('payment.provider.TST', TestStubPaymentDriver::class);

        $agent = $this->createAgentAndDomain();
        [$cashFlow, $agentCashFlow] = $this->createDefaultCashFlow($agent, random_int(20000, 99999), 1);
        $cashFlow->update(['third_party_code' => 'DDTST']);
        $agentCashFlow->refresh();
        $agentCashFlow->load('cashFlow');

        $driver = $this->service->getProvider($agentCashFlow);

        $this->assertInstanceOf(TestStubPaymentDriver::class, $driver);
        $this->assertSame($agentCashFlow->id, $driver->agentCashFlow->id);
    }
}
