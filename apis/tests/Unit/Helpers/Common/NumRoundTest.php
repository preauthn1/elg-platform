<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::numRound 四捨五入（half away from zero）測試
 *
 * php artisan test tests/Unit/Helpers/Common/NumRoundTest.php
 */
class NumRoundTest extends TestCase
{
    public function test_rounds_half_away_from_zero(): void
    {
        $this->assertSame('73.60', Common::numRound('73.595', 2));
        $this->assertSame('73.59', Common::numRound('73.594999', 2));
        $this->assertSame('-57.51', Common::numRound('-57.505', 2));
        $this->assertSame('-57.50', Common::numRound('-57.504999', 2));
        $this->assertSame('748.00', Common::numRound('747.9999999999', 2));
        $this->assertSame('1.00', Common::numRound(0.995, 2));
        $this->assertSame('124', Common::numRound('123.5', 0));
        $this->assertSame('-124', Common::numRound('-123.5', 0));
    }

    public function test_zero_results_are_positive_zero(): void
    {
        $this->assertSame('0.00', Common::numRound('-0.004', 2));
        $this->assertSame('0.00', Common::numRound('0', 2));
        $this->assertSame('0.00', Common::numRound('-0.00', 2));
    }

    public function test_keeps_exact_values(): void
    {
        $this->assertSame('100.00', Common::numRound('100', 2));
        $this->assertSame('-552.00', Common::numRound('-552.0000000000', 2));
        $this->assertSame('0.1235', Common::numRound('0.12345', 4));
    }
}
