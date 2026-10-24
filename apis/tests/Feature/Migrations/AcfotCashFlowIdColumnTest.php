<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Facades\SchemaMonitor;
use Tests\TestCase;

class AcfotCashFlowIdColumnTest extends TestCase
{
    public function test_acfot_has_cash_flow_id_and_not_old_name(): void
    {
        $this->assertTrue(SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_id'));
        $this->assertFalse(SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_info_id'));
    }

    public function test_acfot_cash_flow_id_has_index(): void
    {
        $this->assertTrue(
            SchemaMonitor::hasIndex('agent_cash_flow_order_types', 'acfot_cash_flow_id_index'),
            'cash_flow_id rename 後應保留具名 index acfot_cash_flow_id_index。'
        );
    }
}
