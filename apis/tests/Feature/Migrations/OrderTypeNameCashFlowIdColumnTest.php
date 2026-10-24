<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Facades\SchemaMonitor;
use Tests\TestCase;

class OrderTypeNameCashFlowIdColumnTest extends TestCase
{
    public function test_order_type_names_has_cash_flow_id(): void
    {
        $this->assertTrue(SchemaMonitor::hasColumn('order_type_names', 'cash_flow_id'));
    }

    public function test_order_type_names_has_cash_flow_id_index(): void
    {
        $this->assertTrue(SchemaMonitor::hasIndex('order_type_names', 'order_type_names_cash_flow_id_index'));
    }
}
