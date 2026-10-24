<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\CashFlowEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\BankAccount;
use App\Models\Param;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 開洗分彈窗前置資料（target/tickets#4438，由 API_Agent system_maintenance/transactions、bank/dropdownAccountList 移植）。
 *
 * 必須成立：
 * 1. params 有啟用中的 transaction_maintains 才算維護中；停用的不算。
 * 2. 銀行帳號只回操作者主帳號支付組別、自助開分金流底下啟用中的帳號，帶 is_deposit／is_withdrawal 讓前端分兩個下拉；
 *    子帳號看的是主帳號的支付組別。
 *
 * php artisan test tests/Feature/Agent/Member/MemberTransactionOptionTest.php
 */
class MemberTransactionOptionTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->agent = $this->createAgentAndDomain();
        $this->loginAs($this->agent);
    }

    public function test_maintenance_follows_enabled_transaction_maintains_param(): void
    {
        $this->maintenance()->assertOk()->assertJsonPath('data.maintaining', false);

        $param = Param::create(['type' => 'transaction_maintains', 'parameter' => 'all', 'value' => 1, 'sort' => 0, 'status' => 0, 'remark' => '', 'admin' => 0]);
        $this->maintenance()->assertJsonPath('data.maintaining', false, '停用的維護設定不算');

        $param->update(['status' => 1]);
        $this->maintenance()->assertJsonPath('data.maintaining', true);
    }

    public function test_bank_accounts_only_from_host_cash_flow_group_auto_deposit(): void
    {
        [, , $autoDepositType] = $this->createDefaultCashFlow($this->agent, CashFlowEnum::DEPOSIT_AUTO_DEPOSIT);
        $deposit = BankAccount::factory()->create(['order_type_id' => $autoDepositType->id, 'account_nickname' => '存款帳號', 'is_deposit' => 1]);
        $withdrawal = BankAccount::factory()->create(['order_type_id' => $autoDepositType->id, 'account_nickname' => '出款帳號', 'is_withdrawal' => 1]);
        $disabled = BankAccount::factory()->create(['order_type_id' => $autoDepositType->id, 'is_deposit' => 1, 'status' => 0]);

        // 別的支付組別的自助開分帳號不該出現
        $other = Agent::factory()->create(['cash_flow_group_id' => $this->agent->cash_flow_group_id + 1000]);
        [, , $otherType] = $this->createDefaultCashFlow($other, CashFlowEnum::DEPOSIT_AUTO_DEPOSIT + 100);
        $foreign = BankAccount::factory()->create(['order_type_id' => $otherType->id, 'is_deposit' => 1]);

        $items = collect($this->bankAccounts()->assertOk()->assertJsonPath('code', 200)->json('data'));
        $ids = $items->pluck('account_id')->all();

        $this->assertEqualsCanonicalizing([$deposit->id, $withdrawal->id], $ids);
        $this->assertNotContains($disabled->id, $ids, '停用帳號不列');
        $this->assertNotContains($foreign->id, $ids, '其他支付組別不列');
        $this->assertSame(['account_id' => $deposit->id, 'account_nickname' => '存款帳號', 'is_deposit' => 1, 'is_withdrawal' => 0], $items->firstWhere('account_id', $deposit->id));
        $this->assertSame(1, $items->firstWhere('account_id', $withdrawal->id)['is_withdrawal']);

        // 子帳號看主帳號的支付組別（子帳號自己的組別即使不同也不影響）
        $sub = Agent::factory()->create(['host_id' => $this->agent->id, 'parent' => $this->agent->parent, 'cash_flow_group_id' => $this->agent->cash_flow_group_id + 2000]);
        $this->loginAs($sub);
        $this->assertEqualsCanonicalizing([$deposit->id, $withdrawal->id], collect($this->bankAccounts()->assertOk()->json('data'))->pluck('account_id')->all());
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function maintenance()
    {
        return $this->postJson('agent/api/member/transaction/maintenance', [], $this->headers);
    }

    private function bankAccounts()
    {
        return $this->postJson('agent/api/member/transaction/bank-accounts', [], $this->headers);
    }
}
