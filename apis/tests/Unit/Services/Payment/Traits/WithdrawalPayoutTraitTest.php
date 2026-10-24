<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Traits;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalResponseData;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesTelegramDispatch;
use Tests\TestCase;

/**
 * WithdrawalPayoutTrait::processPayout 編排器分支測試
 *
 * 以 inline test double 直接驅動 processPayout，覆蓋四條路徑：
 *  - 請求例外 → REQUEST_FAILED(-1)
 *  - 同步受理 → 維持 PENDING(0)
 *  - 同步拒絕 → THIRD_PARTY_REJECTED(-2)
 *  - interpretResult 丟例外（response 漂移）→ THIRD_PARTY_REJECTED(-2)，不卡 PENDING
 *
 * 另含回調的訂單狀態檢查（checkWithdrawalOrderStatus）：哪些狀態放行，以及拒絕時的告警在 rollBack 後仍實際派發。
 *
 * php artisan test tests/Unit/Services/Payment/Traits/WithdrawalPayoutTraitTest.php
 */
class WithdrawalPayoutTraitTest extends TestCase
{
    use FakesTelegramDispatch;

    public function test_request_exception_marks_request_failed(): void
    {
        [$double, $order] = $this->makeDouble();

        $response = $double->runProcessPayout(
            $order,
            'TXN-EX',
            ['foo' => 'bar'],
            performRequest: fn (): array => throw new \RuntimeException('connection timeout'),
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(isSuccess: true),
        );

        $this->assertFalse($response->isSuccess);
        $record = $this->recordFor($order);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    public function test_request_exception_response_is_indeterminate(): void
    {
        [$double, $order] = $this->makeDouble();

        $response = $double->runProcessPayout(
            $order,
            'TXN-EX-IND',
            ['foo' => 'bar'],
            performRequest: fn (): array => throw new \RuntimeException('cURL error 28: Operation timed out'),
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(isSuccess: true),
        );

        // 逾時／連線例外＝「不知道結果」，必須標記 indeterminate 供呼叫端分流（缺陷 D）
        $this->assertTrue($response->isIndeterminate);
    }

    public function test_sync_reject_response_is_not_indeterminate(): void
    {
        [$double, $order] = $this->makeDouble();

        $response = $double->runProcessPayout(
            $order,
            'TXN-REJ-DET',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['code' => '0001', 'msg' => 'denied'],
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: false,
                errorMessage: $result['msg'] ?? 'Unknown error',
            ),
        );

        // 同步明確拒絕＝確定失敗，非 indeterminate，呼叫端可安全還原重送
        $this->assertFalse($response->isIndeterminate);
    }

    public function test_sync_accept_stays_pending(): void
    {
        [$double, $order] = $this->makeDouble();

        $response = $double->runProcessPayout(
            $order,
            'TXN-OK',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['code' => '9999', 'data' => ['platOrderNum' => 'TP-1']],
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: true,
                thirdPartyOrderNo: $result['data']['platOrderNum'] ?? '',
            ),
        );

