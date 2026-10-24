<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Enums\Payment\PayoutQueryOutcome;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use App\Services\Payment\Providers\CCPayment\Driver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 代付查單（SupportsPayoutQuery）：以我方訂單號查 getAppWithdrawRecord。
 *
 * 三態鐵則：只有查到紀錄且帶 recordId 才回 FOUND_ACCEPTED；查詢失敗或回應形狀不符一律 UNKNOWN，
 * 禁止把「查詢失敗」推斷成 NOT_FOUND（那會打開重複出款的窗口）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/DriverPayoutQueryTest.php
 */
class DriverPayoutQueryTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => 130,
            'info' => [
                'API_URL' => self::API_URL,
                'APP_ID' => 'test_app_id',
                'APP_SECRET' => 'test_app_secret',
            ],
        ]);
    }

    private function record(): WithdrawalPayoutRecord
    {
        return new WithdrawalPayoutRecord(['transaction_no' => 'CCP20260915000001']);
    }

    private function driver(): Driver
    {
        return new Driver($this->agentCashFlow);
    }

    public function test_driver_supports_payout_query(): void
    {
        $this->assertInstanceOf(SupportsPayoutQuery::class, $this->driver());
    }

    public function test_found_record_returns_accepted_with_record_id(): void
    {
        Http::fake([
            self::API_URL . '/getAppWithdrawRecord' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['record' => ['recordId' => '2024031311310811', 'orderId' => 'CCP20260915000001', 'status' => 'Processing']],
            ]),
        ]);

        $result = $this->driver()->queryPayout($this->record());

        $this->assertSame(PayoutQueryOutcome::FOUND_ACCEPTED, $result->outcome);
        $this->assertSame('2024031311310811', $result->thirdPartyOrderNo);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getAppWithdrawRecord'
            && $request->body() === '{"orderId":"CCP20260915000001"}');
    }

    public function test_success_without_record_returns_not_found(): void
    {
        Http::fake([
            self::API_URL . '/getAppWithdrawRecord' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['record' => null]]),
        ]);

        $this->assertSame(PayoutQueryOutcome::NOT_FOUND, $this->driver()->queryPayout($this->record())->outcome);
    }

    public function test_business_error_returns_unknown(): void
    {
        Http::fake([
            self::API_URL . '/getAppWithdrawRecord' => Http::response(['code' => 11002, 'msg' => 'Internal', 'data' => null]),
        ]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->driver()->queryPayout($this->record())->outcome);
    }

    public function test_http_failure_returns_unknown(): void
    {
        Http::fake([
            self::API_URL . '/getAppWithdrawRecord' => Http::response('', 503),
        ]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->driver()->queryPayout($this->record())->outcome);
    }
}
