<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\Payment\PayoutQueryOutcome;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use App\Services\Payment\Providers\A3Pay\Driver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A3Pay 代付查單（SupportsPayoutQuery）：以我方交易號打 GetPayoutDetail，供停 21 的單自動查證。
 *
 * 三態鐵則：只有 isSuccess=true、statusId 非 5、且帶 paymentOrderId 才回 FOUND_ACCEPTED；
 * 三方已判失敗（5）、任何拒絕、連線失敗、形狀不符一律 UNKNOWN，不推斷成 NOT_FOUND。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/DriverPayoutQueryTest.php
 */
class DriverPayoutQueryTest extends TestCase
{
    use A3PayCryptoHelpers;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAgentAndDomain();
        $this->generateRsaKeyPair();

        $info = $this->buildInfo();
        $this->agentCashFlow = new AgentCashFlow(['cash_flow_id' => 132, 'info' => json_encode($info)]);
        $this->agentCashFlow->info = $info;
    }

    private function record(): WithdrawalPayoutRecord
    {
        return new WithdrawalPayoutRecord(['transaction_no' => 'A3PAY20260922000001']);
    }

    private function queryOutcome(): PayoutQueryOutcome
    {
        return (new Driver($this->agentCashFlow))->queryPayout($this->record())->outcome;
    }

    public function test_driver_supports_payout_query(): void
    {
        $this->assertInstanceOf(SupportsPayoutQuery::class, new Driver($this->agentCashFlow));
    }

    public function test_found_with_payment_order_id_returns_accepted(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true, 'successCode' => 200, 'errorMessage' => '', 'errorList' => null,
            'data' => ['paymentOrderId' => 'W2026092200000001', 'mTransactionId' => 'A3PAY20260922000001', 'statusId' => 1, 'status' => 'Processing', 'payoutAmount' => 100, 'payoutNetAmount' => 101.5, 'paymentMethodId' => 'WD'],
        ], 200)]);

        $result = (new Driver($this->agentCashFlow))->queryPayout($this->record());

        $this->assertSame(PayoutQueryOutcome::FOUND_ACCEPTED, $result->outcome);
        $this->assertSame('W2026092200000001', $result->thirdPartyOrderNo);

        Http::assertSent(function (Request $request): bool {
            $data = json_decode($this->aesDecrypt($request->data()['data']), true);
            $this->assertSame(['MID' => 'SGD00001', 'MTransactionId' => 'A3PAY20260922000001', 'CurrCode' => 'SGD'], $data);

            return str_ends_with($request->url(), '/api/Payout/GetPayoutDetail');
        });
    }

    /** 三方已判失敗：不回 FOUND_ACCEPTED（轉 22 只會等一個不會再來的回調），交人工 */
    public function test_status_5_fail_returns_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => ['paymentOrderId' => 'W2026092200000001', 'statusId' => 5, 'status' => 'Fail'],
        ], 200)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    public function test_found_without_payment_order_id_returns_unknown(): void
    {
        Http::fake(['*' => Http::response(['isSuccess' => true, 'data' => ['statusId' => 1, 'status' => 'Processing']], 200)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    /** 文件沒有「查無」錯誤碼：拒絕一律 UNKNOWN，不推斷成 NOT_FOUND */
    public function test_rejected_returns_unknown_not_not_found(): void
    {
        Http::fake(['*' => Http::response(['isSuccess' => false, 'successCode' => 400, 'errorMessage' => 'Transaction not found'], 400)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    public function test_http_failure_returns_unknown(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    public function test_connection_exception_returns_unknown(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    /** 真實查單回應（dev 提款單 700 已取消，statusId 5）→ UNKNOWN 交人工 */
    public function test_real_failed_detail_returns_unknown(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYOUT_DETAIL_FAILED, 200)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }

    /** 真實查無回應：HTTP 400 純文字 Not found（廠商確認）→ UNKNOWN，不推斷 NOT_FOUND */
    public function test_real_plaintext_not_found_returns_unknown(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYOUT_DETAIL_NOT_FOUND_BODY, 400)]);

        $this->assertSame(PayoutQueryOutcome::UNKNOWN, $this->queryOutcome());
    }
}
