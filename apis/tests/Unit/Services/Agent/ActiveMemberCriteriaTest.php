<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Services\Agent\ActiveMemberCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 活躍玩家門檻比較（7006 充值／7007 有效流水，target/tickets#4121、#4362 共用）。
 *
 * 只驗 `passes()` 的比較規則，門檻取值走 `Agent::getSettingValue()`，由 GGR 結算的 EngineTest 覆蓋。
 *
 * php artisan test --compact tests/Unit/Services/Agent/ActiveMemberCriteriaTest.php
 */
class ActiveMemberCriteriaTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function cases(): array
    {
        return [
            '兩門檻皆 0：任何值都通過' => ['0', '0', '0.0000', '0.0000', true],
            '兩門檻都達到（等於門檻也算）' => ['100', '10', '100.0000', '10.0000', true],
            '充值未達' => ['100', '10', '99.9999', '500.0000', false],
            '有效流水未達' => ['100', '10', '500.0000', '9.9999', false],
            '只設充值門檻：流水 0 也通過' => ['100', '0', '150.0000', '0.0000', true],
            '只設流水門檻：充值 0 也通過' => ['0', '10', '0.0000', '10.0000', true],
            '只設流水門檻：流水未達' => ['0', '10', '1000.0000', '5.0000', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_passes(string $depositMin, string $validBetMin, string $deposit, string $validBet, bool $expected): void
    {
        $criteria = new ActiveMemberCriteria(depositMin: $depositMin, validBetMin: $validBetMin);

        $this->assertSame($expected, $criteria->passes($deposit, $validBet));
    }
}
