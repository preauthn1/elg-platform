<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\HttpCode;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThirdPartyTransactionTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_TRANSACTION = '/frontend/api/third-party/transaction';

    const API_NEW_TRANSACTION = '/frontend/api/third-party/new-transaction';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'txmember',
            'balance' => 1000,
        ]);
    }

    public function test_transaction_deposit_success()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['balance' => 1100]]);

        $this->member->refresh();
        $this->assertEquals(1100, $this->member->balance);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => 100,
            'before_balance' => 1000,
            'balance' => 1100,
        ]);
    }

    /**
     * 迴歸：CS 後台的稅金是 `strval(red_envelopes_tax * amount)` 算出來的**字串**，稅率非 0 時
     * 帶小數（例：0.02 × 175 = "3.5"）。稅額曾被 `(int)` 轉型截成 3，導致餘額扣足額、log 少記，
     * 事後對帳對不上。餘額與 transaction_logs.tax 都必須保有 4 位精度。
     */
    public function test_transaction_deposit_keeps_decimal_tax_precision()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 175,
            'tax' => '3.5',
            'transaction_type' => 4,
        ]);

        // 開分為轉入：餘額增加 amount + tax
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->member->refresh();
        $this->assertEquals(1178.5, $this->member->balance);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => 175,
            'tax' => 3.5, // 不得被截斷成 3
            'before_balance' => 1000,
            'balance' => 1178.5,
        ]);
    }

    public function test_transaction_make_up_writes_cs_result_remark()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 50,
            'tax' => 0,
            'transaction_type' => 14,
            'operator' => 'cs_operator',
            'remark' => '補點測試',
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::MAKE_UP->value,
            'result' => 'CS補點',
            'operator' => 'cs_operator',
            'remark' => '補點測試',
        ]);
    }

    public function test_transaction_deposit_writes_flow_limit_when_audit_amount_positive()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
            'audit_amount' => 100,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'flow_limit' => 100,
        ]);
    }

    public function test_transaction_withdrawal_success()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 300,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['balance' => 700]]);

        $this->member->refresh();
        $this->assertEquals(700, $this->member->balance);
    }

    public function test_transaction_withdrawal_failed_insufficient_balance()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 5000,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1022')]);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
    }

    public function test_transaction_failed_agent_not_found()
    {
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => 'no-such-agent',
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1007')]);
    }

    public function test_transaction_receive_red_envelope_transfers_flow_limit_from_sender()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $sender = $this->createMemberAndToken($this->agent, [
            'username' => 'sender1',
            'balance' => 500,
            'first_name' => 'AAA',
        ]);
        $this->member->first_name = 'BBB';
        $this->member->save();

        FlowLimit::create([
            'member_id' => $sender->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 200,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 7,
            'operator' => $sender->username,
            'current_balance' => 500,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        // 剩餘現金(500) >= 稽核流水(200)，發送方流水不轉移（transfer_flow_limit=0）
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $sender->id,
            'flow_limit' => 200,
            'reach' => 0,
        ]);
        // 但發送方與收款方真實姓名不同，收款方仍以「紅包全額」新增一筆稽核流水
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::RECEIVE_RED_ENVELOPE->value,
            'flow_limit' => 100,
        ]);
    }

    public function test_transaction_receive_red_envelope_no_new_flow_limit_when_same_realname_and_no_transfer()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $sender = $this->createMemberAndToken($this->agent, [
            'username' => 'sender2',
            'balance' => 500,
            'first_name' => 'SAMENAME',
        ]);
        $this->member->first_name = 'SAMENAME';
        $this->member->save();

        FlowLimit::create([
            'member_id' => $sender->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 200,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 7,
            'operator' => $sender->username,
            'current_balance' => 500,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        // 同真實姓名 + 剩餘現金(500) >= 稽核流水(200) → transfer_flow_limit=0 → 不新增收款方稽核流水
        $this->assertDatabaseMissing('flow_limits', [
            'member_id' => $this->member->id,
        ]);
    }

    public function test_new_transaction_deposit_deducts_agent_balance()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['balance' => 1100]]);

        $this->agent->refresh();
        $this->assertEquals(900, $this->agent->balance);
    }

    public function test_new_transaction_deposit_failed_agent_insufficient_balance()
    {
        $this->agent->balance = 10;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1022')]);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
    }

    /**
     * 4333：客服開分不經金流也不建訂單，OrderObserver 收不到；newTransaction 要自己把小時匯總補上（sync queue 下直接落表）。
     */
    public function test_new_transaction_deposit_updates_member_hourly_statistics()
    {
        // newTransaction 開分會扣代理餘額，基底代理餘額為 0 會被 1408 擋下
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        Carbon::setTestNow('2026-03-01 14:20:00');

        try {
            $response = $this->requestApi(self::API_NEW_TRANSACTION, [
                'username' => $this->member->username,
                'agent_username' => $this->agent->username,
                'amount' => 120,
                'tax' => 0,
                'transaction_type' => TransactionEnum::DEPOSIT->value,
            ]);
            $response->assertStatus(HttpCode::HTTP_OK->value);

            $this->assertDatabaseHas('member_hourly_statistics', [
                'member_id' => $this->member->id,
                'summary_hour' => '2026-03-01 14:00:00',
                'deposit_count' => 1,
                'deposit_amount' => 120,
                // 客服開分歸「後台開分」明細（4333 六類）
                'admin_deposit_count' => 1,
                'admin_deposit_amount' => 120,
                'third_party_deposit_count' => 0,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_new_transaction_withdrawal_updates_member_hourly_statistics()
    {
        Carbon::setTestNow('2026-03-01 15:05:00');

        try {
            $response = $this->requestApi(self::API_NEW_TRANSACTION, [
                'username' => $this->member->username,
                'agent_username' => $this->agent->username,
                'amount' => 300,
                'tax' => 0,
                'transaction_type' => TransactionEnum::WITHDRAWAL->value,
            ]);
            $response->assertStatus(HttpCode::HTTP_OK->value);

            $this->assertDatabaseHas('member_hourly_statistics', [
                'member_id' => $this->member->id,
                'summary_hour' => '2026-03-01 15:00:00',
                'withdrawal_count' => 1,
                'withdrawal_amount' => 300,
                // 客服洗分歸「後台洗分」明細（4333 六類）
                'admin_withdrawal_count' => 1,
                'admin_withdrawal_amount' => 300,
                'third_party_withdrawal_count' => 0,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 補點（14）不是充值口徑（數據分析亦不計），不得進小時匯總。
     */
    public function test_new_transaction_make_up_does_not_touch_member_hourly_statistics()
    {
        // target/tickets#4535 起補點會扣代理餘額，基底代理餘額為 0 會被 1408 擋下
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 50,
            'tax' => 0,
            'transaction_type' => TransactionEnum::MAKE_UP->value,
        ]);
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseMissing('member_hourly_statistics', ['member_id' => $this->member->id]);
    }

    /**
     * 4333：活動錢包會員的帳變寫在 wallet_transaction_logs，排程重算只讀 transaction_logs（同數據分析口徑），
     * 即時管道若累加會在下一次整桶覆蓋時被抹掉；故這條路徑不得 dispatch，兩邊一致「不納入」。
     *
     * 4221：`is_event_wallet` = `is_event_v2` 或新人專享挑戰，其中 V2 參加中的開分已被
     * target/tickets#4221 擋下（見下一支測試），故本測試改用新人專享挑戰觸發 `is_event_wallet`，
     * 以維持「活動錢包會員開分不進小時匯總」這個原始意圖不受影響。
     */
    public function test_new_transaction_deposit_for_event_wallet_member_does_not_touch_member_hourly_statistics()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        // 新人專享挑戰進行中讓 Member::is_event_wallet 為真，但不觸發 target/tickets#4221 的 is_event_v2 擋
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        Carbon::setTestNow('2026-03-01 17:20:00');

        try {
            $response = $this->requestApi(self::API_NEW_TRANSACTION, [
                'username' => $this->member->username,
                'agent_username' => $this->agent->username,
                'amount' => 500,
                'tax' => 0,
                'transaction_type' => TransactionEnum::DEPOSIT->value,
            ]);
            $response->assertStatus(HttpCode::HTTP_OK->value);

            $this->assertDatabaseHas('wallet_transaction_logs', [
                'member_id' => $this->member->id,
                'type' => TransactionEnum::DEPOSIT->value,
                'point' => 500,
            ]);
            $this->assertDatabaseMissing('transaction_logs', [
                'member_id' => $this->member->id,
                'type' => TransactionEnum::DEPOSIT->value,
                'point' => 500,
            ]);
            $this->assertDatabaseMissing('member_hourly_statistics', [
                'member_id' => $this->member->id,
                'summary_hour' => '2026-03-01 17:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * target/tickets#4221：活動 V2 進行中禁止後台手動開分，避免繞過活動自己的 balance_limit/flow_limit 判斷。
     */
    public function test_new_transaction_deposit_blocked_for_event_v2_member()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        DB::table('event_v2_user_joins')->insert([
            'event_v2_detail_id' => 999901,
            'member_id' => $this->member->id,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 500,
            'tax' => 0,
            'transaction_type' => TransactionEnum::DEPOSIT->value,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.event_mode.transaction_event_v2_not_allowed')]);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
        $this->assertDatabaseMissing('wallet_transaction_logs', ['member_id' => $this->member->id]);
        $this->assertDatabaseMissing('transaction_logs', ['member_id' => $this->member->id]);
    }

    public function test_new_transaction_refuse_withdrawal_does_not_deduct_agent_balance()
    {
        $this->agent->balance = 10;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        // type=11（拒絕洗分）分數還沒加到代理身上，不應扣代理錢，即使代理餘額不足也應成功
        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 11,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->agent->refresh();
        $this->assertEquals(10, $this->agent->balance);
    }

    /**
     * target/tickets#4535：補點（14）改扣操作代理的餘額，比照開分；避免客服後台帳號無中生有加點。
     * 操作代理（agent_username）刻意不是會員所屬代理，確認扣的是操作代理、帳變也記在操作代理名下。
     */
    public function test_new_transaction_make_up_deducts_agent_balance()
    {
        $this->agent->balance = 500;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $operatingAgent = $this->createOtherAgent(['balance' => 1000, 'is_balance_unlimited' => 0]);
        $this->assertNotSame($operatingAgent->id, $this->member->parent);

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $operatingAgent->username,
            'amount' => 50,
            'tax' => 0,
            'transaction_type' => TransactionEnum::MAKE_UP->value,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['balance' => 1050]]);

        $operatingAgent->refresh();
        $this->assertEquals(950, $operatingAgent->balance);

        // 會員所屬代理不是操作代理，餘額不得異動
        $this->agent->refresh();
        $this->assertEquals(500, $this->agent->balance);

        $this->member->refresh();
        $this->assertEquals(1050, $this->member->balance);

        $this->assertSame(1, DB::table('transaction_logs')->where('member_id', $this->member->id)->count());
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $operatingAgent->id,
            'type' => TransactionEnum::MAKE_UP->value,
            'point' => 50,
            'before_balance' => 1000,
            'balance' => 1050,
        ]);
    }

    /**
     * target/tickets#4535：代理只扣 amount、會員卻加 amount + tax，補點帶非零 tax 等於繞過代理扣款加點，直接拒絕（code 9997）。
     */
    public function test_new_transaction_make_up_rejects_non_zero_tax()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 0,
            'tax' => 100,
            'transaction_type' => TransactionEnum::MAKE_UP->value,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9997')]);
        $this->assertSame(9997, $response->json('code'));

        $this->agent->refresh();
        $this->assertEquals(1000, $this->agent->balance);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
        $this->assertDatabaseMissing('transaction_logs', ['member_id' => $this->member->id]);
    }

    /**
     * target/tickets#4535：代理餘額不足時補點被拒，回應 code 1408（客服端依此顯示餘額不足），會員餘額與帳變都不得異動。
     */
    public function test_new_transaction_make_up_failed_agent_insufficient_balance()
    {
        $this->agent->balance = 10;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 50,
            'tax' => 0,
            'transaction_type' => TransactionEnum::MAKE_UP->value,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1022')]);
        $this->assertSame(1408, $response->json('code'));

        $this->agent->refresh();
        $this->assertEquals(10, $this->agent->balance);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
        $this->assertDatabaseMissing('transaction_logs', ['member_id' => $this->member->id]);
    }

    public function test_new_transaction_withdrawal_returns_balance_to_agent()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 0;
        $this->agent->withdraw_confirm = 0;
        $this->agent->save();

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 200,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['balance' => 800]]);

        $this->agent->refresh();
        $this->assertEquals(1200, $this->agent->balance);
    }

    public function test_new_transaction_deposit_writes_vip_flow_limit()
    {
        $this->agent->is_balance_unlimited = 1;
        $this->agent->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::VIP_VISIBLE, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::VIP_DEPOSIT_FLOW_LIMIT_SETTING, 2);

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
            'role_id' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('vip_flow_limits', [
            'member_id' => $this->member->id,
            'deposit_amount' => 100,
            'flow_limit' => 200,
            'status' => 0,
        ]);
    }

    public function test_new_transaction_withdrawal_failed_insufficient_balance()
    {
        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 5000,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1022')]);

        $this->member->refresh();
        $this->assertEquals(1000, $this->member->balance);
    }

    public function test_new_transaction_withdrawal_deducts_flow_limit_single_row_full_consumption()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_RATE, 1);

        FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 100,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'flow_limit' => 100,
            'reach' => 100,
            'status' => 1,
        ]);
    }

    public function test_new_transaction_withdrawal_deducts_flow_limit_across_multiple_rows()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_RATE, 1);

        $firstRow = FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 30,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $secondRow = FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 100,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // 洗分 80，第一筆 30 全額扣完（status=1），剩 50 扣進第二筆
        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 80,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $firstRow->refresh();
        $secondRow->refresh();
        $this->assertSame(30.0, (float) $firstRow->reach);
        $this->assertSame(1, $firstRow->status);
        $this->assertSame(50.0, (float) $secondRow->reach);
        $this->assertSame(0, $secondRow->status);
    }

    public function test_transaction_receive_red_envelope_transfers_flow_limit_across_multiple_rows_when_balance_insufficient()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $sender = $this->createMemberAndToken($this->agent, [
            'username' => 'sender3',
            'balance' => 500,
        ]);

        $firstRow = FlowLimit::create([
            'member_id' => $sender->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 100,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $secondRow = FlowLimit::create([
            'member_id' => $sender->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 300,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // hasFlowLimit=400，current_balance=50 < hasFlowLimit，transferFlowLimit = 400-50 = 350
        // 第一筆 100 全額扣完（status=9），剩 250 扣進第二筆
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 400,
            'tax' => 0,
            'transaction_type' => 7,
            'operator' => $sender->username,
            'current_balance' => 50,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $firstRow->refresh();
        $secondRow->refresh();
        $this->assertSame(9, $firstRow->status);
        $this->assertSame('transfer', $firstRow->remark);
        $this->assertSame(0, $secondRow->status);
        $this->assertSame(250.0, (float) $secondRow->reach);
        // after 跟著 flow_limits.reach 的 decimal(18,4) 走 4 位小數，不是 numAdd 預設的 scale 10
        $this->assertStringContainsString('transfer,after:250.0000,by:', (string) $secondRow->remark);
    }

    public function test_transaction_receive_red_envelope_keeps_flow_limit_remark_within_column_limit()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $sender = $this->createMemberAndToken($this->agent, [
            'username' => 'sender4',
            'balance' => 500,
        ]);

        // 舊系統連線 strict=false 時代的殘骸：remark 已被 MySQL 靜默截斷成剛好 100 字元（末行不完整）
        $legacyRemark = implode(PHP_EOL, [
            'transfer,after:709.1341,by:',
            'transfer,after:2709.1341,by:',
            'transfer,after:2989.0322,by:',
            'transfer,after',
        ]);
        $this->assertSame(100, mb_strlen($legacyRemark));

        $row = FlowLimit::create([
            'member_id' => $sender->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 300,
            'reach' => 0,
            'remark' => $legacyRemark,
            'status' => 0,
            'admin' => 0,
        ]);

        // hasFlowLimit=300，current_balance=50 → transferFlowLimit = 250，全數扣進這筆
        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 400,
            'tax' => 0,
            'transaction_type' => 7,
            'operator' => $sender->username,
            'current_balance' => 50,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $row->refresh();
        $this->assertSame(250.0, (float) $row->reach);
        // remark 收斂在 varchar(100) 內：最新明細完整寫入，最舊的明細被丟棄
        $this->assertLessThanOrEqual(100, mb_strlen((string) $row->remark));
        $this->assertStringContainsString('transfer,after:250.0000,by:', (string) $row->remark);
        $this->assertStringNotContainsString('transfer,after:709.1341,by:', (string) $row->remark);
    }

    public function test_transaction_receive_red_envelope_no_flow_limit_when_sender_has_no_flow_limit_record_at_all()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        // 發紅包方帳號乾淨：完全沒有任何 FlowLimit 紀錄（hasFlowLimit=0），且與收款方同真實姓名
        $sender = $this->createMemberAndToken($this->agent, [
            'username' => 'sender4',
            'balance' => 500,
            'first_name' => 'CLEANNAME',
        ]);
        $this->member->first_name = 'CLEANNAME';
        $this->member->save();

        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 7,
            'operator' => $sender->username,
            'current_balance' => 500,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        // 舊版行為：發紅包方乾淨帳號 + 同真實姓名 → 不應鎖住收款方任何稽核流水
        $this->assertDatabaseMissing('flow_limits', [
            'member_id' => $this->member->id,
        ]);
    }

    /**
     * transaction_logs.agent_id 必須記錄實際操作／扣款的代理（agent_username 解析出的那一個），
     * 而非會員自身所屬代理——欄位語意即為「同一個玩家可能由不同代理開洗分」。
     */
    public function test_transaction_writes_operating_agent_id_instead_of_member_parent()
    {
        $otherAgent = $this->createOtherAgent();

        $response = $this->requestApi(self::API_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $otherAgent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertNotSame($otherAgent->id, $this->member->parent);
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => 100,
            'agent_id' => $otherAgent->id,
        ]);
    }

    /**
     * newTransaction 同樣須記錄實際被扣款的代理：這裡 $otherAgent 才是餘額真正減少的一方。
     */
    public function test_new_transaction_writes_deducted_agent_id_instead_of_member_parent()
    {
        $otherAgent = $this->createOtherAgent(['balance' => 500]);

        $response = $this->requestApi(self::API_NEW_TRANSACTION, [
            'username' => $this->member->username,
            'agent_username' => $otherAgent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $otherAgent->refresh();
        $this->assertEquals(400, $otherAgent->balance);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => 100,
            'agent_id' => $otherAgent->id,
        ]);
    }

    /**
     * 建立另一個代理（非 $this->member 的所屬代理）；改寫 $domain 避免 agent_domains 唯一鍵衝突。
     */
    private function createOtherAgent(array $data = []): Agent
    {
        $this->domain = 'other-' . $this->domain;

        return $this->createAgentAndDomain($data);
    }
}
