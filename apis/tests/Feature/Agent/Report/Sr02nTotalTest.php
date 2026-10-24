<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report;

use App\Enums\Agent\RoleEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Agent\Report\Concerns\Sr02nFixtures;
use Tests\TestCase;

/**
 * SR02N 開洗分明細表合計（target/tickets#4356）。
 *
 * php artisan test tests/Feature/Agent/Report/Sr02nTotalTest.php
 */
class Sr02nTotalTest extends TestCase
{
    use Sr02nFixtures;

    private Agent $ga;

    private Agent $gaAcc;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSr02n();

        $this->ga = $this->createTopAgent(RoleEnum::GENERAL_AGENT->value, withDomain: true);
        $this->gaAcc = $this->createSubAccount($this->ga);
        $this->agent = $this->createSubAgent($this->ga);
        $this->member = $this->createSr02nMember($this->agent);

        $this->grantSr02nMenu($this->ga);
        $this->loginAs($this->ga);
    }

    /**
     * 會員開分 100、會員提款 100（手續費 2.5×1.2＝3）、遊戲錢包開分 50、扣分 20、
     * 代理額度開分 30、代理自助洗分 50（手續費 1×2＝2）、佔成錢包轉入 20。
     */
    private function seedRows(): void
    {
        $this->mainLog($this->member, ['agent_id' => $this->ga->id, 'point' => '100.0000']);
        $w = $this->withdrawalOrder($this->member->id, ['tax' => '2.5', 'rate' => '1.2']);
        $this->mainLog($this->member, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id, 'point' => '100.0000']);
        $this->gameLog($this->member, ['point' => '50.0000']);
        $this->gameLog($this->member, ['type' => 9, 'point' => '20.0000']);
        $this->agentCreditLog($this->ga, $this->agent, ['amount' => '30.0000']);
        $aw = $this->withdrawalOrder(0, ['tax' => '1', 'rate' => '2']);
        $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $aw->id, 'amount' => '50.0000']);
        $this->agentWalletLog($this->gaAcc, $this->agent, ['amount' => '20.0000']);
    }

    /**
     * 被拒絕的洗分（target/tickets#4493）：會員提款 100 單退款（手續費 2.5×1.2＝3 不計）、
     * 客服洗分 12.34 被會員拒絕洗分（拒絕洗分列 12.34 計入總開分）、代理自助洗分 50 單拒絕（手續費 1×2＝2 不計）。
     *
     * @return array<string, string> 被拒絕的洗分列 "segment:id" ⇒ 變更點數（依序：退款單會員提款、被拒客服洗分、拒絕單代理自助洗分）
     */
    private function seedRejectedRows(): array
    {
        $refunded = $this->withdrawalOrder($this->member->id, ['tax' => '2.5', 'rate' => '1.2', 'status' => WithdrawalOrderStatusEnum::REFUND->value]);
        $refundedLog = $this->mainLog($this->member, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $refunded->id, 'point' => '100.0000']);
        $csWithdraw = $this->csWithdrawal($this->member, '12.3400');
        $this->refusal($this->member, '12.3400');
        $failedAw = $this->withdrawalOrder(0, ['tax' => '1', 'rate' => '2', 'status' => WithdrawalOrderStatusEnum::FAILED->value]);
        $failedAwLog = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $failedAw->id, 'amount' => '50.0000']);

        return [
            "main:{$refundedLog}" => '100.0000',
            "main:{$csWithdraw}" => '12.3400',
            "agent_credit:{$failedAwLog}" => '50.0000',
        ];
    }

    /** @return array<string, string> */
    private function total(array $override = []): array
    {
        $params = $this->sr02nParams($override);
        unset($params['page'], $params['per_page']);

        $response = $this->requestApi('agent/api/report/sr02n/total', $params);
        $response->assertOk()->assertJsonPath('code', 200);

        return $response->json('data');
    }

    #[Group('rules/report-sr02n#R-4')]
    #[TestDox('四項合計（4 位字串）')]
    public function test_totals(): void
    {
        $this->seedRows();

        $this->assertSame([
            'total_in' => '200.0000',   // 100 + 50 + 30 + 20
            'total_out' => '170.0000',  // 100 + 20 + 50
            'total_tax' => '5.0000',    // 3 + 2
            'total_actual' => '165.0000', // 洗分列全計入：97 + 20（遊戲錢包扣分，無提款單手續費 0）+ 48
        ], $this->total());
    }

    #[Group('rules/report-sr02n#R-38')]
    #[Group('rules/report-sr02n#R-10')]
    #[TestDox('被拒絕的洗分（target/tickets#4493）：計入總開分／總洗分，不計入手續費合計與實際金額合計')]
    public function test_rejected_rows_excluded_from_totals(): void
    {
        $this->seedRows();
        $this->seedRejectedRows();

        $this->assertSame([
            'total_in' => '212.3400',   // 200（seedRows）+ 拒絕洗分 12.34
            'total_out' => '332.3400',  // 170（seedRows）+ 退款單 100 + 被拒客服洗分 12.34 + 拒絕單代理自助洗分 50
            'total_tax' => '5.0000',    // 只有 seedRows 的 3 + 2；退款單 3、拒絕單 2 不計
            'total_actual' => '165.0000', // 只有 seedRows 的 97 + 20 + 48；被拒絕的三列不計
        ], $this->total());
    }

    #[Group('rules/report-sr02n#R-4')]
    #[TestDox('合計與列表同一組篩選時，手續費／實際金額＝列表逐列加總（含被拒絕的洗分）')]
    public function test_totals_match_list_rows(): void
    {
        $this->seedRows();
        $this->seedRejectedRows();

        foreach ([[], ['pairs' => ['ga_member']], ['source' => 1], ['member_username' => $this->member->username]] as $filter) {
            $items = $this->listItems($filter);
            $total = $this->total($filter);

            $tax = '0.0000';
            $actual = '0.0000';
            foreach ($items as $item) {
                $tax = Common::numAdd($tax, $item['tax'] ?? '0', 4);
                $actual = Common::numAdd($actual, $item['actual_point'] ?? '0', 4);
            }

            $this->assertSame($tax, $total['total_tax'], json_encode($filter));
            $this->assertSame($actual, $total['total_actual'], json_encode($filter));
        }
    }

    #[Group('rules/report-sr02n#R-40')]
    #[TestDox('被拒絕的洗分：order_id 為 NULL 的客服洗分視為配不到、不被拒絕洗分沖掉，列表與合計一致（不因 NULL 三值邏輯分歧）')]
    public function test_null_order_id_withdrawal_not_rejected(): void
    {
        $withdraw = $this->mainLog($this->member, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => null, 'point' => '12.3400']);
        $this->refusal($this->member, '12.3400');

        $this->assertSame(['0.0000', '12.3400'], $this->moneyColumns()["main:{$withdraw}"]);
        $this->assertSame([
            'total_in' => '12.3400',     // 拒絕洗分列
            'total_out' => '12.3400',
            'total_tax' => '0.0000',
            'total_actual' => '12.3400', // 與列表該列的實際金額一致
        ], $this->total());
    }

    #[Group('rules/report-sr02n#R-4')]
    #[Group('rules/report-sr02n#R-12')]
    #[TestDox('配對過濾同樣作用於合計；線外代理 → 全 0')]
    public function test_totals_follow_filters(): void
    {
        $this->seedRows();

        $this->assertSame('100.0000', $this->total(['pairs' => ['ga_member']])['total_in']);
        // 額度開分 30 ＋ 佔成錢包轉入 20（操作者 gaAcc 為 role 0 子帳號，以主帳號總代身分算）
        $this->assertSame('50.0000', $this->total(['pairs' => ['ga_agent']])['total_in']);

        $outsider = $this->createTopAgent();
        $this->assertSame([
            'total_in' => '0.0000',
            'total_out' => '0.0000',
            'total_tax' => '0.0000',
            'total_actual' => '0.0000',
        ], $this->total(['agent_username' => $outsider->username]));
    }

    #[Group('rules/report-sr02n#R-9')]
    #[TestDox('代理洗分列 order_id 撞到會員提款單（member_id≠0）：視為無提款單，手續費 0、實際金額＝變更點數')]
    public function test_agent_withdrawal_ignores_member_withdrawal_order(): void
    {
        $memberOrder = $this->withdrawalOrder($this->member->id, ['tax' => '5', 'rate' => '1']);
        $collided = $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'order_id' => $memberOrder->id, 'amount' => '40.0000']);

        $item = collect($this->listItems())->firstWhere(static fn (array $i): bool => $i['segment'] === 'agent_credit' && $i['id'] === $collided);

        $this->assertNotNull($item);
        $this->assertSame(['0.0000', '40.0000'], [$item['tax'], $item['actual_point']]);
        $this->assertSame(['0.0000', '40.0000', '40.0000'], [
            $this->total()['total_tax'],
            $this->total()['total_actual'],
            $this->total()['total_out'],
        ]);
    }

    #[Group('rules/report-sr02n#R-11')]
    #[TestDox('同一提款單兩筆成功代付紀錄：手續費只算一次')]
    public function test_duplicate_payout_records_counted_once(): void
    {
        $cf = $this->cashFlow(type: 3);
        $merchant = $this->merchant($cf);
        $w = $this->withdrawalOrder($this->member->id, ['tax' => '2.5', 'rate' => '1.2']);
        $this->payoutRecord($w, $cf->id, $merchant->id);
        $this->payoutRecord($w, $cf->id, $merchant->id);
        $this->mainLog($this->member, ['type' => TransactionEnum::WITHDRAWAL->value, 'order_id' => $w->id, 'point' => '100.0000']);

        $total = $this->total();

        $this->assertSame(['100.0000', '3.0000', '97.0000'], [$total['total_out'], $total['total_tax'], $total['total_actual']]);
    }

    #[Group('rules/report-sr02n#R-14')]
    #[TestDox('佔成錢包段 System(51) 對外線代理的列：非 Admin 合計不計')]
    public function test_system_agent_wallet_outside_scope_excluded_from_total(): void
    {
        $this->agentWalletLog($this->systemAgent(), $this->createTopAgent(), ['amount' => '70.0000']);
        $this->agentWalletLog($this->systemAgent(), $this->agent, ['amount' => '20.0000']);

        $this->assertSame('20.0000', $this->total()['total_in']);
    }

    #[Group('rules/report-sr02n#R-10')]
    #[TestDox('實際金額合計 ≡ 總洗分 − 被拒絕列變更點數 − 手續費合計（含無提款單的客服洗分、扣分、代理洗分與被拒絕的洗分）')]
    public function test_total_actual_equals_out_minus_tax(): void
    {
        $this->seedRows();
        $rejectedRows = $this->seedRejectedRows();
        [$refundedKey, $csKey, $failedAwKey] = array_keys($rejectedRows);
        $this->mainLog($this->member, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::WITHDRAWAL->value, 'point' => '11.1111']);
        $this->mainLog($this->member, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::DEDUCT->value, 'point' => '7.0000']);
        $this->agentCreditLog($this->ga, $this->agent, ['type' => 2, 'amount' => '9.0000']);
        // 總代操作的客服洗分被拒絕：讓「總代對會員」篩選也有被拒絕的洗分
        $gaKey = 'main:' . $this->mainLog($this->member, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::WITHDRAWAL->value, 'point' => '7.7700']);
        $this->mainLog($this->member, ['agent_id' => $this->ga->id, 'type' => TransactionEnum::REFUSE_TO_WITHDRAWAL->value, 'point' => '7.7700']);
        $rejectedRows[$gaKey] = '7.7700';

        // 被拒絕金額直接由夾具回傳的金額加總，不從被測的列表反推
        $sum = static fn (array $keys): string => array_reduce(
            $keys,
            static fn (string $carry, string $key): string => Common::numAdd($carry, $rejectedRows[$key], 4),
            '0.0000',
        );

        $cases = [
            // 170.11＝退款單 100 + 被拒客服洗分 12.34 + 拒絕單代理自助洗分 50 + 總代被拒客服洗分 7.77
            [[], $sum([$refundedKey, $csKey, $failedAwKey, $gaKey])],
            // 7.77：只有總代操作的那筆；代理額度段不併、其餘主錢包列由代理操作
            [['pairs' => ['ga_member']], $sum([$gaKey])],
            // 70.11：客服來源含無單的兩筆客服洗分與代理自助洗分；有取款訂單的退款單不屬客服來源
            [['source' => 1], $sum([$csKey, $failedAwKey, $gaKey])],
            // 100：取款支付商 6（SELF_WITHDRAWAL_CASH_FLOW_ID）不比對代付紀錄，有取款訂單的主錢包洗分列都在，其中被拒絕的只有退款單；代理額度段不併
            [['source' => 4, 'cash_flow_id' => 6], $sum([$refundedKey])],
        ];

        foreach ($cases as [$filter, $rejected]) {
            $total = $this->total($filter);

            $this->assertSame(
                Common::numSub(Common::numSub($total['total_out'], $rejected, 4), $total['total_tax'], 4),
                $total['total_actual'],
                json_encode($filter),
            );
        }
    }
}
