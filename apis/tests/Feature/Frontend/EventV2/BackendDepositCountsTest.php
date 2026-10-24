<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\FlowLimitStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Language;
use App\Models\Member;
use App\Models\TransactionLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * target/tickets#4352：代理組別設置 AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT
 * 開關——後台開分（無 orders 列的 transaction_logs type=DEPOSIT）是否也視為一筆合格儲值，
 * 供含 deposit 條件的活動 V2 判斷資格。開關做在代理組別（非個別活動）。
 *
 * php artisan test tests/Feature/Frontend/EventV2/BackendDepositCountsTest.php
 */
class BackendDepositCountsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $joinPath = 'frontend/api/event-v2/join';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent, ['balance' => 1000]);

        $this->withoutMiddleware(DecryptRequest::class);
    }

    /**
     * @return array{0: EventV2Detail, 1: TransactionLog}
     */
    private function createDepositEventWithBackendDeposit(bool $isBackendDeposit, string $mainWalletDeduction = '0'): array
    {
        $this->createAgentSetting(
            $this->agent,
            AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT,
            (int) $isBackendDeposit
        );

        // event_v2_id=5 為既有 DB 種子（handler_class=DepositService，見 docs/event-v2/event-types.md）
        $detail = EventV2Detail::factory()->create([
            'id' => 90001,
            'event_v2_id' => 5,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 30',
            'condition_config' => $mainWalletDeduction !== '0' ? ['main_wallet_deduction' => $mainWalletDeduction] : null,
            'reward_formula' => '10',
            'reward_config' => null,
            'balance_formula' => null,
            'balance_config' => null,
            'withdraw_formula' => null,
            'withdraw_config' => null,
            'flow_formula' => null,
            'flow_config' => null,
        ]);

        $lang = Language::create(['name' => '繁體中文', 'code' => 'zh-TW', 'admin' => 0]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => $lang->id,
            'name' => 'test',
            'image' => 'image',
            'reward_name' => 'test',
            'content' => 'test',
        ]);

        // 模擬 CS 後台 newTransaction() type=DEPOSIT 建立的紀錄：沒有 orders 列
        $transactionLog = TransactionLog::create([
            'transaction_no' => 'ut_' . uniqid(),
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => 50,
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => 1000,
            'balance' => 1050,
            'vendor_balance' => 0,
            'status' => 1000,
            'remark' => '',
            'result' => '',
            'operator' => 'cs_operator',
            'admin' => 0,
        ]);

        if ($mainWalletDeduction !== '0') {
            FlowLimit::create([
                'member_id' => $this->member->id,
                'type' => TransactionEnum::DEPOSIT->value,
                'event_id' => 0,
                'reference_id' => $transactionLog->id,
                'flow_limit' => 50,
                'reach' => 0,
                'status' => FlowLimitStatusEnum::PENDING->value,
                'admin' => 0,
            ]);
        }

        return [$detail, $transactionLog];
    }

    public function test_join_blocked_when_is_backend_deposit_disabled()
    {
        $this->createDepositEventWithBackendDeposit(isBackendDeposit: false);

        $response = $this->requestApi($this->joinPath, ['event_detail_id' => 90001]);

        // 開關關閉時，後台開分不算儲值，沒有其他真實儲值訂單 → 條件不足
        $response->assertStatus(400);
        $this->assertDatabaseMissing('event_v2_user_joins', [
            'event_v2_detail_id' => 90001,
            'member_id' => $this->member->id,
        ]);
    }

    public function test_join_succeeds_with_backend_deposit_when_enabled()
    {
        [, $transactionLog] = $this->createDepositEventWithBackendDeposit(isBackendDeposit: true);

        $response = $this->requestApi($this->joinPath, ['event_detail_id' => 90001]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('event_v2_user_joins', [
            'event_v2_detail_id' => 90001,
            'member_id' => $this->member->id,
            'order_id' => 0,
            'deposit_transaction_log_id' => $transactionLog->id,
        ]);
    }

    public function test_join_deducts_flow_limit_from_backend_deposit_when_main_wallet_deduction_set()
    {
        [, $transactionLog] = $this->createDepositEventWithBackendDeposit(isBackendDeposit: true, mainWalletDeduction: '20');

        $response = $this->requestApi($this->joinPath, ['event_detail_id' => 90001]);

        $response->assertStatus(200);

        $userJoin = EventV2UserJoin::where('event_v2_detail_id', 90001)->where('member_id', $this->member->id)->first();
        $this->assertNotNull($userJoin);
        $this->assertEquals($transactionLog->id, $userJoin->deposit_transaction_log_id);

        // main_wallet_deduction=20 應扣進這筆後台開分對應的 FlowLimit（reference_id=transactionLog->id）
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'reference_id' => $transactionLog->id,
            'reach' => 20,
        ]);
    }
}
