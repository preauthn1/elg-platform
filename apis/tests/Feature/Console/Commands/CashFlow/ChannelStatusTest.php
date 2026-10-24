<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ChannelStatusTest extends TestCase
{
    use DatabaseTransactions;

    private const CF = 99778;

    private const CF2 = 99779;

    private function seedFixtures(int $cashFlowId = self::CF): array
    {
        $cashFlow = CashFlow::create([
            'id' => $cashFlowId, 'name' => 'Sentinel', 'type' => 1, 'status' => 1, 'info' => '', 'admin' => 0,
        ]);
        $acf = AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => '(MYR) Sentinel', 'status' => 1, 'admin' => 0,
            'info' => [],
        ]);
        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlowId, 'name' => 'Sentinel OT', 'key' => 'sentinel_ot_' . $cashFlowId, 'admin' => 0,
        ]);
        $acfot = AgentCashFlowOrderType::create([
            'cash_flow_id' => $cashFlowId, 'agent_cash_flow_id' => $acf->id, 'order_type_id' => $orderType->id,
            'settings' => '{}', 'status' => 1,
        ]);

        return [$cashFlow, $acf, $acfot];
    }

    #[Group('rules/payment-channel-management#R-7')]
    public function test_query_lists_tree_without_changes(): void
    {
        [, $acf, $acfot] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', ['cash_flow_id' => self::CF])->assertExitCode(0);

        $this->assertSame(1, $acf->fresh()->status);
        $this->assertSame(1, $acfot->fresh()->status);
    }

    #[Group('rules/payment-channel-management#R-8')]
    public function test_set_acfot_status(): void
    {
        [, , $acfot] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => $acfot->id, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, $acfot->fresh()->status);
    }

    #[Group('rules/payment-channel-management#R-8')]
    public function test_set_acf_status(): void
    {
        [, $acf] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acf' => $acf->id, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, $acf->fresh()->status);
    }

    #[Group('rules/payment-channel-management#R-8')]
    public function test_set_status_alone_leaves_backend_display_untouched(): void
    {
        [, , $acfot] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => $acfot->id, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $acfot->fresh()->backend_display, '--backend-display 為選用，不帶就不動');
    }

    #[Group('rules/payment-channel-management#R-8')]
    public function test_backend_display_alone_hides_acfot_without_touching_status(): void
    {
        [, , $acfot] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => $acfot->id, '--backend-display' => 0, '--force' => true,
        ])->assertExitCode(0);

        $fresh = $acfot->fresh();
        $this->assertSame(0, (int) $fresh->backend_display);
        $this->assertSame(1, (int) $fresh->status, '只帶 --backend-display 時 status 不動（停用前先隱藏）');
    }

    #[Group('rules/payment-channel-management#R-8')]
    public function test_set_acf_status_and_backend_display_together(): void
    {
        [, $acf] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acf' => $acf->id, '--set-status' => 0, '--backend-display' => 0, '--force' => true,
        ])->assertExitCode(0);

        $fresh = $acf->fresh();
        $this->assertSame(0, (int) $fresh->status);
        $this->assertSame(0, (int) $fresh->backend_display);
    }

    #[Group('rules/payment-channel-management#R-9')]
    public function test_rejects_invalid_backend_display(): void
    {
        [, , $acfot] = $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => $acfot->id, '--backend-display' => 2, '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) $acfot->fresh()->backend_display);
    }

    #[Group('rules/payment-channel-management#R-9')]
    public function test_rejects_acfot_not_under_cash_flow(): void
    {
        $this->seedFixtures();

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => 999999, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(1);
    }

    #[Group('rules/payment-channel-management#R-9')]
    public function test_rejects_acfot_belonging_to_another_cash_flow(): void
    {
        $this->seedFixtures();
        [, , $acfot2] = $this->seedFixtures(self::CF2);

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acfot' => $acfot2->id, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, $acfot2->fresh()->status);
    }

    #[Group('rules/payment-channel-management#R-9')]
    public function test_rejects_acf_belonging_to_another_cash_flow(): void
    {
        $this->seedFixtures();
        [, $acf2] = $this->seedFixtures(self::CF2);

        $this->artisan('cash-flow:channel-status', [
            'cash_flow_id' => self::CF, '--acf' => $acf2->id, '--set-status' => 0, '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, $acf2->fresh()->status);
    }
}
