<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numFloor 數值無條件捨去測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumFloorTest.php
 */
class NumFloorTest extends TestCase
{
    public function test()
    {
        $result = Common::numFloor('1.200', 2);
        $this->assertEquals(bccomp('1.2', $result), 0);
        $result = Common::numFloor(1.2, 2);
        $this->assertEquals(bccomp('1.2', $result), 0);
        $result = Common::numFloor(1.23, 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor(1.23456789, 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor(1.2356, 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor(1.239, 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor('1.23456789', 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor('1.235', 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor('1.239', 2);
        $this->assertEquals(bccomp('1.23', $result), 0);

        // 新補充的案例
        $result = Common::numFloor(1.235, 2);
        $this->assertEquals(bccomp('1.23', $result), 0);
        $result = Common::numFloor(1.99995, 2);
        $this->assertEquals(bccomp('1.99', $result), 0);
        $result = Common::numFloor(2136.995, 2);
        $this->assertEquals(bccomp('2136.99', $result), 0);
        $result = Common::numFloor(0.0000001, 2);
        $this->assertEquals(bccomp('0.00', $result), 0);
    }
}
