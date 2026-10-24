<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report;

use App\Enums\Agent\ReservedAgentIdEnum;
use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlowName;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Agent\Report\Concerns\Sr02nFixtures;
use Tests\TestCase;

/**
 * SR02N 來源支付商三層樹（target/tickets#4356）。
 *
 * php artisan test tests/Feature/Agent/Report/Sr02nCashFlowTreeTest.php
 */
class Sr02nCashFlowTreeTest extends TestCase
{
    use Sr02nFixtures;

    private const int GROUP = 777001;

    private const int OTHER_GROUP = 777002;

    private const int NAME_GROUP = 778001;

    private Agent $ga;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSr02n();

        $this->ga = $this->createTopAgent(RoleEnum::GENERAL_AGENT->value, withDomain: true);
        $this->ga->update(['cash_flow_group_id' => self::GROUP, 'customized_setting_group_id' => self::NAME_GROUP]);

        $this->grantSr02nMenu($this->ga);
        $this->loginAs($this->ga);
    }

    private function orderType(int $cashFlowId, string $name, int $parent = 0): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => 1,
            'name' => $name,
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'parent' => $parent,
            'info' => '',
            'admin' => 0,
        ]);
    }

    private function bind(int $merchantId, OrderType $orderType): void
    {
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $merchantId,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $orderType->cash_flow_id,
            'status' => 1,
            'settings' => '{}',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tree(): array
    {
        $response = $this->requestApi('agent/api/report/sr02n/cash-flow-tree');
        $response->assertOk()->assertJsonPath('code', 200);

        return collect($response->json('data'))->keyBy('cash_flow_id')->all();
    }

    #[Group('rules/report-sr02n#R-34')]
    #[Group('rules/report-sr02n#R-35')]
    #[Group('rules/report-sr02n#R-36')]
    #[TestDox('非 Admin：只見自群組商戶、支付商名稱取語系覆寫、parent≠0 通道排除、停用支付商排除；取款只到商戶')]
    public function test_tree_for_general_agent(): void
    {
        $deposit = $this->cashFlow(type: 1, name: 'DepositCF');
        $payout = $this->cashFlow(type: 3, name: 'PayoutCF');
        $disabled = $this->cashFlow(type: 1, name: 'Disabled');
        $disabled->update(['status' => 0]);
        $foreign = $this->cashFlow(type: 1, name: 'Foreign');

        $mine = $this->merchant($deposit, self::GROUP, 'MyMerchant');
        $this->merchant($deposit, self::OTHER_GROUP, 'OtherMerchant');
        $this->merchant($payout, self::GROUP, 'PayoutMerchant');
        $this->merchant($disabled, self::GROUP);
        $this->merchant($foreign, self::OTHER_GROUP);

        $top = $this->orderType($deposit->id, 'Channel');
        $child = $this->orderType($deposit->id, 'ChildChannel', parent: $top->id);
        $this->bind($mine->id, $top);
        $this->bind($mine->id, $child);

        CashFlowName::create([
            'id' => random_int(800000, 899999),
            'language_id' => 1,
            'cash_flow_id' => $deposit->id,
            'agent_group_id' => self::NAME_GROUP,
            'name' => 'LocalizedCF',
            'admin' => 0,
        ]);
        OrderTypeName::create(['language_id' => 1, 'type_id' => $top->id, 'name' => 'LocalizedChannel', 'admin' => 0]);

        $tree = $this->tree();

        $this->assertArrayNotHasKey($disabled->id, $tree);
        $this->assertArrayNotHasKey($foreign->id, $tree);
        $this->assertSame('LocalizedCF', $tree[$deposit->id]['name']);
        $this->assertSame(1, $tree[$deposit->id]['type']);
        $this->assertSame([[
            'agent_cash_flow_id' => $mine->id,
            'name' => 'MyMerchant',
            'children' => [['order_type_id' => $top->id, 'name' => 'LocalizedChannel']],
        ]], $tree[$deposit->id]['children']);
        $this->assertSame('PayoutCF', $tree[$payout->id]['name']);
        $this->assertSame([], $tree[$payout->id]['children'][0]['children']);
    }

    #[Group('rules/report-sr02n#R-35')]
    #[TestDox('Admin 線：不依群組過濾，見全部商戶')]
    public function test_tree_for_admin(): void
    {
        $deposit = $this->cashFlow(type: 1, name: 'DepositCF');
        $this->merchant($deposit, self::GROUP);
        $this->merchant($deposit, self::OTHER_GROUP);

        $admin = Agent::factory()->create([
            'username' => 'adm' . bin2hex(random_bytes(6)),
            'host_id' => ReservedAgentIdEnum::ADMIN->value,
            'role_id' => RoleEnum::ADMIN->value,
            'cash_flow_group_id' => self::GROUP,
        ]);
        $this->headers = [];
        $this->loginAs($admin);

        $this->assertCount(2, $this->tree()[$deposit->id]['children']);
    }
}
