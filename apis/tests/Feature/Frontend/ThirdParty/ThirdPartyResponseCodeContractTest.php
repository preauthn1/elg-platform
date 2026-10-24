<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * 鎖定 third-party 群組回應 body 的 `code` 契約：WEB_CustomerServiceSocket 的 ThirdParty.php
 * 依賴這些具體數值碼分岔 UI 行為（見 docs/ticket_4005/api-frontend-migration-4005.md 第 16-2 章），
 * HTTP 狀態碼收斂為 400 不影響這裡，body 內的 `code` 才是呼叫端實際檢查的欄位。
 */
class ThirdPartyResponseCodeContractTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        // agent_setting_defines 是 getAgentSettings() 的 join 主表，空表等於所有代理設定都取不到，
        // 本檔的 member-transfer 契約碼（20471）正是靠設定分岔。測試庫只同步 schema、業務資料各測試自理，
        // 不能指望同 process 的其他測試檔先 seed 過（並行後檔案分派每次不同，會變成間歇性失敗）
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'codecontract',
            'balance' => 100,
        ]);
    }

    public function test_success_response_body_code_is_1000()
    {
        $response = $this->requestApi('/frontend/api/third-party/agent-info', [
            'from' => $this->agent->username,
        ]);

        $this->assertSame(1000, $response->json('code'));
    }

    public function test_member_not_found_body_code_is_1007()
    {
        $response = $this->requestApi('/frontend/api/third-party/check-password', [
            'username' => $this->member->username,
            'password' => 'wrongpw',
            'password_type' => 0,
        ]);

        $this->assertSame(1007, $response->json('code'));
    }

    public function test_transaction_insufficient_balance_body_code_is_1022()
    {
        $response = $this->requestApi('/frontend/api/third-party/transaction', [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 999999,
            'tax' => 0,
            'transaction_type' => 5,
        ]);

        $this->assertSame(1022, $response->json('code'));
    }

    public function test_new_transaction_agent_insufficient_balance_body_code_is_1408()
    {
        $this->agent->is_balance_unlimited = 0;
        $this->agent->balance = 0;
        $this->agent->save();

        $response = $this->requestApi('/frontend/api/third-party/new-transaction', [
            'username' => $this->member->username,
            'agent_username' => $this->agent->username,
            'amount' => 100,
            'tax' => 0,
            'transaction_type' => 4,
        ]);

        // 舊版此情境 code=1408、message 卻沿用 error_message.1022 文字，是舊版既有的碼/訊息不對稱慣例
        $this->assertSame(1408, $response->json('code'));
        $this->assertSame(__('error_message.1022'), $response->json('message'));
    }

    public function test_member_transfer_receiver_not_realname_validated_body_code_is_20471()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED, 1);

        $operator = $this->createMemberAndToken($this->agent, [
            'username' => 'ccoperator',
            'balance' => 1000,
            'realname_validate' => 1,
        ]);
        $this->member->realname_validate = 0;
        $this->member->save();

        // 這是 apis 刻意新增的區分碼：舊版付款方/收款方未實名共用 code 2047，僅訊息文字不同；
        // apis 讓收款方分支改用獨立的 20471，方便呼叫端分辨是哪一方未通過（見程式內註解）
        $response = $this->requestApi('/frontend/api/third-party/member-transfer', [
            'operator' => $operator->username,
            'username' => $this->member->username,
            'amount' => 100,
            'tax' => 0,
        ]);

        $this->assertSame(20471, $response->json('code'));
    }
}
