<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗證代付 point 回填：point<=0 且 amount>0 者補為 amount × rate；已有值/amount=0 不動；dry-run 不寫入。
 *
 * php artisan test tests/Feature/Console/BackfillWithdrawalPointTest.php
 */
class BackfillWithdrawalPointTest extends TestCase
{
    public function test_backfills_point_as_amount_times_rate(): void
    {
        $zero = $this->makeWithdrawal(point: '0', amount: '100', rate: '2');
        $tiny = $this->makeWithdrawal(point: '0', amount: '50000', rate: '0.00023');
        $already = $this->makeWithdrawal(point: '99', amount: '100', rate: '2');
        $noAmount = $this->makeWithdrawal(point: '0', amount: '0', rate: '2');

        $this->artisan('patch:backfill-withdrawal-point')->assertSuccessful();

        // point<=0 且 amount>0 → 補為 amount × rate
        $this->assertEquals('200.0000', $this->point($zero));
        $this->assertEquals('11.5000', $this->point($tiny));
        // 已有 point → 不覆蓋
        $this->assertEquals('99.0000', $this->point($already));
        // amount=0 → 維持 0
        $this->assertEquals('0.0000', $this->point($noAmount));
    }

    public function test_dry_run_does_not_write(): void
    {
        $id = $this->makeWithdrawal(point: '0', amount: '100', rate: '2');

        $this->artisan('patch:backfill-withdrawal-point', ['--dry-run' => true])->assertSuccessful();

        $this->assertEquals('0.0000', $this->point($id));
    }

    private function makeWithdrawal(string $point, string $amount, string $rate): int
    {
        $order = WithdrawalOrder::factory()->create([
            'point' => $point,
            'amount' => $amount,
            'rate' => $rate,
        ]);

        return $order->id;
    }

    private function point(int $id): string
    {
        return (string) DB::table('withdrawal_orders')->where('id', $id)->value('point');
    }
}
