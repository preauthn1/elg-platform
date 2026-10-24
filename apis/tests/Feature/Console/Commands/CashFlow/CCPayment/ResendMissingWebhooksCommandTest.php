<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\CCPayment;

use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ccpayment:resend-missing-webhooks — 存款漏單與代付卡 22 的回調補送排程。
 *
 * 入帳與定案只走回調：本命令只找出缺漏、請金流商重送 webhook，不自己建單也不改單。
 * 重送過一次仍缺的才告警（去重靠 Cache）。存款清單依商戶底下每條支付通道的（幣, 鏈）各拉一輪，
 * 一條通道壞掉只跳過，不停掉整個商戶。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommandTest.php
 */
class ResendMissingWebhooksCommandTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $depositCashFlow;

    private AgentCashFlow $withdrawalCashFlow;

    private OrderType $orderType;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        BotNotify::shouldReceive('channel')->andReturnSelf()->byDefault();
        BotNotify::shouldReceive('send')->byDefault();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_rs_' . uniqid()]);

        $info = [
            'API_URL' => self::API_URL,
            'APP_ID' => 'test_app_id',
            'APP_SECRET' => 'test_app_secret',
        ];
        $this->depositCashFlow = $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 129, 'info' => $info]);
        $this->withdrawalCashFlow = $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 130, 'info' => $info]);

        // 幣與鏈由支付通道的 order type key 決定，coinId 依幣別代號向假的 getCoinList 查
        $this->orderType = $this->createChannel('CCPAYMENT.USDT_TRX');
    }

    /**
     * 假的 getCoinList：商戶後台啟用 USDT（1280，TRX 鏈）與測試幣 TETH（1891，ETH_SEPOLIA 鏈）
     *
     * @return array<string, mixed>
     */
    private function coinListBody(): array
    {
        return [
            'code' => 10000,
            'msg' => 'success',
            'data' => ['coins' => [
                ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
                ['coinId' => 1891, 'symbol' => 'TETH', 'status' => 'Normal', 'networks' => ['ETH_SEPOLIA' => ['chain' => 'ETH_SEPOLIA']]],
            ]],
        ];
    }

    /** 替代收商戶建一條支付通道（order type ＋ 關聯），回傳 order type */
    private function createChannel(string $key): OrderType
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 129,
            'currency_id' => 1,
            'name' => 'CCPayment ' . $key,
            'type' => 1,
            'status' => 1,
            'key' => $key,
            'info' => '',
            'admin' => 0,
        ]);

        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $this->depositCashFlow->id,
            'order_type_id' => $orderType->id,
            'status' => 1,
            'settings' => '',
        ]);

        return $orderType;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function fakeProvider(array $records): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getAppDepositRecordList' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['records' => $records, 'nextId' => ''],
            ]),
            self::API_URL . '/webhook/resend' => Http::response([
                'code' => 10000,
                'msg' => 'Success',
                'data' => ['resendCount' => 1],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function record(string $recordId, string $status = 'Success'): array
    {
        return [
            'recordId' => $recordId,
            'coinId' => 1280,
            'coinSymbol' => 'USDT',
            'chain' => 'TRX',
            'toAddress' => 'TXYZabc',
            'amount' => '100',
            'status' => $status,
            'isFlaggedAsRisky' => false,
            'arrivedAt' => Carbon::now()->subMinutes(30)->timestamp,
            'referenceId' => '1-1',
        ];
    }

    private function createDepositOrder(string $recordId): Order
    {
        return Order::create([
            'agent_cash_flow_id' => $this->depositCashFlow->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'amount' => '100.0000',
            'rate' => 1,
            'tax' => 0,
            'point' => '100.0000',
            'balance' => 0,
            'ip' => '0.0.0.0',
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'third_party_order_no' => $recordId,
            'remark' => '',
            'admin' => 0,
            'order_no' => 'CCP' . uniqid(),
        ]);
    }

    private function createProcessingWithdrawal(string $recordId, int $hoursAgo): WithdrawalOrder
    {
        $order = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'USDT0001',
            'bank_name' => 'USDT(TRC20)',
            'account_no' => 'TDestinationAddress',
            'account_name' => 'Test User',
            'amount' => '100.0000',
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => $recordId,
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        WithdrawalPayoutRecord::create([
            'cash_flow_id' => 130,
            'agent_cash_flow_id' => $this->withdrawalCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => 'CCP' . $recordId,
            'third_party_transaction_no' => $recordId,
            'result_amount' => 0,
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ]);

        $order->timestamps = false;
        $order->updated_at = Carbon::now()->subHours($hoursAgo);
        $order->save();

        return $order;
    }

    private function resendRequests(): int
    {
        $count = 0;
        Http::assertSent(function (Request $request) use (&$count): bool {
            if ($request->url() === self::API_URL . '/webhook/resend') {
                $count++;
            }

            return true;
        });

        return $count;
    }

    #[Group('rules/provider-ccpayment#R-29')]
    public function test_resends_webhooks_for_success_deposit_records_without_orders(): void
    {
        $this->createDepositOrder('r1');
        $this->fakeProvider([$this->record('r1'), $this->record('r2'), $this->record('r3', 'Processing')]);

        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getAppDepositRecordList'
            && str_contains($request->body(), '"coinId":1280')
            && str_contains($request->body(), '"chain":"TRX"'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/webhook/resend'
            && str_contains($request->body(), '"recordIds":["r2"]')
            && str_contains($request->body(), '"transactionType":"DirectDeposit"')
            && str_contains($request->body(), '"webhookResult":"AllResult"'));
        $this->assertSame(1, $this->resendRequests());
        $this->assertSame(0, Order::where('third_party_order_no', 'r2')->count());
    }

    #[Group('rules/provider-ccpayment#R-31')]
    public function test_channel_rejected_by_provider_is_skipped_and_other_channels_still_reconcile(): void
    {
        // Dev 的實況：測試網通道的 coinId 被清單 API 拒絕（11000），USDT 通道仍要照常對帳
        $this->createChannel('CCPAYMENT.TETH_ETH_SEPOLIA');
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getAppDepositRecordList' => function (Request $request) {
                if (str_contains($request->body(), '"coinId":1891')) {
                    return Http::response(['code' => 11000, 'msg' => 'invalid argument', 'data' => null]);
                }

                return Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['records' => [$this->record('r2')], 'nextId' => '']]);
            },
            self::API_URL . '/webhook/resend' => Http::response(['code' => 10000, 'msg' => 'Success', 'data' => ['resendCount' => 1]]),
        ]);

        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getAppDepositRecordList'
            && str_contains($request->body(), '"chain":"ETH_SEPOLIA"'));
        $this->assertSame(1, $this->resendRequests());
    }

    #[Group('rules/provider-ccpayment#R-31')]
    public function test_channel_on_chain_not_enabled_for_the_coin_is_skipped_and_other_channels_still_reconcile(): void
    {
        // 後台沒替 USDT 啟用 UNKNOWN 鏈：該通道跳過並記錄，USDT-TRX 照常對帳
        $this->createChannel('CCPAYMENT.USDT_UNKNOWN');
        $this->fakeProvider([$this->record('r2')]);

        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();

        $this->assertSame(1, $this->resendRequests());
    }

    #[Group('rules/provider-ccpayment#R-29')]
    public function test_record_still_missing_after_resend_is_alerted_instead_of_resent_again(): void
    {
        $this->fakeProvider([$this->record('r2')]);
        BotNotify::shouldReceive('send')->once();

        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();
        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();

        $this->assertSame(1, $this->resendRequests());
    }

    #[Group('rules/provider-ccpayment#R-29')]
    public function test_dry_run_lists_missing_records_but_resends_nothing(): void
    {
        $this->fakeProvider([$this->record('r2')]);

        $this->artisan('ccpayment:resend-missing-webhooks --dry-run')->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::API_URL . '/webhook/resend');
    }

    #[Group('rules/provider-ccpayment#R-30')]
    public function test_resends_withdrawal_webhooks_for_orders_stuck_in_processing_over_two_hours(): void
    {
        $this->fakeProvider([]);
        $stuck = $this->createProcessingWithdrawal('w1', hoursAgo: 3);
        $this->createProcessingWithdrawal('w2', hoursAgo: 1);

        $this->artisan('ccpayment:resend-missing-webhooks')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/webhook/resend'
            && str_contains($request->body(), '"recordIds":["w1"]')
            && str_contains($request->body(), '"transactionType":"ApiWithdrawal"'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), '"w2"'));
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, (int) $stuck->fresh()->status);
    }
}
