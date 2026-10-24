<?php

namespace target\support\Services;


use PHPUnit\Framework\TestCase;

class CashFlowServiceTest extends TestCase
{

    public function testGetOrderNo()
    {
        $service = new CashFlowService();
        for($i=0;$i<5000;$i++) {
            $order_no = $service->getOrderNo();
            $this->assertEquals( 1 , preg_match( '/^[0-9]+$/', $order_no),
                $order_no . ' is not a set of digits' );
        }
    }
}
