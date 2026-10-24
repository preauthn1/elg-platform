<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numCompare 數值比較測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumCompareTest.php
 */
class NumCompareTest extends TestCase
{
    public function test()
    {
        $result = Common::numCompare(1, 2);
        $this->assertEquals(-1, $result);
        $result = Common::numCompare(2, 1);
        $this->assertEquals(1, $result);
        $result = Common::numCompare(1, 1);
        $this->assertEquals(0, $result);
        $result = Common::numCompare('1', '2');
        $this->assertEquals(-1, $result);
        $result = Common::numCompare('2', '1');
        $this->assertEquals(1, $result);
        $result = Common::numCompare('1', '1');
        $this->assertEquals(0, $result);

        $result = Common::numCompare('1000000000000000000000000', '2000000000000000000000000');
        $this->assertEquals(-1, $result);
        $result = Common::numCompare('1000000000000000000000000', '1000000000000000000000000');
        $this->assertEquals(0, $result);
        $result = Common::numCompare('2000000000000000000000000', '1000000000000000000000000');
        $this->assertEquals(1, $result);
    }
}
