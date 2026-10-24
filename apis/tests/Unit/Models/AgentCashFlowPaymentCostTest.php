<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AgentCashFlow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 支付商手續費（成本）計算：point × cost 截到小數第 4 位，不四捨五入 target/tickets#4361。
 *
 * php artisan test tests/Unit/Models/AgentCashFlowPaymentCostTest.php
 */
class AgentCashFlowPaymentCostTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            '2% 整數金額' => ['0.0200', '1000.0000', '20.0000'],
            '截尾不進位（3.34665 → 3.3466）' => ['0.0333', '100.5000', '3.3466'],
            '費率 0 的渠道' => ['0.0000', '100.5000', '0.0000'],
            'float 入境正規化（0.025 → 0.0250）' => ['0.025', '300.0000', '7.5000'],
        ];
    }

    #[DataProvider('cases')]
    public function test_payment_cost_for_truncates_to_four_decimals(string $cost, string $point, string $expected): void
    {
        $agentCashFlow = new AgentCashFlow;
        $agentCashFlow->cost = $cost;

        $this->assertSame($expected, $agentCashFlow->paymentCostFor($point));
    }
}
