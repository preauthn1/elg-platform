<?php

namespace target\support\Test\Unit;

use InvalidArgumentException;
use Tests\TestCase;

class SupportTest extends TestCase {


    public function testMTPayOfCashFlowHelper() {
        $obj = new \target\support\Helpers\CashFlows\CashFlowHelper(3);
        $this->assertInstanceOf('\target\support\Helpers\CashFlows\CashFlowHelper', $obj);
        $service = new \target\support\Services\CashFlowService();
        $this->assertInstanceOf('\target\support\Services\CashFlowService', $service);
        $this->expectException(InvalidArgumentException::class);
        new \target\support\Helpers\CashFlows\CashFlowHelper(999);
    }


}