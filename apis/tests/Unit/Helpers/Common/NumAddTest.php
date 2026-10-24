<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numAdd 數值加法測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumAddTest.php
 */
class NumAddTest extends TestCase
{
    public function test()
    {
        $result = Common::numAdd(1, 2);
        $this->assertEquals(12, strlen($result));
        $this->assertIsString($result);
        $this->assertEquals(bccomp('3', $result), 0);
        $result = Common::numAdd(0.1, 0.2);
        $this->assertEquals(bccomp('0.3', $result), 0);
        $result = Common::numAdd(0.01, 0.02);
        $this->assertEquals(bccomp('0.03', $result), 0);
        $result = Common::numAdd(0.001, 0.002);
        $this->assertEquals(bccomp('0.003', $result), 0);
        $result = Common::numAdd(0.0001, 0.0002);
        $this->assertEquals(bccomp('0.0003', $result), 0);
        $result = Common::numAdd(0.00001, 0.00002);
        $this->assertEquals(bccomp('0.00003', $result), 0);
        $result = Common::numAdd(0.000001, 0.000002);
        $this->assertEquals(bccomp('0.000003', $result), 0);
        $result = Common::numAdd(0.0000001, 0.0000002);
        $this->assertEquals(bccomp('0.0000003', $result), 0);
        $result = Common::numAdd(0.00000001, 0.00000002);
        $this->assertEquals(bccomp('0.00000003', $result), 0);
        $result = Common::numAdd(0.000000001, 0.000000002);
        $this->assertEquals(bccomp('0.000000003', $result), 0);
        $result = Common::numAdd(0.0000000001, 0.0000000002);
        $this->assertEquals(bccomp('0.0000000003', $result), 0);
        $result = Common::numAdd(0.00000000001, 0.00000000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numAdd(0.000000000001, 0.000000000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numAdd(10000, 20000);
        $this->assertEquals(bccomp('30000', $result), 0);
        $result = Common::numAdd('1000000000000000000000000', '2000000000000000000000000');
        $this->assertEquals(bccomp('3000000000000000000000000', $result), 0);
    }
}
