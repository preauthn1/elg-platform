<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\MemberTransferLogStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\TransactionLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class ThirdPartyMemberTransferTest extends TestCase
{
    private Agent $agent;

    private Member $operator;

    private Member $reciever;

    const API_MEMBER_TRANSFER = '/frontend/api/third-party/member-transfer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();

        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->operator = $this->createMemberAndToken($this->agent, [
            'username' => 'operator',
            'password' => '123456',
            'balance' => 100000,
        ]);

        $this->reciever = $this->createMemberAndToken($this->agent, [
            'username' => 'reciever',
            'password' => '123456',
            'balance' => 0,
        ]);

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 1);
    }

    public function test_member_transfer_success()
    {
        // Arrange
        $payoutAmount = 100;
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + $payoutAmount;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();
        $transactionLogOperator = TransactionLog::where('member_id', $this->operator->id)
            ->where('type', TransactionEnum::MEMBER_TRANSFER_OUT->value)
            ->first();
        $transactionLogReciever = TransactionLog::where('member_id', $this->reciever->id)
            ->where('type', TransactionEnum::MEMBER_TRANSFER_IN->value)
            ->first();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);
        $this->assertTrue(is_object($transactionLogOperator));
        $this->assertTrue(is_object($transactionLogReciever));

        $this->assertDatabaseHas('member_transfer_logs', [
            'member_id' => $this->reciever->id,
            'transaction_log_id' => $transactionLogReciever->id,
            'amount' => $payoutAmount,
            'tax' => 0,
            'operator' => $this->operator->id,
            'operator_transaction_log_id' => $transactionLogOperator->id,
            // 成功轉帳必須寫入成功狀態；遺漏時預設 0(失敗)，會讓報表與每日限額彙總全部漏算
            'status' => MemberTransferLogStatusEnum::SUCCESS->value,
        ]);
    }

    /**
     * 迴歸：CS 後台的稅金是 `strval(red_envelopes_tax * amount)` 算出來的**字串**，稅率非 0 時
     * 帶小數（例：0.02 × 175 = "3.5"）。TransactionLogOptionalData::$tax 曾宣告為 int，
     * 在 strict_types 下收到字串／浮點數會直接 TypeError，讓整筆轉帳 500。
     * 轉出方扣含稅毛額、收款方拿淨額，兩邊的 tax 都必須完整落庫。
     */
    public function test_member_transfer_success_with_decimal_string_tax()
    {
        // Arrange
        $payoutAmount = 175;
        $tax = '3.5';
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + 171.5;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => $tax,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertSame(1000, $response->json('code'));

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);

        $this->assertDatabaseHas('member_transfer_logs', [
            'operator' => $this->operator->id,
            'member_id' => $this->reciever->id,
            'amount' => $payoutAmount, // 含稅毛額
            'tax' => 3.5,              // 不得被截斷成 3
            'status' => MemberTransferLogStatusEnum::SUCCESS->value,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::MEMBER_TRANSFER_OUT->value,
            'point' => $payoutAmount,
            'tax' => 3.5,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->reciever->id,
            'type' => TransactionEnum::MEMBER_TRANSFER_IN->value,
            'point' => 171.5,
            'tax' => 3.5,
        ]);
    }

    /**
     * 稽核准入迴歸：代理未開啟稽核開關（FLOW_LIMIT_VISIBLE=0）時不受流水限制，轉帳必須放行。
     *
     * 遷移期間本條被寫反成「開關非 1 就擋」，等於讓所有未開稽核的白標整支端點不可用。
     */
    public function test_member_transfer_success_when_flow_limit_visible_is_zero()
    {
        // Arrange
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 0);

        $payoutAmount = 100;
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + $payoutAmount;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertSame(1000, $response->json('code'));

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);
    }

    public function test_member_transfer_success_with_flow_limit()
    {
        // Arrange
        // 稽核開關關閉，轉出方才不會被「有未達成流水一律擋轉帳」的准入判斷擋下（沿用舊版
        // FlowLimitController::checkFlowLimit 語意），得以走到 TRANSFER_CHANGE_FLOW_LIMIT 的流水轉移邏輯
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 0);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $payoutAmount = 100;
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + $payoutAmount;
        $flowLimit = 100;

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => $flowLimit,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();
        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'flow_limit' => $flowLimit,
            'reach' => 0,
        ]);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'flow_limit' => $payoutAmount,
            'reach' => 0,
        ]);
    }

    /**
     * 迴歸測試：TRANSFER_CHANGE_FLOW_LIMIT 開啟時，成功也必須回 code 1000 的 body。
     *
     * 這條路徑走完流水調整才結束，曾因遺漏 return 而回 200 空 body，呼叫端（客服系統）
     * json_decode('') 得到 null 就判為失敗，但錢其實已入帳，玩家看到失敗會重複轉帳。
     * 其他 success 測試只斷言 assertStatus(200)，空 body 一樣會過，攔不到這個情境。
     */
    public function test_member_transfer_success_with_flow_limit_response_body_code_is_1000()
    {
        // Arrange
        // 稽核開關關閉，轉出方才不會被「有未達成流水一律擋轉帳」的准入判斷擋下（沿用舊版
        // FlowLimitController::checkFlowLimit 語意），得以走到 TRANSFER_CHANGE_FLOW_LIMIT 的流水轉移邏輯
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 0);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => 100,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => 100,
            'tax' => 0,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertSame(1000, $response->json('code'));
    }

    /**
     * 迴歸測試：TRANSFER_CHANGE_FLOW_LIMIT 關閉時走提早 return，同樣要回 code 1000。
     *
     * 註：該路徑原本連 DB::commit() 都沒有（回 1000 但交易未提交，請求結束隱性回滾），
     * 測試包在外層交易內看不出差異，故此處只能守住 response contract。
     */
    public function test_member_transfer_success_without_flow_limit_response_body_code_is_1000()
    {
        // Arrange
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 0);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => 100,
            'tax' => 0,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertSame(1000, $response->json('code'));
    }

    public function test_member_transfer_success_same_first_name_balance_greater_than_flow_limit()
    {
        // Arrange
        // 稽核開關關閉，轉出方才不會被「有未達成流水一律擋轉帳」的准入判斷擋下（沿用舊版
        // FlowLimitController::checkFlowLimit 語意），得以走到 TRANSFER_CHANGE_FLOW_LIMIT 的流水轉移邏輯
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 0);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $this->operator->first_name = 'aaa';
        $this->operator->balance = 1000;
        $this->operator->save();
        $this->reciever->first_name = $this->operator->first_name;
        $this->reciever->save();

        $payoutAmount = 500;
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + $payoutAmount;
        $flowLimit = 500;

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => $flowLimit,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();
        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'flow_limit' => $flowLimit,
            'reach' => 0,
        ]);

        $this->assertDatabaseMissing('flow_limits', [
            'member_id' => $this->reciever->id,
            'type' => TransactionEnum::MEMBER_TRANSFER_IN->value,
        ]);
    }

    public function test_member_transfer_success_same_first_name_balance_greater_than_flow_limit_cause_balance_less_than_flow_limit()
    {
        // Arrange
        // 稽核開關關閉，轉出方才不會被「有未達成流水一律擋轉帳」的准入判斷擋下（沿用舊版
        // FlowLimitController::checkFlowLimit 語意），得以走到 TRANSFER_CHANGE_FLOW_LIMIT 的流水轉移邏輯
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 0);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $this->operator->first_name = 'aaa';
        $this->operator->balance = 1000;
        $this->operator->save();
        $this->reciever->first_name = $this->operator->first_name;
        $this->reciever->save();

        $payoutAmount = 600;
        $expectedOperatorBalance = $this->operator->balance - $payoutAmount;
        $expectedRecieverBalance = $this->reciever->balance + $payoutAmount;
        $originalFlowLimit = 500;
        $transferFlowLimit = $payoutAmount - $originalFlowLimit;

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => $originalFlowLimit,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();
        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertEquals($expectedOperatorBalance, $this->operator->balance);
        $this->assertEquals($expectedRecieverBalance, $this->reciever->balance);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'flow_limit' => $originalFlowLimit,
            'reach' => $transferFlowLimit,
        ]);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->reciever->id,
            'type' => TransactionEnum::MEMBER_TRANSFER_IN->value,
            'flow_limit' => $transferFlowLimit,
            'reach' => 0,
        ]);
    }

    /**
     ************************************************************************************************************************************
     *                                         Assert Failed Tests                                                                      *
     ************************************************************************************************************************************
     */
    public function test_member_transfer_failed_same_user()
    {
        // Arrange
        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->operator->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                'message' => __('error_message.9997'),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
    }

    /**
     * 稽核准入迴歸：稽核開關開啟且轉出方有未達成流水時，**即使餘額遠大於流水也必須擋**。
     *
     * 遷移期間本條曾被放寬成「餘額足以覆蓋流水就放行」，等於讓有流水在身的玩家把錢轉給乾淨帳號後
     * 由對方提出（TRANSFER_CHANGE_FLOW_LIMIT 關閉時流水不會跟著轉移），形成繞流水漏洞。
     */
    public function test_member_transfer_failed_when_operator_has_unreached_flow_limit_even_if_balance_is_enough()
    {
        // Arrange
        $flowLimit = 100;
        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance; // 100000，遠大於流水
        $beforeRecieverBalance = $this->reciever->balance;

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => $flowLimit,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                'message' => __('error_message.1980'),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
    }

    /**
     * 收款方鎖分（event_mode 非 0）不允許轉入；沿用舊版 API_Frontend memberTransfer 的檢查，
     * 遷移時整段遺失，導致鎖分中的會員仍可收到轉入款而繞過鎖分。
     */
    public function test_member_transfer_failed_when_reciever_is_in_event_mode()
    {
        // Arrange
        $this->reciever->event_mode = 1;
        $this->reciever->save();

        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $this->assertSame(9999, $response->json('code'));
        $this->assertSame(__('error_message.event_mode.transfer_in_not_allowed'), $response->json('message'));

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
        $this->assertDatabaseMissing('member_transfer_logs', ['operator' => $this->operator->id]);
    }

    /**
     * 會員群組轉帳限額（B）：轉出方所屬群組設有單筆上限時，超過即擋且不動任何餘額。
     * 限額的完整組合（下限／每日金額／每日次數）由 MemberLimitResolverTest 覆蓋，此處只驗接線。
     */
    public function test_member_transfer_failed_when_exceeding_member_group_transfer_max()
    {
        // Arrange
        $group = MemberGroup::create([
            'agent_id' => $this->agent->id,
            'name' => 'transfer-limit-group',
            'status' => 1,
            'default_setting' => 0,
            'transfer_max' => 50,
        ]);
        $group->refresh(); // transfer_max 為 decimal(18,4)，讀回 DB 值才能與訊息中的 :limit 對齊
        $this->operator->member_group_id = $group->id;
        $this->operator->save();

        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                // :limit 直出 decimal 欄位值（50.0000），與既有存款／提款群組限額訊息同慣例
                'message' => __('error_message.2075', ['limit' => $group->transfer_max]),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
        $this->assertDatabaseMissing('member_transfer_logs', ['operator' => $this->operator->id]);
    }

    /**
     * 稽核准入（沿用舊版 FlowLimitController::checkFlowLimit）：稽核開關開啟且轉出方尚有未達成流水
     * 即擋，**與餘額無關**。本例為餘額不足以覆蓋流水的情境。
     */
    public function test_member_transfer_failed_balance_less_than_flow_limit()
    {
        // Arrange
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT, 1);

        $payoutAmount = 100;
        $flowLimit = 1000000;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        FlowLimit::create([
            'member_id' => $this->operator->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => $flowLimit,
            'reach' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                'message' => __('error_message.1980'),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
    }

    public function test_member_transfer_failed_realname_validate_enabled_but_member_not_validated()
    {
        // Arrange
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED, 1);

        $this->operator->realname_validate = 0;
        $this->operator->save();

        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                'message' => __('error_message.realname_invalid.transfer'),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
    }

    public function test_member_transfer_failed_not_enough_balance()
    {
        // Arrange
        $this->operator->balance = 1;
        $this->operator->save();

        $payoutAmount = 100;
        $beforeOperatorBalance = $this->operator->balance;
        $beforeRecieverBalance = $this->reciever->balance;

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, [
            'operator' => $this->operator->username,
            'username' => $this->reciever->username,
            'amount' => $payoutAmount,
            'tax' => 0,
        ]);
        $this->operator->refresh();
        $this->reciever->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson([
                'message' => __('error_message.1022'),
            ]);

        $this->assertEquals($beforeOperatorBalance, $this->operator->balance);
        $this->assertEquals($beforeRecieverBalance, $this->reciever->balance);
    }
}
