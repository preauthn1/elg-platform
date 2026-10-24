<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalFeeTierTypeEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalTaxSourceEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\MemberGroup;
use App\Models\TransactionLog;
use App\Models\VipContent;
use App\Models\WithdrawalOrder;
use App\Services\MemberService;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Database\Seeders\WithdrawalTypeSeeder;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php
 */
class CreateOrderTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Bank $bank;

    protected AgentCashFlow $agentCashFlow;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(WithdrawalTypeSeeder::class);
        $this->seed(CashFlowSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->bank = $this->createBank();
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent);
    }

    /**
     * 測試提款成功
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_success
     */
    public function test_success()
    {
        // 增加餘額方便提款測試
        $this->member->balance = 100;
        $this->member->save();

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // 檢查 withdrawal_order 資料
        $bank_tax = ($this->bank->tax_type == 0) ? $this->bank->tax : ($this->bank->tax * $postData['amount']);
        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->where('status', 1)->first();
        $this->assertNotNull($order);
        $this->assertEquals($order->amount, 50);
        $this->assertEquals($order->bank_id, $this->bank->id);
        $this->assertEquals($order->bank_name, $this->bank->name);
        $this->assertEquals($order->bank_code, $this->bank->code);
        $this->assertEquals($order->account_name, 'tim');
        $this->assertEquals($order->account_no, '123546789');
        $this->assertEquals($order->agent_group_id, $this->agent->cash_flow_group_id);
        $this->assertEquals($order->member_id, $this->member->id);
        $this->assertEquals($order->rate, $this->bank->rate);
        $this->assertEquals($order->tax, $bank_tax);
        $this->assertEquals($order->third_party_order_no, '');

        // 檢查 transaction_log 資料
        $log = TransactionLog::query()->where('member_id', $this->member->id)->where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals($log->agent_id, $this->member->parent);
        $this->assertEquals($log->member_id, $this->member->id);
        $this->assertEquals($log->order_id, $order->id);
        $this->assertEquals($log->point, $postData['amount']);
        $this->assertEquals($log->vendor_point, 0);
        $this->assertEquals($log->rate, 0);
        $this->assertEquals($log->before_balance, $this->member->balance);
        $this->assertEquals($log->balance, $this->member->balance - $postData['amount']);
        $this->assertEquals($log->type, TransactionEnum::WITHDRAWAL->value);
        $this->assertEquals($log->operator, $this->member->username);
        $this->assertEquals($log->status, TransactionStatusEnum::STATUS_REVIEW->value);

        // Assert JSON 結構
        $response->assertStatus(200);
    }

    /**
     * 測試餘額不足
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_insufficient_balance
     */
    public function test_insufficient_balance()
    {
        $this->member->balance = 49;
        $this->member->save();

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1022'),
            ]);
    }

    /**
     * 測試提款金額超過限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_exceed_withdrawal_amount_limit
     */
    public function test_exceed_withdrawal_amount_limit()
    {
        $this->member->balance = 10000;
        $this->member->save();

        $postData = [
            'amount' => 1001,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.9997'),
            ]);
    }

    /**
     * 測試提款金額低於限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_below_withdrawal_amount_limit
     */
    public function test_below_withdrawal_amount_limit()
    {
        $this->member->balance = 10000;
        $this->member->save();

        $postData = [
            'amount' => 49,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.9997'),
            ]);
    }

    /**
     * 測試修改過手機判斷
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_modify_member_data
     */
    public function test_modify_member_data()
    {
        $this->member->balance = 10000;
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_WITHDRAWAL, 1);
        $this->createMemberBindingLog();

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.2046'),
            ]);
    }

    /**
     * 測試真實姓名
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_realname_required
     */
    public function test_realname_required()
    {
        $this->member->balance = 10000;
        $this->member->realname_validate = 0;
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED, 1);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.realname_invalid.withdrawal'),
            ]);
    }

    /**
     * 測試流水限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_flow_limit
     */
    public function test_flow_limit()
    {
        $this->member->balance = 10000;
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 1);
        $mock = Mockery::mock(MemberService::class)->makePartial();
        $mock->shouldReceive('getFlowLimit')->andReturn([
            'pass' => 0,
            'flow_limit' => 1000,
            'reach' => 0,
        ]);
        $this->app->instance(MemberService::class, $mock);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1980'),
            ]);
    }

    /**
     * 測試提領次數限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_withdrawal_times
     */
    public function test_withdrawal_times()
    {
        $this->member->balance = 10000;
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT, 3);
        $mock = Mockery::mock(MemberService::class)->makePartial();
        $mock->shouldReceive('getTodayWithdrawalCount')->andReturn(3);
        $this->app->instance(MemberService::class, $mock);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1956'),
            ]);
    }

    /**
     * 測試vip提領次數限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_vip_withdrawal_times
     */
    public function test_vip_withdrawal_times()
    {
        $this->member->balance = 10000;
        $this->member->save();

        // 設定一般提領次數限制 3次
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT, 3);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::VIP_VISIBLE, 1);

        $mock = Mockery::mock(MemberService::class)->makePartial();
        // 設定已提領 3次
        $mock->shouldReceive('getTodayWithdrawalCount')->andReturn(3);
        // 設定VIP提領次數限制 1次, 會額外加上一般提領次數限制, 理論上這樣會提領成功
        // withdrawal_amount_limit = -1 表示不限制金額，避免命中當日累計金額檢查
        $mock->shouldReceive('getVipContent')->andReturn(VipContent::query()->create([
            'withdrawal_times_limit' => 1,
            'withdrawal_amount_limit' => -1,
        ]));
        $this->app->instance(MemberService::class, $mock);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200);
    }

    /**
     * 測試超過vip提領次數限制
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_exceed_vip_withdrawal_times
     */
    public function test_exceed_vip_withdrawal_times()
    {
        $this->member->balance = 10000;
        $this->member->save();

        // 設定一般提領次數限制 3次
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT, 3);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::VIP_VISIBLE, 1);

        $mock = Mockery::mock(MemberService::class)->makePartial();
        // 設定已提領 3次
        $mock->shouldReceive('getTodayWithdrawalCount')->andReturn(3);
        // 設定VIP提領次數限制 0次
        // withdrawal_amount_limit = -1 表示不限制金額，確保 throw 來源為次數而非金額
        $mock->shouldReceive('getVipContent')->andReturn(VipContent::query()->create([
            'withdrawal_times_limit' => 0,
            'withdrawal_amount_limit' => -1,
        ]));
        $this->app->instance(MemberService::class, $mock);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1956'),
            ]);
    }

    /**
     * 測試剩餘流水需小於提款後錢包餘額
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_transfer_flow_limit
     */
    public function test_transfer_flow_limit()
    {
        // 設定剩餘流水需小於提款後錢包餘額
        $this->member->balance = 100;
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_FLOW_LIMIT_SETTING_001, 1);

        $mock = Mockery::mock(MemberService::class)->makePartial();

        // 設定剩餘流水 1000
        $mock->shouldReceive('getTransferChangeFlowLimit')->andReturn([
            'pass' => 0,
            'flow_limit' => 1000,
            'reach' => 0,
        ]);
        $this->app->instance(MemberService::class, $mock);

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1980'),
            ]);
    }

    /**
     * 無群組階梯：手續費沿用銀行，並記錄當日第幾筆與手續費來源 target/tickets#4353
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_records_withdraw_seq_and_bank_tax_source
     */
    public function test_records_withdraw_seq_and_bank_tax_source()
    {
        $this->member->balance = 100;
        $this->member->save();

        $response = $this->postCreateOrder(50);

        $response->assertStatus(200);
        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(1, (int) $order->withdraw_seq);
        $this->assertSame(WithdrawalTaxSourceEnum::BANK->value, (int) $order->tax_source);
        $this->assertEquals(0.1, $order->tax);
    }

    /**
     * 當日第幾筆只算有效單：已有一張待審核單＋一張被拒絕單 → 本筆為第 2 筆（駁回不佔位）target/tickets#4353
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_withdraw_seq_excludes_failed_orders
     */
    public function test_withdraw_seq_excludes_failed_orders()
    {
        $this->member->balance = 100;
        $this->member->save();

        WithdrawalOrder::factory()->create([
            'member_id' => $this->member->id,
            'status' => WithdrawalOrderStatusEnum::PENDING_REVIEW->value,
        ]);
        WithdrawalOrder::factory()->create([
            'member_id' => $this->member->id,
            'status' => WithdrawalOrderStatusEnum::FAILED->value,
        ]);

        $response = $this->postCreateOrder(50);

        $response->assertStatus(200);
        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, (int) $order->withdraw_seq);
    }

    /**
     * 會員群組有階梯：依當日第幾筆取階，手續費來源記為群組 target/tickets#4353
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_member_group_fee_tier_applies_by_seq
     */
    public function test_member_group_fee_tier_applies_by_seq()
    {
        $this->member->balance = 1000;
        $this->member->member_group_id = $this->createFeeTierGroup()->id;
        $this->member->save();

        // 今日已提 3 筆 → 本筆為第 4 筆，命中 from=4 的 3%
        $mock = Mockery::mock(MemberService::class)->makePartial();
        $mock->shouldReceive('getTodayWithdrawalCount')->andReturn(3);
        $this->app->instance(MemberService::class, $mock);

        $response = $this->postCreateOrder(100);

        $response->assertStatus(200);
        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(4, (int) $order->withdraw_seq);
        $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP->value, (int) $order->tax_source);
        $this->assertEquals(3, $order->tax);
    }

    /**
     * 會員群組有階梯但未達第一階：手續費 0（不回退銀行）target/tickets#4353
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_member_group_below_first_tier_is_free
     */
    public function test_member_group_below_first_tier_is_free()
    {
        $this->member->balance = 1000;
        $this->member->member_group_id = $this->createFeeTierGroup()->id;
        $this->member->save();

        $response = $this->postCreateOrder(100);

        $response->assertStatus(200);
        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(1, (int) $order->withdraw_seq);
        $this->assertSame(WithdrawalTaxSourceEnum::MEMBER_GROUP->value, (int) $order->tax_source);
        $this->assertEquals(0, $order->tax);
    }

    /**
     * 會員群組階梯手續費 >= 提現金額：擋 1958，餘額不動 target/tickets#4353
     * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderTest.php --filter test_member_group_fee_not_less_than_amount_rejected
     */
    public function test_member_group_fee_not_less_than_amount_rejected()
    {
        $this->member->balance = 1000;
        $this->member->member_group_id = $this->createFeeTierGroup([
            ['from' => 1, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 100],
        ])->id;
        $this->member->save();

        $response = $this->postCreateOrder(100);

        $response->assertStatus(400)
            ->assertJson([
                'message' => __('error_message.1958'),
            ]);
        $this->assertFalse(WithdrawalOrder::query()->where('member_id', $this->member->id)->exists());
        $this->assertEquals(1000, $this->member->refresh()->balance);
    }

    /**
     * 建立啟用中、帶平台手續費階梯的會員群組；預設兩階：第 4 筆起 3%、第 11 筆起固定 50。
     *
     * @param  array<int, array{from: int, type: int, value: int|string}>  $tiers
     */
    protected function createFeeTierGroup(array $tiers = [
        ['from' => 4, 'type' => WithdrawalFeeTierTypeEnum::PERCENT->value, 'value' => 3],
        ['from' => 11, 'type' => WithdrawalFeeTierTypeEnum::FIXED->value, 'value' => 50],
    ]): MemberGroup
    {
        return MemberGroup::query()->create([
            'agent_id' => $this->agent->id,
            'name' => 'fee-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
            'withdraw_fee_tiers' => $tiers,
        ]);
    }

    protected function postCreateOrder(int $amount)
    {
        return $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: [
                'amount' => $amount,
                'account_name' => 'tim',
                'account_no' => '123546789',
                'bank_id' => $this->bank->id,
                'bank_name' => $this->bank->name,
                'bank_code' => $this->bank->code,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );
    }

    protected function createBank(): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::BANK->value,
            'name' => 'MayBank',
            'code' => 'MayBank',
            'third_party_code' => null,
            'currency_id' => CurrencyEnum::MYR->value,
            'info' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0.1,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 99,
            'image_url' => null,
            'status' => 1,
            'remark' => 'MY',
            'admin' => 0,
        ]);
    }

    public function createMemberBindingLog()
    {
        MemberBindingLog::query()->create([
            'member_id' => $this->member->id,
            'type' => MemberBindingLogTypeEnum::UPDATE_PHONE,
            'status' => 1,
            'ip' => '0.0.0.0',
        ]);
    }
}
