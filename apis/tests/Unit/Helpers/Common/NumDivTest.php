<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numDiv 數值除法測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumDivTest.php
 */
class NumDivTest extends TestCase
{
    public function test()
    {
        $result = Common::numDiv(1, 2);
        $this->assertEquals(12, strlen($result));
        $this->assertIsString($result);
        $this->assertEquals(bccomp('0.5', $result), 0);
        $result = Common::numDiv(0.1, 0.2);
        $this->assertEquals(bccomp('0.5', $result), 0);
        $result = Common::numDiv(10000, 20000);
        $this->assertEquals(bccomp('0.5', $result), 0);
        $result = Common::numDiv('1000000000000000000000000', '2000000000000000000000000');
        $this->assertEquals(bccomp('0.5', $result), 0);
        $result = Common::numDiv(1, 3);
        $this->assertEquals(bccomp('0.3333333333', $result), 0);
    }
}
