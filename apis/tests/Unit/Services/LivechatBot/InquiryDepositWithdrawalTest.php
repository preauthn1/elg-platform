<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\LivechatBotTransactionTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\LivechatBot;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use App\Services\LivechatBot\InquiryDepositWithdrawalService;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Livechat Bot 查詢儲值/提款紀錄測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/InquiryDepositWithdrawalTest.php
 */
class InquiryDepositWithdrawalTest extends TestCase
{
    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id)));
        $this->guard = new MemberAgentGuard;
    }

    private function makeBot(int $agentId): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    private function makeService(?LivechatBotContext $context = null): InquiryDepositWithdrawalService
    {
        return new InquiryDepositWithdrawalService(
            context: $context ?? $this->context,
            guard: $this->guard,
        );
    }

    /**
     * 建立多筆 Deposit Order，驗證列表倒序排列
     */
    public function test_inquiry_deposit_returns_list_sorted_desc(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        $order1 = $this->createOrder($member->id, $currency->id);
        $order2 = $this->createOrder($member->id, $currency->id);
        $order3 = $this->createOrder($member->id, $currency->id);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );

        $this->assertCount(3, $result);
        $this->assertSame($order3->id, $result[0]['id']);
        $this->assertSame($order2->id, $result[1]['id']);
        $this->assertSame($order1->id, $result[2]['id']);
    }

    /**
     * 建立多筆 WithdrawalOrder，驗證列表倒序排列
     */
    public function test_inquiry_withdraw_returns_list_sorted_desc(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('KES');
        $bank = $this->createBank($currency->id);

        $order1 = $this->createWithdrawalOrder($member->id, $bank->id);
        $order2 = $this->createWithdrawalOrder($member->id, $bank->id);
        $order3 = $this->createWithdrawalOrder($member->id, $bank->id);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::WITHDRAW,
        );

        $this->assertCount(3, $result);
        $this->assertSame($order3->id, $result[0]['id']);
        $this->assertSame($order2->id, $result[1]['id']);
        $this->assertSame($order1->id, $result[2]['id']);
    }

    /**
     * Deposit 查詢最多回傳最新 50 筆（依 id 倒序）
     */
    public function test_inquiry_deposit_limits_to_50_records(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        /** @var array<int, Order> $orders */
        $orders = [];
        for ($i = 0; $i < 55; $i++) {
            $orders[] = $this->createOrder($member->id, $currency->id);
        }

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );

        // 剛好 50 筆
        $this->assertCount(50, $result);

        // 結果應為 id 最大的 50 筆（最新 50 筆），即 orders[5..54]
        $returnedIds = array_map(static fn (array $row): int => $row['id'], $result);
        $expectedIds = collect($orders)
            ->slice(5) // 跳過最舊的 5 筆
            ->sortByDesc('id')
            ->pluck('id')
            ->all();
        $this->assertSame($expectedIds, $returnedIds);

        // 最舊的 5 筆（最小 id）不應出現於結果中
        $oldestIds = collect($orders)->take(5)->pluck('id')->all();
        foreach ($oldestIds as $oldestId) {
            $this->assertNotContains($oldestId, $returnedIds);
        }
    }

    /**
     * Withdraw 查詢最多回傳最新 50 筆（依 id 倒序）
     */
    public function test_inquiry_withdraw_limits_to_50_records(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('KES');
        $bank = $this->createBank($currency->id);

        /** @var array<int, WithdrawalOrder> $orders */
        $orders = [];
        for ($i = 0; $i < 55; $i++) {
            $orders[] = $this->createWithdrawalOrder($member->id, $bank->id);
        }

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::WITHDRAW,
        );

        // 剛好 50 筆
        $this->assertCount(50, $result);

        // 結果應為 id 最大的 50 筆（最新 50 筆），即 orders[5..54]
        $returnedIds = array_map(static fn (array $row): int => $row['id'], $result);
        $expectedIds = collect($orders)
            ->slice(5) // 跳過最舊的 5 筆
            ->sortByDesc('id')
            ->pluck('id')
            ->all();
        $this->assertSame($expectedIds, $returnedIds);

        // 最舊的 5 筆（最小 id）不應出現於結果中
        $oldestIds = collect($orders)->take(5)->pluck('id')->all();
        foreach ($oldestIds as $oldestId) {
            $this->assertNotContains($oldestId, $returnedIds);
        }
    }

    /**
     * 會員存在但沒有任何紀錄，data 回傳空陣列
     */
    public function test_inquiry_returns_empty_array_when_no_records(): void
    {
        $member = $this->createMember($this->agent);

        $service = $this->makeService();

        $deposits = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );
        $withdraws = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::WITHDRAW,
        );

        $this->assertSame([], $deposits);
        $this->assertSame([], $withdraws);
    }

    /**
     * Deposit 狀態 mapping（涵蓋 CREATED / DEPOSIT_FINISHED / FAILED 三種）
     */
    public function test_deposit_status_mapping(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        $created = $this->createOrder($member->id, $currency->id, [
            'status' => OrderStatusEnum::CREATED->value,
        ]);
        $success = $this->createOrder($member->id, $currency->id, [
            'status' => OrderStatusEnum::SUCCESS->value,
        ]);
        $waiting = $this->createOrder($member->id, $currency->id, [
            'status' => OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value,
        ]);
        $finished = $this->createOrder($member->id, $currency->id, [
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
        ]);
        $failed = $this->createOrder($member->id, $currency->id, [
            'status' => OrderStatusEnum::FAILED->value,
        ]);

        $service = $this->makeService();
        $result = collect($service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        ))->keyBy('id');

        $this->assertSame('PENDING', $result[$created->id]['status']);
        $this->assertSame('PENDING', $result[$success->id]['status']);
        $this->assertSame('PENDING', $result[$waiting->id]['status']);
        $this->assertSame('APPROVED', $result[$finished->id]['status']);
        $this->assertSame('REJECTED', $result[$failed->id]['status']);
    }

    /**
     * Withdraw 狀態 mapping（特別驗證 APPROVED(3) → PENDING）
     */
    public function test_withdraw_status_mapping(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');
        $bank = $this->createBank($currency->id);

        $created = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::ORDER_CREATED->value,
        ]);
        $pendingReview = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::PENDING_REVIEW->value,
        ]);
        $approved = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::APPROVED->value,
        ]);
        $paymentSubmitted = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
        ]);
        $paymentProcessing = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        ]);
        $transferred = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::TRANSFERRED->value,
        ]);
        $thirdPartySuccess = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
        ]);
        $failed = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::FAILED->value,
        ]);
        $refund = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::REFUND->value,
        ]);
        $thirdPartyFailed = $this->createWithdrawalOrder($member->id, $bank->id, [
            'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
        ]);

        $service = $this->makeService();
        $result = collect($service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::WITHDRAW,
        ))->keyBy('id');

        $this->assertSame('PENDING', $result[$created->id]['status']);
        $this->assertSame('PENDING', $result[$pendingReview->id]['status']);
        // 關鍵驗證：內部 APPROVED(3) 在對外 API 為 PENDING
        $this->assertSame('PENDING', $result[$approved->id]['status']);
        $this->assertSame('PENDING', $result[$paymentSubmitted->id]['status']);
        $this->assertSame('PENDING', $result[$paymentProcessing->id]['status']);
        $this->assertSame('APPROVED', $result[$transferred->id]['status']);
        $this->assertSame('APPROVED', $result[$thirdPartySuccess->id]['status']);
        $this->assertSame('REJECTED', $result[$failed->id]['status']);
        $this->assertSame('REJECTED', $result[$refund->id]['status']);
        $this->assertSame('REJECTED', $result[$thirdPartyFailed->id]['status']);
    }

    /**
     * user_received_amount = amount - tax
     */
    public function test_user_received_amount_is_amount_minus_tax(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        $this->createOrder($member->id, $currency->id, [
            'amount' => '100',
            'tax' => '5',
        ]);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );

        $this->assertCount(1, $result);
        $this->assertSame('100.0000', $result[0]['amount']);
        $this->assertSame('95.0000', $result[0]['user_received_amount']);
    }

    /**
     * 金額 data 層級 4 位、message 層級 2 位
     */
    public function test_amount_format_4dp_in_data_and_2dp_in_message(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        $this->createOrder($member->id, $currency->id, [
            'amount' => '55',
            'tax' => '0',
        ]);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );

        $this->assertCount(1, $result);
        $record = $result[0];

        $this->assertSame('55.0000', $record['amount']);
        $this->assertSame('55.0000', $record['user_received_amount']);

        $this->assertContains('Amount: 55.00', $record['message']);
        $this->assertContains('User Received Amount: 55.00', $record['message']);
        $this->assertContains('Currency: MYR', $record['message']);
        $this->assertContains('Status: ' . $record['status'], $record['message']);
        $this->assertContains('ID: ' . $record['id'], $record['message']);
    }

    /**
     * P1-1：processing_at 遇 legacy '0000-00-00 00:00:00' 時需回傳 null
     */
    public function test_processing_at_handles_zero_datetime(): void
    {
        $member = $this->createMember($this->agent);
        $currency = $this->createCurrency('MYR');

        $order = $this->createOrder($member->id, $currency->id);
        // MySQL strict mode 會拒絕 '0000-00-00 00:00:00'，需先關閉本次連線的嚴格模式，
        // 以模擬 legacy 資料（舊系統殘留的零時間）情境。
        DB::statement("SET SESSION sql_mode = ''");
        DB::table('orders')->where('id', $order->id)->update(['pay_time' => '0000-00-00 00:00:00']);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['processing_at']);
    }

    /**
     * P1-3：提款紀錄的 currency 必須包含已停用（status=0）的幣別
     */
    public function test_withdraw_currency_includes_disabled_currency(): void
    {
        $member = $this->createMember($this->agent);
        // 幣別停用（USD 已進主檔且 code 有唯一鍵，測試庫若已有該列就就地改成停用，交易結束會回滾）
        $currency = Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'USD',
                'rate' => 1,
                'status' => 0,
                'sort' => 0,
                'admin' => 0,
            ],
        );
        $bank = $this->createBank($currency->id);

        $this->createWithdrawalOrder($member->id, $bank->id);

        $service = $this->makeService();
        $result = $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::WITHDRAW,
        );

        $this->assertCount(1, $result);
        $this->assertSame('USD', $result[0]['currency']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        $service = $this->makeService($otherContext);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->inquiry(
            username: $member->username,
            transactionType: LivechatBotTransactionTypeEnum::DEPOSIT,
        );
    }

    /**
     * 取得或建立 Currency
     *
     * currencies.code 自 2026-09-07 起有唯一鍵（多幣別錢包第二期），測試庫本來就有主檔的 21 筆，
     * 對 MYR 這類既有代碼再 create 會撞 1062；改為有就覆寫屬性、沒有才建，
     * 夾具屬性因此不依賴測試庫現況（DatabaseTransactions 會在測試結束時回滾）。
     */
    private function createCurrency(string $code): Currency
    {
        return Currency::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'rate' => 1,
                'status' => 1,
                'sort' => 0,
                'admin' => 0,
            ],
        );
    }

    /**
     * 建立 Bank
     */
    private function createBank(int $currencyId): Bank
    {
        return Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => 'Test Bank ' . Str::random(5),
            'code' => Str::random(4),
            'currency_id' => $currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 建立 Deposit Order
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(int $memberId, int $currencyId, array $overrides = []): Order
    {
        $defaults = [
            'member_id' => $memberId,
            'agent_id' => 0,
            'agent_cash_flow_id' => 0,
            'order_type_id' => 0,
            'currency_id' => $currencyId,
            'order_no' => 'O' . Str::random(20),
            'third_party_order_no' => '',
            'amount' => '100',
            'tax' => '0',
            'rate' => 1,
            'point' => 0,
            'balance' => 0,
            'status' => OrderStatusEnum::CREATED->value,
            'remark' => '',
            'admin' => 0,
        ];

        return Order::factory()->create(array_merge($defaults, $overrides));
    }

    /**
     * 建立 Withdrawal Order
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createWithdrawalOrder(int $memberId, int $bankId, array $overrides = []): WithdrawalOrder
    {
        $defaults = [
            'bank_id' => $bankId,
            'member_id' => $memberId,
            'agent_id' => 0,
            'agent_group_id' => 0,
            'amount' => '100',
            'tax' => '0',
            'rate' => 1,
            'point' => 0,
            'third_party_order_no' => Str::uuid()->toString(),
            'status' => WithdrawalOrderStatusEnum::ORDER_CREATED->value,
            'memo' => '',
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ];

        return WithdrawalOrder::factory()->create(array_merge($defaults, $overrides));
    }
}
