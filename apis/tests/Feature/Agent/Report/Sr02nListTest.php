<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report;

use App\Enums\Agent\RoleEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Agent\Report\Concerns\Sr02nFixtures;
use Tests\TestCase;

/**
 * SR02N 開洗分明細表列表（target/tickets#4356）。
 *
 * 組織：總代 ga（登入者）─ 客服子帳號 cs、role 0 子帳號 gaAcc、代理 agent ─ 代理 agent2；平行代理 peer。
 *
 * php artisan test tests/Feature/Agent/Report/Sr02nListTest.php
 */
class Sr02nListTest extends TestCase
{
    use Sr02nFixtures;

    private Agent $ga;

    private Agent $cs;

    private Agent $gaAcc;

    private Agent $agent;

    private Agent $agent2;

    private Agent $peer;

    private Member $gaMember;

    private Member $agentMember;

    private Member $agent2Member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSr02n();

        $this->ga = $this->createTopAgent(RoleEnum::GENERAL_AGENT->value, withDomain: true);
        $this->cs = $this->createSubAccount($this->ga, RoleEnum::CUSTOMER_SERVICE->value);
        $this->gaAcc = $this->createSubAccount($this->ga);
        $this->agent = $this->createSubAgent($this->ga);
        $this->agent2 = $this->createSubAgent($this->agent, RoleEnum::AGENT->value);
        $this->peer = $this->createSubAgent($this->ga);

        $this->gaMember = $this->createSr02nMember($this->ga);
        $this->agentMember = $this->createSr02nMember($this->agent);
        $this->agent2Member = $this->createSr02nMember($this->agent2);

