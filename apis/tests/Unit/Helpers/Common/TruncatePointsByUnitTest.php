<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::truncatePointsByUnit 小數截斷測試
 *
 * php artisan test tests/Unit/Helpers/Common/TruncatePointsByUnitTest.php
 */
class TruncatePointsByUnitTest extends TestCase
{
    public function test()
    {
        $result = Common::truncatePointsByUnit(0.01, 0.1);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::truncatePointsByUnit(1000.877, 0.01);
        $this->assertEquals(bccomp('1000.87', $result), 0);
        $result = Common::truncatePointsByUnit(1000.877, 0.02);
        $this->assertEquals(bccomp('1000.86', $result), 0);
        $result = Common::truncatePointsByUnit(1000.877, 0.1);
        $this->assertEquals(bccomp('1000.8', $result), 0);
        $result = Common::truncatePointsByUnit(1000.877, 1);
        $this->assertEquals(bccomp('1000', $result), 0);
        $result = Common::truncatePointsByUnit(1000.877, 0.5);
        $this->assertEquals(bccomp('1000.5', $result), 0);

        $result = Common::truncatePointsByUnit('0.01', '0.1');
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::truncatePointsByUnit('1000.877', '0.01');
        $this->assertEquals(bccomp('1000.87', $result), 0);
        $result = Common::truncatePointsByUnit('1000.877', '0.02');
        $this->assertEquals(bccomp('1000.86', $result), 0);
        $result = Common::truncatePointsByUnit('1000.877', '0.1');
        $this->assertEquals(bccomp('1000.8', $result), 0);
        $result = Common::truncatePointsByUnit('1000.877', '1');
        $this->assertEquals(bccomp('1000', $result), 0);
        $result = Common::truncatePointsByUnit('1000.877', '0.5');
        $this->assertEquals(bccomp('1000.5', $result), 0);

        $result = Common::truncatePointsByUnit(-0.01, 0.1);
        $this->assertEquals(bccomp('0', $result), 0);
        $result = Common::truncatePointsByUnit(-1000.877, 0.01);
        $this->assertEquals(bccomp('-1000.87', $result), 0);
        $result = Common::truncatePointsByUnit(-1000.877, 0.02);
        $this->assertEquals(bccomp('-1000.86', $result), 0);
        $result = Common::truncatePointsByUnit(-1000.877, 0.1);
        $this->assertEquals(bccomp('-1000.8', $result), 0);
        $result = Common::truncatePointsByUnit(-1000.877, 1);
        $this->assertEquals(bccomp('-1000', $result), 0);
        $result = Common::truncatePointsByUnit(-1000.877, 0.5);
        $this->assertEquals(bccomp('-1000.5', $result), 0);
    }
}
