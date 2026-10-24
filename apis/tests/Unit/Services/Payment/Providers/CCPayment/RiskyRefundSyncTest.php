<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use App\Enums\OrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\DepositManualReview;
use App\Models\Member;
use App\Models\Order;
use App\Services\Payment\Providers\CCPayment\RiskyRefundSync;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 風險交易退款同步：依 getRiskyRefundRecordList 的退款結果更新風險交易充值訂單。
 *
 * 判定矩陣以 RiskyRefundSync 的 class docblock 為準：退回付款人改狀態 2、退到白名單地址改為可上分，
 * 兩者都寫入服務費 × 匯率；地址都不符只告警；一筆批量退款對到多筆存款時各自處理。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/RiskyRefundSyncTest.php
 */
class RiskyRefundSyncTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private const PAYER = 'TPayerAddress';

    private const MERCHANT_ADDRESS = 'TMerchantAddress';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $agentCashFlow;

    private AgentCashFlowOrderType $channel;

    /** @var array<string, array<string, mixed>> 假的原存款回查結果，依 recordId */
    private array $depositRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        BotNotify::shouldReceive('channel')->andReturnSelf()->byDefault();
        BotNotify::shouldReceive('send')->byDefault();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_rr_' . uniqid()]);
        [, $this->agentCashFlow, , $this->channel] = $this->createDefaultCashFlow($this->agent, 129, 1);

        $this->agentCashFlow->info = [
            'API_URL' => self::API_URL,
            'APP_ID' => 'test_app_id',
            'APP_SECRET' => 'test_app_secret',
            RiskyRefundSync::MERCHANT_ADDRESSES_KEY => [self::MERCHANT_ADDRESS],
        ];
        $this->agentCashFlow->save();
        $this->agentCashFlow = $this->agentCashFlow->fresh();
    }

    /** 建一張風險交易充值訂單（狀態 4）與待處理列，原存款回查的服務費為 $serviceFee */
    private function riskyOrder(string $recordId, string $serviceFee = '0.5025', string $payer = self::PAYER): Order
    {
        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $this->channel->order_type_id,
            'currency_id' => 1,
            'third_party_order_no' => $recordId,
            'order_no' => 'ccp_rr_' . $recordId,
            'status' => OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value,
            'balance' => 0,
            'amount' => '100.5',
            'point' => 0,
            'remark' => '',
            'rate' => 4.176,
            'admin' => 0,
        ]);

        DB::transaction(fn () => DepositManualReview::createPending($order, $this->agentCashFlow, DepositManualReviewReasonEnum::RISKY_TRANSACTION));

        $this->depositRecords[$recordId] = [
            'recordId' => $recordId,
            'fromAddress' => $payer,
            'serviceFee' => $serviceFee,
            'status' => 'Success',
            'isFlaggedAsRisky' => true,
        ];

        return $order;
    }

    /**
     * 假的退款清單（兩頁時以 nextId 翻頁）與原存款回查
     *
     * @param  list<list<array<string, mixed>>>  $pages
     */
    private function fakeRefunds(array $pages): void
    {
        Http::fake(function (ClientRequest $request) use ($pages) {
            if ($request->url() === self::API_URL . '/getAppDepositRecord') {
                return Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['record' => $this->depositRecords[$request['recordId']]]]);
            }

            $page = (int) ($request['nextId'] ?? 0);

            return Http::response(['code' => 10000, 'msg' => 'success', 'data' => [
                'records' => $pages[$page],
                'nextId' => isset($pages[$page + 1]) ? (string) ($page + 1) : '',
            ]]);
        });
    }

    /** @return array<string, mixed> */
    private function refund(string $refundRecordId, array $depositRecordIds, string $toAddress, string $status = 'Success'): array
    {
        return [
            'recordId' => $refundRecordId,
            'toAddress' => $toAddress,
            'status' => $status,
            'fee' => ['coinSymbol' => 'USDT', 'amount' => '1'],
            'fromDeposit' => array_map(fn (string $id): array => ['recordId' => $id], $depositRecordIds),
        ];
    }

    private function sync(): void
    {
        $summary = (new RiskyRefundSync($this->agentCashFlow))->sync();
        $this->assertSame([], $summary['failed'], implode("\n", $summary['lines']));
    }

    private function reviewOf(Order $order): DepositManualReview
    {
        return DepositManualReview::query()->where('order_id', $order->id)->sole();
    }

    public function test_refund_to_payer_marks_order_failed_and_writes_service_fee(): void
    {
        $order = $this->riskyOrder('dep1');
        $this->fakeRefunds([[$this->refund('rf1', ['dep1'], self::PAYER)]]);

        $this->sync();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::FAILED->value, (int) $order->status);
        $this->assertSame('2.0984', (string) $order->payment_cost); // 0.5025 × 4.176，截到第 4 位

        $review = $this->reviewOf($order);
        $this->assertSame(DepositManualReviewResultEnum::REFUNDED_TO_PAYER->value, $review->result);
        $this->assertSame(0, (int) $review->allow_credit);
        $this->assertNotNull($review->resolved_at);
        // MySQL JSON 欄位會重排鍵順序，只比對內容
        $this->assertEquals(['refund_record_id' => 'rf1', 'refund_status' => 'Success', 'refund_to_address' => self::PAYER, 'payer_address' => self::PAYER], $review->proof);
    }

    public function test_refund_to_whitelisted_address_allows_credit_and_writes_service_fee(): void
    {
        $order = $this->riskyOrder('dep1');
        $this->fakeRefunds([[$this->refund('rf1', ['dep1'], self::MERCHANT_ADDRESS)]]);

        $this->sync();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, (int) $order->status, '退到營運方的單維持狀態 4，由營運按手動上分');
        $this->assertSame('2.0984', (string) $order->payment_cost);

        $review = $this->reviewOf($order);
        $this->assertSame(DepositManualReviewResultEnum::REFUNDED_TO_MERCHANT->value, $review->result);
        $this->assertSame(1, (int) $review->allow_credit);
    }

    public function test_refund_to_unknown_address_changes_nothing_and_alerts(): void
    {
        $order = $this->riskyOrder('dep1');
        $this->fakeRefunds([[$this->refund('rf1', ['dep1'], 'TSomeoneElse')]]);
        BotNotify::shouldReceive('send')->once();

        $this->sync();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, (int) $order->status);
        $this->assertSame('0.0000', (string) $order->payment_cost);
        $review = $this->reviewOf($order);
        $this->assertSame(DepositManualReviewResultEnum::PENDING->value, $review->result);
        $this->assertSame(0, (int) $review->allow_credit);
    }

    public function test_batch_refund_resolves_each_deposit_with_its_own_fee(): void
    {
        $first = $this->riskyOrder('dep1', '0.5025', 'TPayerOne');
        $second = $this->riskyOrder('dep2', '1', 'TPayerOne');
        // 第二頁才出現的退款也要拉到：同一個付款人兩筆風險存款，一次批量退回
        $this->fakeRefunds([
            [$this->refund('rf0', ['other'], 'TNotOurs')],
            [$this->refund('rf1', ['dep1', 'dep2'], 'TPayerOne')],
        ]);

        $this->sync();

        $this->assertSame(OrderStatusEnum::FAILED->value, (int) $first->fresh()->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, (int) $second->fresh()->status);
        $this->assertSame('2.0984', (string) $first->fresh()->payment_cost);
        $this->assertSame('4.1760', (string) $second->fresh()->payment_cost); // 1 × 4.176
        $this->assertSame(DepositManualReviewResultEnum::REFUNDED_TO_PAYER->value, $this->reviewOf($first)->result);
        $this->assertSame(DepositManualReviewResultEnum::REFUNDED_TO_PAYER->value, $this->reviewOf($second)->result);
    }

    public function test_rerun_writes_once_and_processing_refund_is_skipped(): void
    {
        $resolved = $this->riskyOrder('dep1');
        $processing = $this->riskyOrder('dep2');
        $this->fakeRefunds([[
            $this->refund('rf1', ['dep1'], self::PAYER),
            $this->refund('rf2', ['dep2'], self::PAYER, 'Processing'),
        ]]);

        $this->sync();
        $firstResolvedAt = $this->reviewOf($resolved)->resolved_at;
        // 營運之後在別處改了手續費；重跑不能蓋掉，也不能再寫一次結果
        $resolved->update(['payment_cost' => '9.9999']);

        $this->sync();

        $this->assertSame('9.9999', (string) $resolved->fresh()->payment_cost);
        $this->assertEquals($firstResolvedAt, $this->reviewOf($resolved)->resolved_at);
        $this->assertSame(DepositManualReviewResultEnum::PENDING->value, $this->reviewOf($processing)->result);
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, (int) $processing->fresh()->status);
        // 第二輪 dep1 已不是待處理，只剩 dep2 在待處理清單裡：只拉退款清單、不回查任何存款
        $this->assertSame(1, Http::recorded(fn (ClientRequest $request): bool => $request->url() === self::API_URL . '/getAppDepositRecord')->count());
    }
}