        $this->grantSr02nMenu($this->ga);
        $this->loginAs($this->ga);
    }

    #[Group('rules/report-sr02n#R-1')]
    #[Group('rules/report-sr02n#R-15')]
    #[Group('rules/report-sr02n#R-29')]
    #[TestDox('全不勾：四段各一列，依時間新到舊')]
    public function test_no_pairs_returns_all_segments_sorted_desc(): void
    {
        $main = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id, 'created_at' => '2026-09-10 02:00:00']);
        $game = $this->gameLog($this->gaMember, ['agent_id' => $this->ga->id, 'created_at' => '2026-09-10 03:00:00']);
        $credit = $this->agentCreditLog($this->ga, $this->agent, ['created_at' => '2026-09-10 04:00:00']);
        $wallet = $this->agentWalletLog($this->gaAcc, $this->agent, ['created_at' => '2026-09-10 05:00:00']);

        $items = $this->listItems();

        $this->assertSame(
            ["agent_wallet:{$wallet}", "agent_credit:{$credit}", "game:{$game}", "main:{$main}"],
            array_map(static fn (array $i): string => $i['segment'] . ':' . $i['id'], $items),
        );
        $this->assertSame([2, 2], [$items[0]['platform'], $items[0]['wallet_type']]);
        $this->assertSame([1, 2], [$items[2]['platform'], $items[2]['wallet_type']]);
        $this->assertSame($this->agent->username, $items[1]['to_agent_username']);
        $this->assertSame('transfer_to_agent', $items[1]['type']);
        $this->assertSame('30.0000', $items[1]['point']);
        $this->assertSame('900.0000', $items[1]['balance']);
        // 遊戲錢包 6（代理後台開分）顯示為 4
        $this->assertSame(4, $items[2]['type']);
    }

    #[Group('rules/report-sr02n#R-17')]
    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('總代對會員：命中總代線下任一會員，含 role 0 子帳號以主帳號身分操作；代理操作不命中')]
    public function test_pair_ga_member(): void
    {
        $byGa = $this->mainLog($this->agent2Member, ['agent_id' => $this->ga->id]);
        $byGaAcc = $this->mainLog($this->agentMember, ['agent_id' => $this->gaAcc->id]);
        $byAgent = $this->mainLog($this->agentMember, ['agent_id' => $this->agent->id]);

        $keys = $this->listKeys(['pairs' => ['ga_member']]);

        $this->assertEqualsCanonicalizing(["main:{$byGa}", "main:{$byGaAcc}"], $keys);
        $this->assertNotContains("main:{$byAgent}", $keys);
    }

    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('客服對會員：只命中客服子帳號操作')]
    public function test_pair_cs_member(): void
    {
        $byCs = $this->mainLog($this->agentMember, ['agent_id' => $this->cs->id]);
        $byGa = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);
        $gameByCs = $this->gameLog($this->gaMember, ['agent_id' => $this->cs->id]);

        $keys = $this->listKeys(['pairs' => ['cs_member']]);

        $this->assertEqualsCanonicalizing(["main:{$byCs}", "game:{$gameByCs}"], $keys);
        $this->assertNotContains("main:{$byGa}", $keys);
    }

    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('代理對會員：只命中直屬會員，代理對下線會員不命中')]
    public function test_pair_agent_member_direct_only(): void
    {
        $direct = $this->mainLog($this->agentMember, ['agent_id' => $this->agent->id]);
        $downline = $this->mainLog($this->agent2Member, ['agent_id' => $this->agent->id]);

        $keys = $this->listKeys(['pairs' => ['agent_member']]);

        $this->assertSame(["main:{$direct}"], $keys);
        $this->assertNotContains("main:{$downline}", $keys);
        // 下線會員那列只在全不勾出現
        $this->assertContains("main:{$downline}", $this->listKeys());
    }

    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('總代對客服：對象為客服子帳號才命中')]
    public function test_pair_ga_cs(): void
    {
        $toCs = $this->agentCreditLog($this->ga, $this->cs);
        $toAgent = $this->agentCreditLog($this->ga, $this->agent);

        $this->assertSame(["agent_credit:{$toCs}"], $this->listKeys(['pairs' => ['ga_cs']]));
        $this->assertSame(["agent_credit:{$toAgent}"], $this->listKeys(['pairs' => ['ga_agent']]));
    }

    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('客服對代理：操作者為客服子帳號、對象為線下代理')]
    public function test_pair_cs_agent(): void
    {
        $byCs = $this->agentCreditLog($this->cs, $this->agent2);
        $byGa = $this->agentCreditLog($this->ga, $this->agent2);

        $this->assertSame(["agent_credit:{$byCs}"], $this->listKeys(['pairs' => ['cs_agent']]));
        $this->assertSame(["agent_credit:{$byGa}"], $this->listKeys(['pairs' => ['ga_agent']]));
    }

    #[Group('rules/report-sr02n#R-18')]
    #[TestDox('代理對代理：對象須在操作者路徑下，平行代理不命中')]
    public function test_pair_agent_agent_excludes_peer(): void
    {
        $toDownline = $this->agentCreditLog($this->agent, $this->agent2);
        $toPeer = $this->agentCreditLog($this->agent, $this->peer);

        $keys = $this->listKeys(['pairs' => ['agent_agent'], 'agent_username' => $this->agent->username]);

        $this->assertSame(["agent_credit:{$toDownline}"], $keys);
        $this->assertNotContains("agent_credit:{$toPeer}", $keys);
    }

    #[Group('rules/report-sr02n#R-17')]
    #[TestDox('對象為子帳號時依其自身角色（2026-09-30 定案）：總代 → 自己的 role 0 子帳號＝總代對代理、role 12 子帳號＝總代對客服')]
    public function test_target_sub_account_uses_own_role_for_general_agent(): void
    {
        $toOwnAgentAcc = $this->agentCreditLog($this->ga, $this->gaAcc);
        $toOwnCs = $this->agentCreditLog($this->ga, $this->cs);

        $gaAgent = $this->listKeys(['pairs' => ['ga_agent']]);
        $gaCs = $this->listKeys(['pairs' => ['ga_cs']]);

        $this->assertContains("agent_credit:{$toOwnAgentAcc}", $gaAgent);
        $this->assertNotContains("agent_credit:{$toOwnAgentAcc}", $gaCs);
        $this->assertContains("agent_credit:{$toOwnCs}", $gaCs);
        $this->assertNotContains("agent_credit:{$toOwnCs}", $gaAgent);
    }

    #[Group('rules/report-sr02n#R-17')]
    #[TestDox('對象為子帳號時依其自身角色：一般代理 → 自己的 role 0 子帳號＝代理對代理；客服 → 總代的 role 0 子帳號＝客服對代理')]
    public function test_target_sub_account_uses_own_role_for_agent_and_cs(): void
    {
        $agentAcc = $this->createSubAccount($this->agent);
        $agentToOwnAcc = $this->agentCreditLog($this->agent, $agentAcc);
        $csToGaAcc = $this->agentCreditLog($this->cs, $this->gaAcc);

        $this->assertContains(
            "agent_credit:{$agentToOwnAcc}",
            $this->listKeys(['pairs' => ['agent_agent'], 'agent_username' => $this->agent->username]),
        );
        $this->assertContains("agent_credit:{$csToGaAcc}", $this->listKeys(['pairs' => ['cs_agent']]));
    }

    #[Group('rules/report-sr02n#R-15')]
    #[Group('rules/report-sr02n#R-19')]
    #[TestDox('系統派發（agent_id=0）只在全不勾出現；多配對為 OR')]
    public function test_system_rows_only_without_pairs_and_pairs_are_or(): void
    {
        $system = $this->mainLog($this->gaMember, ['agent_id' => 0, 'type' => TransactionEnum::ACTIVITY_AWARD->value]);
        $byGa = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);
        $byAgent = $this->mainLog($this->agentMember, ['agent_id' => $this->agent->id]);

        $this->assertEqualsCanonicalizing(
            ["main:{$system}", "main:{$byGa}", "main:{$byAgent}"],
            $this->listKeys(),
        );
        $this->assertEqualsCanonicalizing(
            ["main:{$byGa}", "main:{$byAgent}"],
            $this->listKeys(['pairs' => ['ga_member', 'agent_member']]),
        );
    }

    #[Group('rules/report-sr02n#R-19')]
    #[TestDox('三方充值列（agent_id＝會員上層總代、operator＝會員帳號）只在全不勾出現，不算總代對會員')]
    public function test_third_party_deposit_not_tagged_as_ga_member(): void
    {
        $cf = $this->cashFlow();
        $order = $this->depositOrder($this->gaMember, $this->merchant($cf), 7001);
        $deposit = $this->mainLog($this->gaMember, [
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'order_id' => $order->id,
            'agent_id' => $this->ga->id,
            'operator' => $this->gaMember->username,
        ]);
        $byGa = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);

        $this->assertContains("main:{$deposit}", $this->listKeys());
        $this->assertSame(["main:{$byGa}"], $this->listKeys(['pairs' => ['ga_member']]));
    }

    #[Group('rules/report-sr02n#R-19')]
    #[TestDox('系統獎勵列（agent_id＝上層代理、operator＝system）只在全不勾出現，不算任何會員配對')]
    public function test_system_reward_not_tagged_by_parent_agent(): void
    {
        $reward = $this->mainLog($this->agentMember, [
            'type' => TransactionEnum::FIRST_DEPOSIT_REWARDS->value,
            'agent_id' => $this->agent->id,
            'operator' => 'system',
        ]);
        $gameDeposit = $this->gameLog($this->agentMember, [
            'type' => 5,
            'agent_id' => $this->agent->id,
            'operator' => '',
        ]);

        $this->assertContains("main:{$reward}", $this->listKeys());
        $this->assertContains("game:{$gameDeposit}", $this->listKeys());
        foreach (['ga_member', 'cs_member', 'agent_member'] as $pair) {
            $keys = $this->listKeys(['pairs' => [$pair]]);
            $this->assertNotContains("main:{$reward}", $keys, $pair);
            $this->assertNotContains("game:{$gameDeposit}", $keys, $pair);
        }
    }

    #[Group('rules/report-sr02n#R-20')]
    #[TestDox('只勾代理配對時會員兩段不併；只勾會員配對時代理兩段不併')]
    public function test_segments_skipped_without_applicable_pairs(): void
    {
        $main = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);
        $credit = $this->agentCreditLog($this->ga, $this->agent);

        $this->assertSame(["agent_credit:{$credit}"], $this->listKeys(['pairs' => ['ga_agent']]));
        $this->assertSame(["main:{$main}"], $this->listKeys(['pairs' => ['ga_member']]));
    }

    #[Group('rules/report-sr02n#R-16')]
    #[TestDox('非 Admin 線帶 admin 配對 → 400')]
    public function test_admin_pair_rejected_for_non_admin(): void
    {
        $response = $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams(['pairs' => ['admin']]));

        $response->assertStatus(400)->assertJsonPath('code', 400);
    }

    #[Group('rules/report-sr02n#R-15')]
    #[Group('rules/report-sr02n#R-16')]
    #[TestDox('Admin 子帳號帶 admin：只回 Admin 線操作的列；總代登入時 Admin 線的列只在全不勾出現')]
    public function test_admin_pair_for_admin_sub_account(): void
    {
        $adminAcc = $this->adminSubAccount();

        $byAdmin = $this->mainLog($this->agentMember, ['agent_id' => $adminAcc->id]);
        $byGa = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);
        $adminCredit = $this->agentCreditLog($adminAcc, $this->ga);

        // 總代視角：Admin 線的列不屬任何非 admin 配對
        $this->assertContains("main:{$byAdmin}", $this->listKeys());
        $this->assertSame(["main:{$byGa}"], $this->listKeys(['pairs' => ['ga_member']]));

        $this->headers = [];
        $this->loginAs($adminAcc);

        $this->assertEqualsCanonicalizing(
            ["main:{$byAdmin}", "agent_credit:{$adminCredit}"],
            $this->listKeys(['pairs' => ['admin']]),
        );
    }

    #[Group('rules/report-sr02n#R-22')]
    #[TestDox('來源存款：支付商／商戶／通道三層各自過濾')]
    public function test_deposit_source_layers(): void
    {
        $cf = $this->cashFlow();
        $otherCf = $this->cashFlow();
        $m1 = $this->merchant($cf);
        $m2 = $this->merchant($cf);
        $m3 = $this->merchant($otherCf);

        $o1 = $this->depositOrder($this->gaMember, $m1, 7001);
        $o2 = $this->depositOrder($this->gaMember, $m2, 7002);
        $o3 = $this->depositOrder($this->gaMember, $m3, 7003);
        $l1 = $this->mainLog($this->gaMember, ['type' => TransactionEnum::ORDER_DEPOSIT->value, 'order_id' => $o1->id, 'agent_id' => 0]);
        $l2 = $this->mainLog($this->gaMember, ['type' => TransactionEnum::ORDER_DEPOSIT->value, 'order_id' => $o2->id, 'agent_id' => 0]);
        $this->mainLog($this->gaMember, ['type' => TransactionEnum::ORDER_DEPOSIT->value, 'order_id' => $o3->id, 'agent_id' => 0]);
        $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);

        $this->assertEqualsCanonicalizing(["main:{$l1}", "main:{$l2}"], $this->listKeys(['source' => 3, 'cash_flow_id' => $cf->id]));
        $this->assertSame(["main:{$l1}"], $this->listKeys(['source' => 3, 'cash_flow_id' => $cf->id, 'agent_cash_flow_id' => $m1->id]));
        $this->assertSame(["main:{$l2}"], $this->listKeys(['source' => 3, 'cash_flow_id' => $cf->id, 'order_type_id' => 7002]));

        $items = $this->listItems(['source' => 3, 'cash_flow_id' => $cf->id, 'agent_cash_flow_id' => $m1->id]);
        $this->assertSame('third_party_deposit', $items[0]['type']);
        $this->assertSame('cash_flow', $items[0]['source_kind']);
        $this->assertSame($o1->order_no, $items[0]['transaction_no']);
    }

    #[Group('rules/report-sr02n#R-23')]
    #[TestDox('來源取款：依 wpr.agent_cash_flow_id 過濾商戶；自助洗分（6）不比 wpr.cash_flow_id；帶通道 → 400')]
    public function test_payout_source_layers(): void
    {
        $cf = $this->cashFlow(type: 3);
        $mw1 = $this->merchant($cf);
        $mw2 = $this->merchant($cf);

        $w1 = $this->withdrawalOrder($this->gaMember->id);
        $w2 = $this->withdrawalOrder($this->gaMember->id);
        $w3 = $this->withdrawalOrder($this->gaMember->id);
        $this->payoutRecord($w1, $cf->id, $mw1->id);
        $this->payoutRecord($w2, $cf->id, $mw2->id);
        $l1 = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w1->id]);
        $l2 = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w2->id]);
        $l3 = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w3->id]);

        $this->assertEqualsCanonicalizing(["main:{$l1}", "main:{$l2}"], $this->listKeys(['source' => 4, 'cash_flow_id' => $cf->id]));
        $this->assertSame(["main:{$l1}"], $this->listKeys(['source' => 4, 'cash_flow_id' => $cf->id, 'agent_cash_flow_id' => $mw1->id]));
        $this->assertEqualsCanonicalizing(
            ["main:{$l1}", "main:{$l2}", "main:{$l3}"],
            $this->listKeys(['source' => 4, 'cash_flow_id' => 6]),
        );

        $items = collect($this->listItems(['source' => 4, 'cash_flow_id' => 6]))->keyBy('id');
        $this->assertSame('third_party_payout', $items[$l1]['type']);
        $this->assertSame('cs_payout', $items[$l3]['type']);
        $this->assertSame('customer_service', $items[$l3]['source_kind']);

        $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams(['source' => 4, 'cash_flow_id' => $cf->id, 'order_type_id' => 1]))
            ->assertStatus(400);
    }

    #[Group('rules/report-sr02n#R-2')]
    #[Group('rules/report-sr02n#R-23')]
    #[TestDox('來源取款預付網（18）：走型別 22＋ota_orders，商戶比 oo.agent_cash_flow_id')]
    public function test_payout_source_ota(): void
    {
        $ota = $this->otaOrder($this->gaMember, 555);
        $l = $this->mainLog($this->gaMember, ['type' => TransactionEnum::OTA_DEDUCTION->value, 'order_id' => $ota]);

        $this->assertSame(["main:{$l}"], $this->listKeys(['source' => 4, 'cash_flow_id' => 18]));
        $this->assertSame([], $this->listKeys(['source' => 4, 'cash_flow_id' => 18, 'agent_cash_flow_id' => 556]));
        $this->assertSame('ota', $this->listItems(['source' => 4, 'cash_flow_id' => 18])[0]['source_kind']);
    }

    #[Group('rules/report-sr02n#R-7')]
    #[Group('rules/report-sr02n#R-8')]
    #[TestDox('手續費：會員提款列 tax×rate、非提款列兩欄 null；代理自助洗分列同理')]
    public function test_withdrawal_fee_columns(): void
    {
        $w = $this->withdrawalOrder($this->gaMember->id, ['tax' => '2.5', 'rate' => '1.2']);
        $withdraw = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id, 'point' => '100.0000']);
        $deposit = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);
        $aw = $this->withdrawalOrder(0, ['tax' => '1', 'rate' => '2']);
        $selfWithdraw = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $aw->id, 'amount' => '50.0000']);

        $items = collect($this->listItems())->keyBy(static fn (array $i): string => $i['segment'] . ':' . $i['id']);

        $this->assertSame(['3.0000', '97.0000'], [$items["main:{$withdraw}"]['tax'], $items["main:{$withdraw}"]['actual_point']]);
        $this->assertSame([null, null], [$items["main:{$deposit}"]['tax'], $items["main:{$deposit}"]['actual_point']]);
        $this->assertSame(['2.0000', '48.0000'], [$items["agent_credit:{$selfWithdraw}"]['tax'], $items["agent_credit:{$selfWithdraw}"]['actual_point']]);
        $this->assertSame('deduct_from_agent', $items["agent_credit:{$selfWithdraw}"]['type']);
    }

    #[Group('rules/report-sr02n#R-7')]
    #[Group('rules/report-sr02n#R-9')]
    #[TestDox('PM 定案 B：無提款單的洗分列（客服洗分 5、扣分 36、代理洗分 2、遊戲錢包扣分 9）手續費 0、實際金額＝變更點數；開分列仍 null')]
    public function test_withdrawal_rows_without_order_have_zero_fee(): void
    {
        $csWithdraw = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::WITHDRAWAL->value, 'point' => '12.3400']);
        $deduct = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::DEDUCT->value, 'point' => '8.0000']);
        $deposit = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);
        $refused = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::REFUSE_TO_WITHDRAWAL->value]);
        $agentWithdraw = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'amount' => '25.0000']);
        $agentDeposit = $this->agentCreditLog($this->ga, $this->agent);
        $gameDeduct = $this->gameLog($this->gaMember, ['type' => 9, 'point' => '6.0000']);
        $walletIn = $this->agentWalletLog($this->gaAcc, $this->agent);

        $items = collect($this->listItems())->keyBy(static fn (array $i): string => $i['segment'] . ':' . $i['id']);
        $money = static fn (string $key): array => [$items[$key]['tax'], $items[$key]['actual_point']];

        $this->assertSame(['0.0000', '12.3400'], $money("main:{$csWithdraw}"));
        $this->assertSame(['0.0000', '8.0000'], $money("main:{$deduct}"));
        $this->assertSame(['0.0000', '25.0000'], $money("agent_credit:{$agentWithdraw}"));
        $this->assertSame(['0.0000', '6.0000'], $money("game:{$gameDeduct}"));
        foreach (["main:{$deposit}", "main:{$refused}", "agent_credit:{$agentDeposit}", "agent_wallet:{$walletIn}"] as $key) {
            $this->assertSame([null, null], $money($key), $key);
        }
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分（target/tickets#4493）：拒絕洗分只沖之前最近一筆同會員同點數的無單洗分；拒絕洗分列本身仍 null')]
    public function test_refused_cs_withdrawal_is_rejected(): void
    {
        $a = $this->csWithdrawal($this->gaMember, '12.3400');
        $b = $this->csWithdrawal($this->gaMember, '12.3400');
        $c = $this->csWithdrawal($this->gaMember, '50.0000');
        $refused = $this->refusal($this->gaMember, '12.3400');

        $money = $this->moneyColumns();

        $this->assertSame(['0.0000', '12.3400'], $money["main:{$a}"]);
        $this->assertSame([null, null], $money["main:{$b}"]);
        $this->assertSame(['0.0000', '50.0000'], $money["main:{$c}"]);
        $this->assertSame([null, null], $money["main:{$refused}"]);
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：同一筆洗分被重複拒絕，只沖最近那一列一次')]
    public function test_duplicate_refusals_cancel_one_row(): void
    {
        $a = $this->csWithdrawal($this->gaMember, '12.3400');
        $b = $this->csWithdrawal($this->gaMember, '12.3400');
        $this->refusal($this->gaMember, '12.3400');
        $this->refusal($this->gaMember, '12.3400');

        $money = $this->moneyColumns();

        $this->assertSame(['0.0000', '12.3400'], $money["main:{$a}"]);
        $this->assertSame([null, null], $money["main:{$b}"]);
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：點數不同、或別的會員的拒絕洗分都不沖；扣分列（36）也不被沖')]
    public function test_refusal_requires_same_member_and_point(): void
    {
        $withdraw = $this->csWithdrawal($this->gaMember, '12.3400');
        $deduct = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::DEDUCT->value, 'point' => '13.0000']);
        $this->refusal($this->gaMember, '13.0000');
        $this->refusal($this->agentMember, '12.3400');

        $money = $this->moneyColumns();

        $this->assertSame(['0.0000', '12.3400'], $money["main:{$withdraw}"]);
        $this->assertSame(['0.0000', '13.0000'], $money["main:{$deduct}"]);
    }

    #[Group('rules/report-sr02n#R-39')]
    #[TestDox('被拒絕的洗分：會員提款單、代理自助洗分單最終拒絕（2）／退款（9）→ 兩欄 null；已轉帳（4）照算')]
    public function test_rejected_withdrawal_orders(): void
    {
        $refunded = $this->withdrawalOrder($this->gaMember->id, ['tax' => '2.5', 'rate' => '1.2', 'status' => WithdrawalOrderStatusEnum::REFUND->value]);
        $refundedLog = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $refunded->id, 'point' => '100.0000']);
        $paid = $this->withdrawalOrder($this->gaMember->id, ['tax' => '2.5', 'rate' => '1.2', 'status' => WithdrawalOrderStatusEnum::TRANSFERRED->value]);
        $paidLog = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $paid->id, 'point' => '100.0000']);

        $failedAw = $this->withdrawalOrder(0, ['tax' => '1', 'rate' => '2', 'status' => WithdrawalOrderStatusEnum::FAILED->value]);
        $failedAwLog = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $failedAw->id, 'amount' => '50.0000']);
        $paidAw = $this->withdrawalOrder(0, ['tax' => '1', 'rate' => '2', 'status' => WithdrawalOrderStatusEnum::TRANSFERRED->value]);
        $paidAwLog = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $paidAw->id, 'amount' => '50.0000']);

        $money = $this->moneyColumns();

        $this->assertSame([null, null], $money["main:{$refundedLog}"]);
        $this->assertSame(['3.0000', '97.0000'], $money["main:{$paidLog}"]);
        $this->assertSame([null, null], $money["agent_credit:{$failedAwLog}"]);
        $this->assertSame(['2.0000', '48.0000'], $money["agent_credit:{$paidAwLog}"]);
    }

    #[Group('rules/report-sr02n#R-39')]
    #[TestDox('被拒絕的洗分：取款訂單非最終狀態（審核中 1、三方失敗 29）結果未定，手續費／實際金額照算')]
    public function test_non_final_withdrawal_orders_still_counted(): void
    {
        $logs = [];
        foreach ([WithdrawalOrderStatusEnum::PENDING_REVIEW, WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED] as $status) {
            $order = $this->withdrawalOrder($this->gaMember->id, ['tax' => '2.5', 'rate' => '1.2', 'status' => $status->value]);
            $logs[$status->name] = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $order->id, 'point' => '100.0000']);
        }

        $money = $this->moneyColumns();

        foreach ($logs as $status => $id) {
            $this->assertSame(['3.0000', '97.0000'], $money["main:{$id}"], $status);
        }
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：拒絕洗分在洗分之前（r.id < l.id）不沖')]
    public function test_refusal_before_withdrawal_does_not_reject(): void
    {
        $this->refusal($this->gaMember, '12.3400');
        $withdraw = $this->csWithdrawal($this->gaMember, '12.3400');

        $this->assertSame(['0.0000', '12.3400'], $this->moneyColumns()["main:{$withdraw}"]);
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：拒絕洗分列狀態不是成功（1000）時不沖')]
    public function test_unsuccessful_refusal_does_not_reject(): void
    {
        $withdraw = $this->csWithdrawal($this->gaMember, '12.3400');
        $this->mainLog($this->gaMember, [
            'type' => TransactionEnum::REFUSE_TO_WITHDRAWAL->value,
            'point' => '12.3400',
            'status' => TransactionStatusEnum::STATUS_REJECT->value,
        ]);

        $this->assertSame(['0.0000', '12.3400'], $this->moneyColumns()["main:{$withdraw}"]);
    }

    #[Group('rules/report-sr02n#R-41')]
    #[TestDox('被拒絕的洗分：不設時間窗，拒絕洗分落在報表期間之後仍沖掉期間內的洗分列')]
    public function test_refusal_after_period_still_rejects(): void
    {
        $withdraw = $this->csWithdrawal($this->gaMember, '12.3400');
        // 期間 +8 牆鐘到 2026-09-10 23:59:59 ＝ UTC 09-10 15:59:59；此列在期間外
        $refused = $this->mainLog($this->gaMember, [
            'type' => TransactionEnum::REFUSE_TO_WITHDRAWAL->value,
            'point' => '12.3400',
            'created_at' => '2026-09-12 02:00:00',
            'updated_at' => '2026-09-12 02:00:00',
        ]);

        $money = $this->moneyColumns();

        $this->assertSame([null, null], $money["main:{$withdraw}"]);
        $this->assertArrayNotHasKey("main:{$refused}", $money);
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：洗分 A → 拒絕 → 洗分 B → 拒絕（同會員同點數），A、B 都被沖')]
    public function test_alternating_refusals_reject_each_withdrawal(): void
    {
        $a = $this->csWithdrawal($this->gaMember, '12.3400');
        $this->refusal($this->gaMember, '12.3400');
        $b = $this->csWithdrawal($this->gaMember, '12.3400');
        $this->refusal($this->gaMember, '12.3400');

        $money = $this->moneyColumns();

        $this->assertSame([null, null], $money["main:{$a}"]);
        $this->assertSame([null, null], $money["main:{$b}"]);
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：有取款訂單的洗分列（order_id≠0）不參與拒絕洗分配對')]
    public function test_withdrawal_with_order_not_paired_with_refusal(): void
    {
        $order = $this->withdrawalOrder($this->gaMember->id, ['tax' => '1', 'rate' => '1', 'status' => WithdrawalOrderStatusEnum::TRANSFERRED->value]);
        $a = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $order->id, 'point' => '12.3400']);
        $b = $this->csWithdrawal($this->gaMember, '12.3400');
        $this->refusal($this->gaMember, '12.3400');

        $money = $this->moneyColumns();

        $this->assertSame(['1.0000', '11.3400'], $money["main:{$a}"]);
        $this->assertSame([null, null], $money["main:{$b}"]);
    }

    #[Group('rules/report-sr02n#R-22')]
    #[Group('rules/report-sr02n#R-23')]
    #[Group('rules/report-sr02n#R-24')]
    #[TestDox('代理兩段：有會員／單號條件或來源存款／取款時不併；來源取款時遊戲錢包不併')]
    public function test_segment_enable_rules(): void
    {
        $main = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);
        $game = $this->gameLog($this->gaMember);
        $this->agentCreditLog($this->ga, $this->agent);
        $this->agentWalletLog($this->gaAcc, $this->agent);

        $this->assertEqualsCanonicalizing(
            ["main:{$main}", "game:{$game}"],
            $this->listKeys(['member_username' => $this->gaMember->username]),
        );

        foreach ([['member_username' => $this->gaMember->username], ['member_nickname' => $this->gaMember->nickname], ['transaction_no' => 'nope'], ['source' => 3], ['source' => 4]] as $filter) {
            $segments = array_map(static fn (string $k): string => explode(':', $k)[0], $this->listKeys($filter));
            $this->assertNotContains('agent_credit', $segments, json_encode($filter));
            $this->assertNotContains('agent_wallet', $segments, json_encode($filter));
        }

        $this->assertNotContains("game:{$game}", $this->listKeys(['source' => 4, 'cash_flow_id' => 6]));
        $this->assertContains("game:{$game}", $this->listKeys(['source' => 1]));
    }

    #[Group('rules/report-sr02n#R-27')]
    #[Group('rules/report-sr02n#R-28')]
    #[TestDox('時區：UTC 16:30 寫入 → +8 隔日命中並回 00:30；is_created_period 改用 orders.created_at')]
    public function test_timezone_and_created_period(): void
    {
        $hit = $this->mainLog($this->gaMember, ['created_at' => '2026-09-09 16:30:00']);
        $miss = $this->mainLog($this->gaMember, ['created_at' => '2026-09-09 15:59:00']);

        $items = $this->listItems();
        $this->assertSame(["main:{$hit}"], array_map(static fn (array $i): string => $i['segment'] . ':' . $i['id'], $items));
        $this->assertSame('2026-09-10 00:30:00', $items[0]['created_at']);
        $this->assertNotContains("main:{$miss}", $this->listKeys());

        $cf = $this->cashFlow();
        $order = $this->depositOrder($this->gaMember, $this->merchant($cf), 7001, ['created_at' => '2026-09-10 03:00:00']);
        $lateLog = $this->mainLog($this->gaMember, [
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'order_id' => $order->id,
            'created_at' => '2026-09-11 03:00:00',
        ]);

        $this->assertNotContains("main:{$lateLog}", $this->listKeys(['source' => 3]));
        $this->assertSame(["main:{$lateLog}"], $this->listKeys(['source' => 3, 'is_created_period' => 1]));
    }

    #[Group('rules/report-sr02n#R-12')]
    #[Group('rules/report-sr02n#R-13')]
    #[TestDox('範圍：指定線外代理 → 空且不跑 union；指定子代理只回其線下；代理段未指定時只列登入者與子帳號操作')]
    public function test_scope_rules(): void
    {
        $outsider = $this->createTopAgent();
        $outsiderMember = $this->createSr02nMember($outsider);
        $this->mainLog($outsiderMember, ['agent_id' => $outsider->id]);
        $gaRow = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);
        $agentRow = $this->mainLog($this->agentMember, ['agent_id' => $this->ga->id]);
        $agent2Row = $this->mainLog($this->agent2Member, ['agent_id' => $this->agent->id]);
        $byGa = $this->agentCreditLog($this->ga, $this->agent);
        $byAgent = $this->agentCreditLog($this->agent, $this->agent2);

        DB::enableQueryLog();
        $this->assertSame([], $this->listKeys(['agent_username' => $outsider->username]));
        $unionQueries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains(strtolower($q['query']), 'union all'));
        DB::disableQueryLog();
        $this->assertSame([], array_values($unionQueries));

        $this->assertEqualsCanonicalizing(
            ["main:{$agentRow}", "main:{$agent2Row}", "agent_credit:{$byAgent}"],
            $this->listKeys(['agent_username' => $this->agent->username]),
        );

        $keys = $this->listKeys();
        $this->assertContains("main:{$gaRow}", $keys);
        $this->assertContains("agent_credit:{$byGa}", $keys);
        $this->assertNotContains("agent_credit:{$byAgent}", $keys);
    }

    #[Group('rules/report-sr02n#R-1')]
    #[Group('rules/report-sr02n#R-3')]
    #[Group('rules/report-sr02n#R-14')]
    #[TestDox('代理段隱藏規則：主錢包轉佔成（6）與失敗列不出現；佔成段 System 51 可見、登入者本人操作不列')]
    public function test_agent_segment_hidden_rules(): void
    {
        $system = $this->systemAgent();

        $type6 = $this->agentCreditLog($this->ga, $this->agent, ['type' => 6]);
        $failed = $this->agentCreditLog($this->ga, $this->agent, ['status' => 0]);
        $bySystem = $this->agentWalletLog($system, $this->agent);
        $bySelf = $this->agentWalletLog($this->ga, $this->agent);
        $walletFailed = $this->agentWalletLog($this->gaAcc, $this->agent, ['status' => 0]);

        $keys = $this->listKeys();

        $this->assertContains("agent_wallet:{$bySystem}", $keys);
        foreach (["agent_credit:{$type6}", "agent_credit:{$failed}", "agent_wallet:{$bySelf}", "agent_wallet:{$walletFailed}"] as $hidden) {
            $this->assertNotContains($hidden, $keys);
        }
    }

    #[Group('rules/report-sr02n#R-11')]
    #[TestDox('同一提款單多筆成功代付紀錄／多筆付款帳戶異動：列表只出現一次，取最新一筆')]
    public function test_duplicate_payout_records_and_bank_logs_do_not_duplicate_rows(): void
    {
        $oldCf = $this->cashFlow(type: 3, name: 'OldPayout');
        $newCf = $this->cashFlow(type: 3, name: 'NewPayout');
        $w = $this->withdrawalOrder($this->gaMember->id, ['tax' => '2.5', 'rate' => '1.2']);
        $this->payoutRecord($w, $oldCf->id, $this->merchant($oldCf)->id);
        $this->payoutRecord($w, $newCf->id, $this->merchant($newCf)->id);
        $this->bankLog($w->id, 4, $this->gaMember->id);
        $latestNickname = $this->bankLog($w->id, 4, $this->gaMember->id);
        $row = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id]);

        $items = $this->listItems();

        $this->assertSame(["main:{$row}"], array_map(static fn (array $i): string => $i['segment'] . ':' . $i['id'], $items));
        $this->assertSame('NewPayout', $items[0]['source_name']);
        $this->assertSame($latestNickname, $items[0]['status']);
        $this->assertSame('3.0000', $items[0]['tax']);
    }

    #[Group('rules/report-sr02n#R-14')]
    #[TestDox('佔成錢包段 System(51)：對外線代理的列非 Admin 看不到，對線下代理的看得到；Admin 全看得到')]
    public function test_system_agent_wallet_rows_limited_to_viewer_scope(): void
    {
        $system = $this->systemAgent();
        $outsider = $this->createTopAgent();
        $toOutsider = $this->agentWalletLog($system, $outsider);
        $toDownline = $this->agentWalletLog($system, $this->agent);

        $keys = $this->listKeys();
        $this->assertContains("agent_wallet:{$toDownline}", $keys);
        $this->assertNotContains("agent_wallet:{$toOutsider}", $keys);

        $this->headers = [];
        $this->loginAs($this->adminSubAccount());

        $adminKeys = $this->listKeys();
        $this->assertContains("agent_wallet:{$toOutsider}", $adminKeys);
        $this->assertContains("agent_wallet:{$toDownline}", $adminKeys);
    }

    #[Group('rules/report-sr02n#R-26')]
    #[TestDox('執行者帳號：四段各自的比對欄位（l.operator／operator.username／la.operator／f.username）')]
    public function test_execution_username_per_segment(): void
    {
        $other = $this->createSubAccount($this->ga);
        $hits = [
            'main:' . $this->mainLog($this->agentMember, ['agent_id' => $this->gaAcc->id]),
            'game:' . $this->gameLog($this->agentMember, ['agent_id' => $this->gaAcc->id]),
            'agent_credit:' . $this->agentCreditLog($this->gaAcc, $this->agent),
            'agent_wallet:' . $this->agentWalletLog($this->gaAcc, $this->agent),
        ];
        $this->mainLog($this->agentMember, ['agent_id' => $other->id]);
        $this->gameLog($this->agentMember, ['agent_id' => $other->id]);
        $this->agentCreditLog($other, $this->agent);
        $this->agentWalletLog($other, $this->agent);

        $this->assertEqualsCanonicalizing($hits, $this->listKeys(['execution_username' => $this->gaAcc->username]));
    }

    #[Group('rules/report-sr02n#R-25')]
    #[TestDox('單號：來源存款比對三方充值單號、來源取款比對三方代付單號；來源全部只比我方單號')]
    public function test_transaction_no_matches_third_party_no_by_source(): void
    {
        $cf = $this->cashFlow();
        $order = $this->depositOrder($this->gaMember, $this->merchant($cf), 7001, ['third_party_order_no' => 'TPDEP001']);
        $deposit = $this->mainLog($this->gaMember, ['type' => TransactionEnum::ORDER_DEPOSIT->value, 'order_id' => $order->id]);

        $payoutCf = $this->cashFlow(type: 3);
        $w = $this->withdrawalOrder($this->gaMember->id, ['third_party_order_no' => 'TPPAY001']);
        $this->payoutRecord($w, $payoutCf->id, $this->merchant($payoutCf)->id);
        $payout = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id]);

        $this->assertSame(["main:{$deposit}"], $this->listKeys(['source' => 3, 'transaction_no' => 'TPDEP001']));
        $this->assertSame(["main:{$payout}"], $this->listKeys(['source' => 4, 'cash_flow_id' => $payoutCf->id, 'transaction_no' => 'TPPAY001']));
        $this->assertSame([], $this->listKeys(['transaction_no' => 'TPDEP001']));
    }

    #[Group('rules/report-sr02n#R-21')]
    #[TestDox('來源客服系統：排除有提款單的洗分列與派發類代理額度列')]
    public function test_customer_service_source_exclusions(): void
    {
        $cs = $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);
        $w = $this->withdrawalOrder($this->gaMember->id);
        $selfWithdraw = $this->mainLog($this->gaMember, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id]);
        $credit = $this->agentCreditLog($this->ga, $this->agent);
        $commission = $this->agentCreditLog($this->ga, $this->agent, ['type' => 4]);
        $profitShare = $this->agentCreditLog($this->ga, $this->agent, ['type' => 9]);

        $keys = $this->listKeys(['source' => 1]);

        $this->assertContains("main:{$cs}", $keys);
        $this->assertContains("agent_credit:{$credit}", $keys);
        foreach (["main:{$selfWithdraw}", "agent_credit:{$commission}", "agent_credit:{$profitShare}"] as $excluded) {
            $this->assertNotContains($excluded, $keys);
        }
        $this->assertContains("agent_credit:{$commission}", $this->listKeys());
    }

    #[Group('rules/report-sr02n#R-24')]
    #[TestDox('會員暱稱精確比對')]
    public function test_member_nickname_filter(): void
    {
        $mine = $this->mainLog($this->agentMember, ['agent_id' => $this->agent->id]);
        $this->mainLog($this->gaMember, ['agent_id' => $this->ga->id]);

        $this->assertSame(["main:{$mine}"], $this->listKeys(['member_nickname' => $this->agentMember->nickname]));
    }

    #[Group('rules/report-sr02n#R-27')]
    #[TestDox('期間剛好 60 天：通過驗證')]
    public function test_period_exactly_60_days_is_allowed(): void
    {
        $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams([
            'start_date' => '2026-07-12 00:00:00',
            'end_date' => '2026-09-10 00:00:00',
        ]))->assertOk()->assertJsonPath('code', 200);
    }

    #[Group('rules/report-sr02n#R-27')]
    #[Group('rules/report-sr02n#R-30')]
    #[TestDox('期間超過 60 天 → 400；無 report_sr02n 權限 → 9003')]
    public function test_validation_and_permission(): void
    {
        $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams([
            'start_date' => '2026-07-01 00:00:00',
            'end_date' => '2026-08-31 00:00:00',
        ]))->assertStatus(400);

        $other = $this->createTopAgent();
        $this->headers = [];
        $this->loginAs($other);

        $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams())
            ->assertJsonPath('message', __('error_message.9003'));
    }
}
