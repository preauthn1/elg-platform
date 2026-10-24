<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numMul 數值乘法測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumMulTest.php
 */
class NumMulTest extends TestCase
{
    public function test()
    {
        $result = Common::numMul(1, 2);
        $this->assertEquals(12, strlen($result));
        $this->assertIsString($result);
        $this->assertEquals(bccomp('2', $result), 0);
        $result = Common::numMul(0.1, 0.2);
        $this->assertEquals(bccomp('0.02', $result), 0);
        $result = Common::numMul(0.01, 0.02);
        $this->assertEquals(bccomp('0.0002', $result), 0);
        $result = Common::numMul(0.001, 0.002);
        $this->assertEquals(bccomp('0.000002', $result), 0);
        $result = Common::numMul(0.0001, 0.0002);
        $this->assertEquals(bccomp('0.00000002', $result), 0);
        $result = Common::numMul(0.00001, 0.00002);
        $this->assertEquals(bccomp('0.0000000002', $result), 0);
        $result = Common::numMul(0.000001, 0.000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numMul(0.0000001, 0.0000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numMul(10000, 20000);
        $this->assertEquals(bccomp('200000000', $result), 0);
        $result = Common::numMul('1000000000000000000000000', '2000000000000000000000000');
        $this->assertEquals(bccomp('2000000000000000000000000000000000000000000000000', $result), 0);
    }
}
