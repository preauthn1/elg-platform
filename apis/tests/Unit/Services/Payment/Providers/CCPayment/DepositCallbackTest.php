<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\DepositManualReview;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\CCPayment\DepositCallback;
use App\Services\PaymentService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 代收回調：永久地址模式的 DirectDeposit（回查後建單）與風險地址回調。
 *
 * 判定矩陣以 DepositCallback 的 class docblock 為準：
 * 只有回查為 Success、代幣與鏈別等於支付通道設定、非風險交易才建單入帳；
 * 幣別不符不建單只告警；風險交易建單為待人工確認；Processing／Failed 不建單。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private const APP_ID = 'test_app_id';

    private const APP_SECRET = [REDACTED]';

    private const ADDRESS = 'TXYZabc';

    private const RECORD_ID = '2024031311310811';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $agentCashFlow;

    private AgentCashFlowOrderType $channel;

    private MemberCryptoWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        BotNotify::shouldReceive('channel')->andReturnSelf()->byDefault();
        BotNotify::shouldReceive('send')->byDefault();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_cb_' . uniqid()]);
        [, $this->agentCashFlow, , $this->channel] = $this->createDefaultCashFlow($this->agent, 129, 1);
        // 幣（USDT）與鏈（TRX）由支付通道的 order type key 決定，coinId 1280 依幣別代號向假的 getCoinList 查；商戶 info 只放憑證
        OrderType::whereKey($this->channel->order_type_id)->update(['key' => 'CCPAYMENT.USDT_TRX']);
        $this->channel = $this->channel->fresh();

        $this->agentCashFlow->info = [
            'API_URL' => self::API_URL,
            'APP_ID' => self::APP_ID,
            'APP_SECRET' => self::APP_SECRET,
        ];
        $this->agentCashFlow->save();
        $this->agentCashFlow = $this->agentCashFlow->fresh();

        $this->wallet = $this->createWallet($this->member, self::ADDRESS);
    }

    private function createWallet(Member $member, string $address, int $status = 1): MemberCryptoWallet
    {
        return MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $this->channel->id,
            'member_id' => $member->id,
            'address' => $address,
            'status' => $status,
            'balance' => 0,
            'admin' => 0,
        ]);
    }

    private function referenceId(): string
    {
        return $this->channel->id . '-' . $this->member->id;
    }

    /** @param array<string, mixed> $payload */
    private function signedRequest(array $payload, ?string $sign = null): Request
    {
        $rawBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $sign ??= hash_hmac('sha256', self::APP_ID . $timestamp . $rawBody, self::APP_SECRET);

        return Request::create(
            '/external/api/deposit/notify/' . $this->agentCashFlow->id,
            'POST',
            [],
            [],
            [],
            [
                'HTTP_APPID' => self::APP_ID,
                'HTTP_TIMESTAMP' => $timestamp,
                'HTTP_SIGN' => $sign,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function directDepositPayload(array $overrides = []): array
    {
        return [
            'type' => 'DirectDeposit',
            'msg' => array_merge([
                'recordId' => self::RECORD_ID,
                'referenceId' => $this->referenceId(),
                'coinId' => 1280,
                'coinSymbol' => 'USDT',
                'status' => 'Success',
                'isFlaggedAsRisky' => false,
            ], $overrides),
        ];
    }

    /**
     * 假的回查結果（getAppDepositRecord）、解綁端點，與商戶啟用代幣清單（getCoinList：USDT 1280 在 TRX 鏈）
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeRecord(array $overrides = [], int $unbindCode = 10000): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['coins' => [
                    ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
                ]],
            ]),
            self::API_URL . '/getAppDepositRecord' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => [
                    'record' => array_merge([
                        'recordId' => self::RECORD_ID,
                        'coinId' => 1280,
                        'coinSymbol' => 'USDT',
                        'chain' => 'TRX',
                        'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                        'coinUSDPrice' => '1',
                        'fromAddress' => 'TSender',
                        'toAddress' => self::ADDRESS,
                        'toMemo' => '',
                        'amount' => '100.5',
                        'serviceFee' => '0',
                        'txId' => 'tx1',
                        'status' => 'Success',
                        'isFlaggedAsRisky' => false,
                        'arrivedAt' => 1741783734,
                        'referenceId' => $this->referenceId(),
                    ], $overrides),
                ],
            ]),
            self::API_URL . '/addressUnbinding' => Http::response([
                'code' => $unbindCode,
                'msg' => $unbindCode === 10000 ? 'success' : 'failed',
                'data' => $unbindCode === 10000 ? [
                    'unbound' => [['chain' => 'TRX', 'address' => self::ADDRESS]],
                    'unboundAt' => 1741783734,
                    'userID' => '',
                    'referenceID' => $this->referenceId(),
                ] : null,
            ]),
        ]);
    }

    private function handle(Request $request): DepositCallbackResponseData
    {
        return (new DepositCallback($this->agentCashFlow))->handle($request);
    }

    private function memberOrders(): int
    {
        return Order::where('member_id', $this->member->id)->count();
    }

    // =========================================================================
    // 驗簽
    // =========================================================================

    public function test_rejects_webhook_with_invalid_signature(): void
    {
        $this->fakeRecord();

        try {
            $this->handle($this->signedRequest($this->directDepositPayload(), sign: 'bad-signature'));
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNothingSent();
            $this->assertSame(0, $this->memberOrders());
        }
    }

    // =========================================================================
    // DirectDeposit
    // =========================================================================

    public function test_success_deposit_creates_order_with_queried_amount_ready_for_crediting(): void
    {
        $this->fakeRecord();

        $result = $this->handle($this->signedRequest($this->directDepositPayload()));

        $this->assertSame('Success', $result->response);
        $order = $result->order;
        $this->assertNotNull($order);
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame(self::RECORD_ID, $order->third_party_order_no);
        $this->assertSame($this->member->id, (int) $order->member_id);
        $this->assertSame($this->agentCashFlow->id, (int) $order->agent_cash_flow_id);
        $this->assertSame(0, Common::numCompare((string) $order->amount, '100.5', 4));
        $this->assertSame(1, $this->memberOrders());
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::API_URL . '/getAppDepositRecord'
            && $request->body() === '{"recordId":"' . self::RECORD_ID . '"}');
    }

    public function test_resent_webhook_for_processed_record_creates_no_second_order_and_skips_query(): void
    {
        $this->fakeRecord();
        $first = $this->handle($this->signedRequest($this->directDepositPayload()));
        $first->order?->update(['status' => OrderStatusEnum::DEPOSIT_FINISHED->value]);

        $second = $this->handle($this->signedRequest($this->directDepositPayload()));

        $this->assertSame('Success', $second->response);
        $this->assertSame($first->order?->id, $second->order?->id);
        $this->assertSame(1, $this->memberOrders());
        // 第一次回調：一次 getCoinList（查 coinId）加一次回查；重送的回調零請求
        Http::assertSentCount(2);
        $this->assertSame(1, Http::recorded(fn (ClientRequest $request): bool => $request->url() === self::API_URL . '/getAppDepositRecord')->count());
    }

    public function test_success_deposit_writes_service_fee_as_payment_cost_and_crediting_keeps_it(): void
    {
        // updateMemberBalance 會讀代理設定，測試庫只有 schema，定義要自己 seed
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        // 成本費率設非 0，證明入帳時沒有被「點數 × 成本費率」覆寫
        $this->agentCashFlow->update(['rate' => 4.176, 'cost' => 0.02]);
        $this->fakeRecord(['serviceFee' => '0.5025']);

        $order = $this->handle($this->signedRequest($this->directDepositPayload()))->order;
        $this->assertNotNull($order);
        $this->assertSame('2.0984', (string) $order->fresh()->payment_cost); // 0.5025 × 4.176，截到第 4 位

        app(PaymentService::class)->updateMemberBalance($order);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatusEnum::DEPOSIT_FINISHED->value, (int) $fresh->status);
        $this->assertSame('2.0984', (string) $fresh->payment_cost);
    }

    public function test_missing_service_fee_still_credits_and_falls_back_to_cost_rate(): void
    {
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agentCashFlow->update(['rate' => 4.176, 'cost' => 0.02]);
        $this->fakeRecord(['serviceFee' => null]);
        BotNotify::shouldReceive('send')->once();

        $order = $this->handle($this->signedRequest($this->directDepositPayload()))->order;
        $this->assertNotNull($order);
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('0.0000', (string) $order->fresh()->payment_cost);

        app(PaymentService::class)->updateMemberBalance($order);

        $this->assertSame('8.3937', (string) $order->fresh()->payment_cost); // 100.5 × 4.176 × 0.02，截到第 4 位
    }

    public function test_coin_mismatch_creates_no_order_and_alerts(): void
    {
        $this->fakeRecord(['coinId' => 1, 'coinSymbol' => 'TRX']);
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest($this->directDepositPayload(['coinId' => 1, 'coinSymbol' => 'TRX'])));

        $this->assertSame('Success', $result->response);
        $this->assertNull($result->order);
        $this->assertSame(0, $this->memberOrders());
    }

    public function test_chain_mismatch_creates_no_order_and_alerts(): void
    {
        $this->fakeRecord(['chain' => 'ETH']);
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest($this->directDepositPayload()));

        $this->assertNull($result->order);
        $this->assertSame(0, $this->memberOrders());
    }

    public function test_risky_deposit_creates_order_waiting_manual_confirmation_and_alerts(): void
    {
        $this->fakeRecord(['isFlaggedAsRisky' => true, 'serviceFee' => '0.5025']);
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest($this->directDepositPayload(['isFlaggedAsRisky' => true])));

        $this->assertNotNull($result->order);
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $result->order->status);
        $this->assertSame(self::RECORD_ID, $result->order->third_party_order_no);
        $this->assertSame(0, Common::numCompare((string) $result->order->amount, '100.5', 4));
        // 風險交易單建單時不寫金流商手續費：服務費要等退款成功才扣，由退款同步排程寫入
        $this->assertSame('0.0000', (string) $result->order->fresh()->payment_cost);

        // 同交易寫入存款人工確認紀錄：待處理、不可上分，舊代理後台據此擋手動上分
        $review = DepositManualReview::query()->where('order_id', $result->order->id)->sole();
        $this->assertSame(DepositManualReviewReasonEnum::RISKY_TRANSACTION->value, $review->reason);
        $this->assertSame(DepositManualReviewResultEnum::PENDING->value, $review->result);
        $this->assertSame(0, (int) $review->allow_credit);
        $this->assertSame($this->agentCashFlow->id, (int) $review->agent_cash_flow_id);
        $this->assertSame((int) $this->agentCashFlow->cash_flow_id, (int) $review->cash_flow_id);
    }

    public function test_processing_status_creates_no_order_and_does_not_alert(): void
    {
        $this->fakeRecord(['status' => 'Processing']);
        BotNotify::shouldReceive('send')->never();

        $result = $this->handle($this->signedRequest($this->directDepositPayload(['status' => 'Processing'])));

        $this->assertSame('Success', $result->response);
        $this->assertNull($result->order);
        $this->assertSame(0, $this->memberOrders());
    }

    public function test_failed_status_creates_no_order(): void
    {
        $this->fakeRecord(['status' => 'Failed']);

        $result = $this->handle($this->signedRequest($this->directDepositPayload(['status' => 'Failed'])));

        $this->assertNull($result->order);
        $this->assertSame(0, $this->memberOrders());
    }

    public function test_address_owned_by_another_member_creates_no_order_and_alerts(): void
    {
        $other = $this->createMember($this->agent, ['username' => 'ccp_cb_other_' . uniqid()]);
        $this->createWallet($other, 'TOther');
        $this->fakeRecord(['toAddress' => 'TOther']);
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest($this->directDepositPayload()));

        $this->assertNull($result->order);
        $this->assertSame(0, $this->memberOrders());
        $this->assertSame(0, Order::where('member_id', $other->id)->count());
    }

    public function test_reference_id_not_in_our_format_creates_no_order_and_alerts_without_query(): void
    {
        $this->fakeRecord();
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest($this->directDepositPayload(['referenceId' => 'not-ours'])));

        $this->assertSame('Success', $result->response);
        $this->assertNull($result->order);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 風險地址回調
    // =========================================================================

    public function test_risky_address_webhook_disables_wallet_unbinds_and_alerts(): void
    {
        $this->fakeRecord();
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest([
            'chainId' => 'TRX',
            'address' => self::ADDRESS,
            'riskAt' => 1741783734,
        ]));

        $this->assertSame('Success', $result->response);
        $this->assertNull($result->order);
        $this->assertSame(0, (int) $this->wallet->fresh()->status);
        $this->assertSame(self::ADDRESS, $this->wallet->fresh()->address);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::API_URL . '/addressUnbinding'
            && $request->body() === '{"chain":"TRX","address":"' . self::ADDRESS . '"}');
    }

    public function test_risky_address_webhook_for_unknown_address_only_alerts(): void
    {
        $this->fakeRecord();
        BotNotify::shouldReceive('send')->once();

        $result = $this->handle($this->signedRequest([
            'chainId' => 'TRX',
            'address' => 'TNobody',
            'riskAt' => 1741783734,
        ]));

        $this->assertSame('Success', $result->response);
        $this->assertSame(1, (int) $this->wallet->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_risky_address_webhook_still_disables_wallet_when_unbinding_fails(): void
    {
        $this->fakeRecord(unbindCode: 11003);
        BotNotify::shouldReceive('send')->atLeast()->once();

        $result = $this->handle($this->signedRequest([
            'chainId' => 'TRX',
            'address' => self::ADDRESS,
            'riskAt' => 1741783734,
        ]));

        $this->assertSame('Success', $result->response);
        $this->assertSame(0, (int) $this->wallet->fresh()->status);
    }
}
