<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Models\CashFlow;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderTypeNameManageTest extends TestCase
{
    use DatabaseTransactions;

    private const CF = 99779;

    private function seedFixtures(): OrderTypeName
    {
        CashFlow::create([
            'id' => self::CF, 'name' => 'Sentinel', 'type' => 1, 'status' => 1, 'info' => '', 'admin' => 0,
        ]);

        $ot = OrderType::create([
            'cash_flow_id' => self::CF, 'currency_id' => 1, 'name' => 'x', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);

        return OrderTypeName::create([
            'cash_flow_id' => self::CF, 'type_id' => $ot->id, 'language_id' => 1, 'name' => '舊名', 'admin' => 0,
        ]);
    }

    public function test_query_lists_without_changes(): void
    {
        $otn = $this->seedFixtures();

        $this->artisan('cash-flow:order-type-name', ['cash_flow_id' => self::CF])->assertExitCode(0);

        $this->assertSame('舊名', $otn->fresh()->name);
    }

    public function test_set_name(): void
    {
        $otn = $this->seedFixtures();

        $this->artisan('cash-flow:order-type-name', [
            'cash_flow_id' => self::CF, '--otn' => $otn->id, '--set-name' => '新名', '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('新名', $otn->fresh()->name);
    }

    public function test_rejects_nonexistent_cash_flow(): void
    {
        $this->artisan('cash-flow:order-type-name', ['cash_flow_id' => 987654])->assertExitCode(1);
    }

    public function test_rejects_otn_not_under_cash_flow(): void
    {
        $this->seedFixtures();

        $this->artisan('cash-flow:order-type-name', [
            'cash_flow_id' => self::CF, '--otn' => 999999, '--set-name' => '新名', '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_rejects_otn_belonging_to_another_cash_flow(): void
    {
        $this->seedFixtures();

        $otherCashFlowId = self::CF + 1;

        $otherOt = OrderType::create([
            'cash_flow_id' => $otherCashFlowId, 'currency_id' => 1, 'name' => 'y', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);

        $otherOtn = OrderTypeName::create([
            'cash_flow_id' => $otherCashFlowId, 'type_id' => $otherOt->id, 'language_id' => 1, 'name' => '原名', 'admin' => 0,
        ]);

        $this->artisan('cash-flow:order-type-name', [
            'cash_flow_id' => self::CF, '--otn' => $otherOtn->id, '--set-name' => '入侵', '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('原名', $otherOtn->fresh()->name);
    }
}
