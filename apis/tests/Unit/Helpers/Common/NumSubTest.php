<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numSub 數值減法測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumSubTest.php
 */
class NumSubTest extends TestCase
{
    public function test()
    {
        $result = Common::numSub(1, 2);
        $this->assertEquals(13, strlen($result));
        $this->assertIsString($result);
        $this->assertEquals(bccomp('-1', $result), 0);
        $result = Common::numSub(0.1, 0.2);
        $this->assertEquals(bccomp('-0.1', $result), 0);
        $result = Common::numSub(0.01, 0.02);
        $this->assertEquals(bccomp('-0.01', $result), 0);
        $result = Common::numSub(0.001, 0.002);
        $this->assertEquals(bccomp('-0.001', $result), 0);
        $result = Common::numSub(0.0001, 0.0002);
        $this->assertEquals(bccomp('-0.0001', $result), 0);
        $result = Common::numSub(0.00001, 0.00002);
        $this->assertEquals(bccomp('-0.00001', $result), 0);
        $result = Common::numSub(0.000001, 0.000002);
        $this->assertEquals(bccomp('-0.000001', $result), 0);
        $result = Common::numSub(0.0000001, 0.0000002);
        $this->assertEquals(bccomp('-0.0000001', $result), 0);
        $result = Common::numSub(0.00000001, 0.00000002);
        $this->assertEquals(bccomp('-0.00000001', $result), 0);
        $result = Common::numSub(0.000000001, 0.000000002);
        $this->assertEquals(bccomp('-0.000000001', $result), 0);
        $result = Common::numSub(0.0000000001, 0.0000000002);
        $this->assertEquals(bccomp('-0.0000000001', $result), 0);
        $result = Common::numSub(0.00000000001, 0.00000000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numSub(0.000000000001, 0.000000000002);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::numSub(10000, 20000);
        $this->assertEquals(bccomp('-10000', $result), 0);
        $result = Common::numSub('1000000000000000000000000', '2000000000000000000000000');
        $this->assertEquals(bccomp('-1000000000000000000000000', $result), 0);
    }
}