        $this->assertTrue($response->isSuccess);
        $record = $this->recordFor($order);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('TP-1', $record->third_party_transaction_no);
    }

    public function test_payout_record_leaves_transaction_time_empty_for_callback(): void
    {
        [$double, $order] = $this->makeDouble();

        $double->runProcessPayout(
            $order,
            'TXN-TIME',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['code' => '9999'],
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(isSuccess: true),
        );

        // transaction_time 只收三方回傳的交易時間，建單不得先填我方時間當佔位
        // （代理後台把它當三方時間顯示，target/op_tickets#3467）；請求時間看 created_at
        $record = $this->recordFor($order);
        $this->assertSame('', $record->transaction_time);
        $this->assertNotNull($record->created_at);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        [$double, $order] = $this->makeDouble();

        $response = $double->runProcessPayout(
            $order,
            'TXN-REJ',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['code' => '0001', 'msg' => 'denied'],
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: false,
                errorMessage: $result['msg'] ?? 'Unknown error',
            ),
        );

        $this->assertFalse($response->isSuccess);
        $record = $this->recordFor($order);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_interpret_exception_marks_rejected_not_pending(): void
    {
        [$double, $order] = $this->makeDouble();

        BotNotify::spy();

        $response = $double->runProcessPayout(
            $order,
            'TXN-DRIFT',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['unexpected' => 'schema'],
            interpretResult: fn (array $result): PayoutResult => throw new \RuntimeException('missing key'),
        );

        $this->assertFalse($response->isSuccess);
        $record = $this->recordFor($order);
        // 防禦深度：parser 例外保守視為 -2，且不可卡在 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
        // raw response 與錯誤訊息有回填
        $this->assertStringContainsString('unexpected', $record->payout_order_response);
        $this->assertStringContainsString('missing key', $record->info_1);
        // 疑似三方格式變更：必須發出告警通知人工檢查 parser
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_request_exception_truncates_info_1(): void
    {
        [$double, $order] = $this->makeDouble();

        // op_tickets#2784 實際訊息：帶兩段完整 URL，加上 driver prefix 後為 216 字元，
        // 超出 TPower / VWorld 正式庫 info_1 的 varchar(200)，未截斷會拋 1406
        // 並把外層代付交易連同「逾時轉 23 鎖定」的保護一起 rollback
        $longMessage = 'cURL error 28: Connection timed out after 10002 milliseconds '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html) '
            . 'for https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2_B2b.aspx';

        $double->runProcessPayout(
            $order,
            'TXN-LONG-EX',
            ['foo' => 'bar'],
            performRequest: fn (): array => throw new \RuntimeException($longMessage),
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(isSuccess: true),
        );

        $record = $this->recordFor($order);
        $this->assertGreaterThan(200, mb_strlen('TestDouble request exception: ' . $longMessage));
        $this->assertLessThanOrEqual(200, mb_strlen($record->info_1));
        $this->assertStringStartsWith('TestDouble request exception: cURL error 28', $record->info_1);
        // 截斷不得讓摘要失真到無法辨識來源，完整原文仍在 payout_order_response
        $this->assertStringContainsString($longMessage, $record->payout_order_response);
    }

    public function test_response_failure_truncates_multibyte_info_1(): void
    {
        [$double, $order] = $this->makeDouble();

        // 三方回傳中文錯誤訊息時，截斷必須依字元切，不可把多位元組字元攔腰砍成亂碼
        $longChineseMessage = str_repeat('代付請求遭三方拒絕，請確認渠道設定與餘額。', 20);

        $double->runProcessPayout(
            $order,
            'TXN-LONG-REJ',
            ['foo' => 'bar'],
            performRequest: fn (): array => ['code' => '0001', 'msg' => $longChineseMessage],
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: false,
                errorMessage: $result['msg'],
            ),
        );

        $record = $this->recordFor($order);
        $this->assertLessThanOrEqual(200, mb_strlen($record->info_1));
        $this->assertSame($record->info_1, mb_convert_encoding($record->info_1, 'UTF-8', 'UTF-8'));
        $this->assertStringStartsWith('TestDouble response error: 代付請求遭三方拒絕', $record->info_1);
    }

    public function test_callback_status_check_rejects_retired_verifying_status(): void
    {
        [$double, $order] = $this->makeDouble();
        $order->update(['status' => 23]);
        $this->fakeTelegramDispatch();

        // 23（原「三方查證中」）已隨 target/tickets#4137 收尾移除，線上不應再出現；
        // 若仍收到 23 的回調，代表有未知寫入者，走「其餘狀態」路徑告警並拒絕，交人工追查
        $e = $this->rejectStatusCheckInRolledBackTransaction($double, $order);

        $this->assertSame(400, $e->getCode());
        $this->assertCount(1, $this->dispatchedTelegramAlerts('訂單狀態不合預期'));
    }

    public function test_callback_status_check_still_rejects_completed_order(): void
    {
        [$double, $order] = $this->makeDouble();
        $order->update(['status' => 24]);
        $this->fakeTelegramDispatch();

        // 24（已成功）的回調仍須拒絕（缺陷 B 守門：已定案成功的單不得被回調改動）
        $e = $this->rejectStatusCheckInRolledBackTransaction($double, $order);

        $this->assertSame(400, $e->getCode());
        $this->assertCount(1, $this->dispatchedTelegramAlerts('訂單狀態不合預期'));
    }

    public function test_response_failure_does_not_overwrite_callback_finalized_record(): void
    {
        [$double, $order] = $this->makeDouble();

        // record 建立於事務外：回調可能在 HTTP 往返期間搶先定案（此處模擬回調已寫 SUCCESS）。
        // 遲到的同步失敗 handler 不得抹除回調終態與冪等標記，否則重放回調不再走已處理 ACK
        $double->runProcessPayout(
            $order,
            'TXN-CB-RACE',
            ['foo' => 'bar'],
            performRequest: function () use ($order): array {
                WithdrawalPayoutRecord::query()
                    ->where('withdrawal_order_id', $order->id)
                    ->update(['is_success_payout' => WithdrawalPayoutStatusEnum::SUCCESS->value]);

                return ['code' => '9999'];
            },
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: false,
                errorMessage: 'rejected',
            ),
        );

        // 回調寫入的 SUCCESS 必須站住，不被 THIRD_PARTY_REJECTED(-2) 覆寫
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $this->recordFor($order)->is_success_payout);
    }

    public function test_callback_status_check_allows_submitted_order(): void
    {
        [$double, $order] = $this->makeDouble();

        // 21（代付請求中）：結果未明／pod 中斷停留的單，回調正是定案依據，必須放行（#4137）
        $double->runCheckWithdrawalOrderStatus($order->refresh());

        $this->assertTrue(true);
    }

    public function test_callback_status_check_rejects_failed_order(): void
    {
        [$double, $order] = $this->makeDouble();
        $order->update(['status' => 29]);
        $this->fakeTelegramDispatch();

        // 29 已移出放行（2026-08-17 決議）：拒絕且必須告警——
        // 若為補送的成功回調，代表訂單可能遭誤標，重新發起就是重複出款
        $e = $this->rejectStatusCheckInRolledBackTransaction($double, $order);

        $this->assertSame(400, $e->getCode());
        $this->assertCount(1, $this->dispatchedTelegramAlerts('仍收到回調'));
    }

    private function recordFor(WithdrawalOrder $order): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
    }

    /**
     * 照各金流商回調的寫法跑狀態檢查：在交易內呼叫，被拒絕就 rollBack。
     * 告警若跟著交易被丟掉，呼叫端的 dispatchedTelegramAlerts() 會是空的。
     */
    private function rejectStatusCheckInRolledBackTransaction(object $double, WithdrawalOrder $order): WithdrawalOrderException
    {
        DB::beginTransaction();

        try {
            $double->runCheckWithdrawalOrderStatus($order->refresh());
        } catch (WithdrawalOrderException $e) {
            DB::rollBack();

            return $e;
        }

        DB::rollBack();
        $this->fail(sprintf('狀態 %d 的回調應被拒絕', $order->status));
    }

    /**
     * 建立 test double（含已持久化的 AgentCashFlow）與一筆狀態 21 的提款訂單。
     *
     * @return array{0: object, 1: WithdrawalOrder}
     */
    private function makeDouble(): array
    {
        $agent = Agent::factory()->create();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $member = Member::factory()->create(['parent' => $agent->id]);

        $agentCashFlow = AgentCashFlow::create([
            'currency_id' => $currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'admin' => 0,
            'info' => json_encode([]),
        ]);
        $agentCashFlow->info = [];

        $order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_id' => 0,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $double = new class($agentCashFlow) extends AbstractMethod
        {
            public function __construct(AgentCashFlow $agentCashFlow)
            {
                $this->agentCashFlow = $agentCashFlow;
                $this->info = [];
            }

            protected function getDriverName(): string
            {
                return 'TestDouble';
            }

            /**
             * @param  array<string, mixed>  $params
             * @param  Closure(): array<mixed>  $performRequest
             * @param  Closure(array<mixed>): PayoutResult  $interpretResult
             */
            public function runProcessPayout(
                WithdrawalOrder $order,
                string $transactionNo,
                array $params,
                Closure $performRequest,
                Closure $interpretResult,
            ): WithdrawalResponseData {
                return $this->processPayout($order, $transactionNo, $params, $performRequest, $interpretResult);
            }

            public function runCheckWithdrawalOrderStatus(?WithdrawalOrder $order): void
            {
                $this->checkWithdrawalOrderStatus($order);
            }
        };

        return [$double, $order];
    }
}
