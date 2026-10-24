<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Withdrawal;

use App\Facades\BotNotify;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\PaymentService;
use App\Services\WithdrawalPayoutManualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 代付發起事務邊界拆分（target/tickets#4137）：
 *  - 第一階段短事務先提交 21 佔位（擋重複發起），三方 HTTP 於事務外執行
 *  - 請求例外（逾時／連線失敗）＝結果未知 → 訂單停留 21，禁止還原重送，告警交人工定案
 *  - 同步明確拒絕＝確定失敗 → 一律轉 29（不再還原發起前狀態），可重新發起
 *  - 第二階段條件寫入：回調先定案讓路、人工誤標 29 遇受理硬事實拉回 22
 *
 * php artisan test tests/Feature/Agent/Withdrawal/PayoutIndeterminateTest.php
 */
class PayoutIndeterminateTest extends TestCase
{
    private string $apiPath = 'agent/api/withdrawal/payout-process';

    private Agent $agent;

    private Bank $bank;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        // fixture 須為可通過渠道覆核（缺陷 C 守衛）的合法渠道，
        // 才能測到守衛之後的同步結果分流（缺陷 D／#4137 事務拆分）
        $this->bank = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => '測試銀行',
            'code' => 'TEST001',
            'currency_id' => 1,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $cashFlow = CashFlow::create([
            'name' => 'Payout Channel',
            'type' => 3,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $this->agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'currency_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'admin' => 0,
            'info' => [
                'available_banks' => [['BankCode_ELG' => 'TEST001']],
            ],
            'status' => 1,
        ]);
    }

    #[Group('rules/payout-submit-and-status#R-4')]
    public function test_request_exception_keeps_order_submitted(): void
    {
        BotNotify::spy();
        $order = $this->makeOrderWithStatus(3);
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => false,
            'errorMessage' => 'cURL error 28: Operation timed out after 30001 milliseconds',
            'isIndeterminate' => true,
        ]));

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        // 結果未知：訂單停留在 21（擋重送），不再轉 23、更不得還原回 3
        $this->assertSame(21, (int) $order->refresh()->status, 'response: ' . $response->getContent());
        // 卡單須主動告警交人工定案，不能靜默
        BotNotify::shouldHaveReceived('send')->once();
    }

    #[Group('rules/payout-submit-and-status#R-3')]
    public function test_definite_sync_rejection_marks_third_party_failed(): void
    {
        $order = $this->makeOrderWithStatus(3);
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => false,
            'errorMessage' => 'Insufficient merchant balance',
            'isIndeterminate' => false,
        ]));

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        // 明確拒絕一律轉 29（2026-08-17 決議，不再還原發起前狀態），可重新發起
        $this->assertSame(29, (int) $order->refresh()->status, 'response: ' . $response->getContent());
    }

    #[Group('rules/payout-submit-and-status#R-2')]
    public function test_sync_accept_moves_order_to_processing(): void
    {
        $order = $this->makeOrderWithStatus(3);
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => true,
            'errorMessage' => '',
            'providerOrderNo' => 'THIRD-001',
        ]));

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        // 受理主線：21 → 22 並回填三方單號
        $this->assertSame(22, (int) $order->status);
        $this->assertSame('THIRD-001', $order->third_party_order_no);
    }

    public function test_provider_request_happens_outside_transaction_with_committed_submitted_status(): void
    {
        $order = $this->makeOrderWithStatus(3);
        $observed = [];
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'THIRD-002',
            ]),
            function () use ($order, &$observed): void {
                // HTTP 當下必須已離開業務事務（level 1＝測試框架 DatabaseTransactions 的外層），
                // 且 21 已寫入——這就是「pod 中斷、請求紀錄不再被回滾」的核心契約
                $observed['tx_level'] = DB::transactionLevel();
                $observed['status'] = (int) WithdrawalOrder::query()->whereKey($order->id)->value('status');
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(1, $observed['tx_level'], '三方 HTTP 必須發生在業務事務之外');
        $this->assertSame(21, $observed['status'], '第一階段的 21 必須先提交再送 HTTP');
    }

    public function test_sync_result_yields_when_callback_already_finalized(): void
    {
        BotNotify::spy();
        $order = $this->makeOrderWithStatus(3);
        // 模擬「HTTP 在途期間回調先抵達並定案 24」。局限：真實回調含 record 鎖與驗簽，
        // 單 connection 測試無法重現跨 connection 競爭（見計劃「並發驗證限制」）
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'LATE-ACCEPT-001',
            ]),
            function () use ($order): void {
                WithdrawalOrder::query()->whereKey($order->id)->update([
                    'status' => 24,
                    'third_party_order_no' => 'CALLBACK-FIRST',
                ]);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        // 已定案 24（終態）不得被遲到的同步結果覆寫回 22
        $this->assertSame(24, (int) $order->status);
        $this->assertSame('CALLBACK-FIRST', $order->third_party_order_no);
    }

    public function test_sync_accept_pulls_back_manual_failed_to_processing(): void
    {
        BotNotify::spy();
        $order = $this->makeOrderWithStatus(3);
        // 模擬「HTTP 在途期間人工誤標 29」（record 不存在＝非回調判死）：
        // 支付商已受理是硬事實，必須拉回 22，否則 29 可重新發起＝重複出款，
        // 且 29 已移出回調放行、成功回調無法自動糾正
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'THIRD-003',
            ]),
            function () use ($order): void {
                WithdrawalOrder::query()->whereKey($order->id)->update(['status' => 29]);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        $this->assertSame(22, (int) $order->status);
        $this->assertSame('THIRD-003', $order->third_party_order_no);
        // 拉回必須告警，人工需要知道自己的誤標被機器事實糾正
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_sync_accept_yields_to_callback_failed_order(): void
    {
        $order = $this->makeOrderWithStatus(3);
        // 模擬「HTTP 在途期間三方失敗回調先抵達」：record 已被回調寫 CALLBACK_FAILED(-3)
        // ＝三方自己定案失敗，29 必須站住、不得拉回 22（拉回會讓訂單卡死等不到回調）
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'THIRD-004',
            ]),
            function () use ($order): void {
                $this->makeCallbackFailedRecord($order);
                WithdrawalOrder::query()->whereKey($order->id)->update(['status' => 29]);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(29, (int) $order->refresh()->status);
    }

    public function test_stale_attempt_sync_result_yields_to_newer_attempt(): void
    {
        BotNotify::spy();
        $order = $this->makeOrderWithStatus(3);
        // ABA 競態（狀態 21→29→重新發起又回到 21，值相同、實為兩次發起）：前一次發起
        // 在途期間被回調判 29（其 record 已 -3）→ 人工從 29 重新發起（訂單回 21、建了
        // 更新的 record）→ 前一次發起的遲到「明確拒絕」不得把新發起的 21 洗成 29
        // （否則新發起在途時又開重送窗口＝重複出款）
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => false,
                'errorMessage' => 'Insufficient merchant balance',
                'isIndeterminate' => false,
            ]),
            function () use ($order): void {
                $this->makeCallbackFailedRecord($order);          // 前一次發起的 record（回調判死）
                $this->makePendingRecord($order, 'TXN-ATTEMPT-B'); // 新一次發起建立的新 record
                WithdrawalOrder::query()->whereKey($order->id)->update(['status' => 21]);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        // 新發起的 21 必須原封不動（同步結果綁定本次發起的 record，較早發起的結果一律讓路）
        $this->assertSame(21, (int) $order->refresh()->status);
    }

    public function test_stale_rejection_yields_when_resubmitted_before_new_record(): void
    {
        BotNotify::spy();
        $order = $this->makeOrderWithStatus(3);
        // 最窄的 ABA 窗口：前一次發起被回調判死 → 重新發起「已寫 21、但尚未建立新 record」——
        // 此刻前一次發起的遲到「明確拒絕」不得把新發起的 21 洗成 29。守衛＝attempt token
        // 等值比對（重新發起的第一階段原子換上新 token），與時間戳精度無關、同秒也不誤判
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => false,
                'errorMessage' => 'Insufficient merchant balance',
                'isIndeterminate' => false,
            ]),
            function () use ($order): void {
                $this->makeCallbackFailedRecord($order); // 前一次發起的 record＋回調判死
                // 重新發起：寫 21 並原子換上新的 attempt token（新 record 尚未建立）
                WithdrawalOrder::query()->whereKey($order->id)->update([
                    'status' => 21,
                    'third_party_response' => 'attempt:token-of-newer-attempt-b',
                ]);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        // 新發起的 21 必須原封不動
        $this->assertSame(21, (int) $order->refresh()->status);
    }

    public function test_pull_back_commit_survives_notification_failure(): void
    {
        // 通知故障不得回滾「29 誤標拉回 22」的安全寫入（通知於事務提交後送出、失敗只記 log）
        BotNotify::shouldReceive('send')->andThrow(new \RuntimeException('notify down'));
        $order = $this->makeOrderWithStatus(3);
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'THIRD-006',
            ]),
            function () use ($order): void {
                WithdrawalOrder::query()->whereKey($order->id)->update(['status' => 29]);
            },
        );

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        $this->assertSame(22, (int) $order->status, 'response: ' . $response->getContent());
        $this->assertSame('THIRD-006', $order->third_party_order_no);
    }

    public function test_manual_mark_failed_during_request_is_pulled_back_by_acceptance(): void
    {
        BotNotify::spy();
        // scope 守衛以 hierarchy_path 前綴判定，操作者需自成子樹根且不命中 admin bypass
        $this->agent->update(['hierarchy_path' => '/' . $this->agent->id, 'host_id' => 0]);
        $order = $this->makeOrderWithStatus(3);
        // 真實人工路徑（含鎖、scope、狀態守衛）於 HTTP 在途期間標記失敗：
        // 受理結果仍須拉回 22。單 connection 鎖可重入，跨 connection 競爭見「並發驗證限制」
        $this->mockProviderResponseWithSideEffect(
            WithdrawalResponseData::from([
                'isSuccess' => true,
                'errorMessage' => '',
                'providerOrderNo' => 'THIRD-005',
            ]),
            function () use ($order): void {
                // 倒退 updated_at 越過 in-flight 保護窗，人工接口才可操作
                WithdrawalOrder::query()->whereKey($order->id)->update([
                    'updated_at' => now()->subMinutes(10)->toDateTimeString(),
                ]);
                app(WithdrawalPayoutManualService::class)->markFailed($order->id, $this->agent);
            },
        );

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        $this->assertSame(22, (int) $order->status);
        $this->assertSame('THIRD-005', $order->third_party_order_no);
    }

    #[Group('rules/payout-submit-and-status#R-1')]
    public function test_submitted_order_rejects_duplicate_payout_request(): void
    {
        $order = $this->makeOrderWithStatus(21);
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => true,
            'errorMessage' => '',
            'providerOrderNo' => 'MUST-NOT-REACH',
        ]));

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        // 21 佔位即擋重複發起：狀態不變、三方單號未被寫入（provider 未被觸發）
        $this->assertSame(21, (int) $order->status);
        $this->assertNotSame('MUST-NOT-REACH', $order->third_party_order_no);
        $this->assertStringContainsString('not allowed', $response->getContent());
    }

    #[Group('rules/payout-submit-and-status#R-1')]
    public function test_rejects_statuses_not_allowed_to_submit(): void
    {
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => true,
            'errorMessage' => '',
            'providerOrderNo' => 'MUST-NOT-REACH',
        ]));

        foreach ([22, 24] as $status) {
            $order = $this->makeOrderWithStatus($status);

            $this->requestApi($this->apiPath, [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'order_id' => $order->id,
            ]);

            // 可發起集合僅 {3, 29}：22（在途）與 24（終態）都必須被擋
            $this->assertSame($status, (int) $order->refresh()->status);
        }
    }

    #[Group('rules/payout-submit-and-status#R-1')]
    #[Group('rules/payout-submit-and-status#R-3')]
    public function test_resend_from_failed_status_succeeds(): void
    {
        $order = $this->makeOrderWithStatus(29);
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => true,
            'errorMessage' => '',
            'providerOrderNo' => 'THIRD-RESEND',
        ]));

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_id' => $order->id,
        ]);

        $order->refresh();
        // {3,29} 契約的另一半：29 可重新發起並照常走 21 → 22
        $this->assertSame(22, (int) $order->status);
        $this->assertSame('THIRD-RESEND', $order->third_party_order_no);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function makeOrderWithStatus(int $status): WithdrawalOrder
    {
        return WithdrawalOrder::factory()->create([
            'agent_id' => $this->agent->id,
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'member_id' => 0,
            'bank_id' => $this->bank->id,
            'bank_code' => 'TEST001',
            'bank_name' => '測試銀行',
            'status' => $status,
        ]);
    }

    private function mockProviderResponse(WithdrawalResponseData $response): void
    {
        $provider = new class($response)
        {
            public function __construct(private readonly WithdrawalResponseData $response) {}

            public function withdrawal(WithdrawalRequestData $request): WithdrawalResponseData
            {
                return $this->response;
            }
        };

        $this->mock(PaymentService::class, function ($mock) use ($provider): void {
            $mock->shouldReceive('getProvider')->andReturn($provider);
        });
    }

    private function mockProviderResponseWithSideEffect(WithdrawalResponseData $response, \Closure $sideEffect): void
    {
        $provider = new class($response, $sideEffect)
        {
            public function __construct(
                private readonly WithdrawalResponseData $response,
                private readonly \Closure $sideEffect,
            ) {}

            public function withdrawal(WithdrawalRequestData $request): WithdrawalResponseData
            {
                ($this->sideEffect)();

                return $this->response;
            }
        };

        $this->mock(PaymentService::class, function ($mock) use ($provider): void {
            $mock->shouldReceive('getProvider')->andReturn($provider);
        });
    }

    private function makePendingRecord(WithdrawalOrder $order, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '',
            'result_amount' => $order->amount,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => 0, // WithdrawalPayoutStatusEnum::PENDING
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
        ]);
    }

    private function makeCallbackFailedRecord(WithdrawalOrder $order): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => 'TXN-CB-FAILED-' . $order->id,
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '',
            'result_amount' => $order->amount,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '{}',
            'is_success_payout' => -3, // WithdrawalPayoutStatusEnum::CALLBACK_FAILED
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
        ]);
    }
}
