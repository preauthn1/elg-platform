<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Services\Payment\Traits\WithdrawalCallbackTrait;
use Tests\TestCase;

class WithdrawalCallbackOutcomeTest extends TestCase
{
    private object $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = new class
        {
            use WithdrawalCallbackTrait;

            public function resolve(int|string $s, array $ok, array $fail): WithdrawalCallbackOutcome
            {
                return $this->resolveWithdrawalOutcome($s, $ok, $fail);
            }

            public function update(\App\Models\WithdrawalOrder $o, WithdrawalCallbackOutcome $oc): ?\App\Models\TransactionLog
            {
                return $this->updateWithdrawalOrderStatus($o, $oc);
            }

            protected function getDriverName(): string
            {
                return 'TestDriver';
            }
        };
    }

    public function test_success_code_maps_to_success(): void
    {
        $this->assertSame(WithdrawalCallbackOutcome::SUCCESS, $this->sut->resolve('00000', ['00000'], ['10015']));
    }

    public function test_failure_code_maps_to_failure(): void
    {
        $this->assertSame(WithdrawalCallbackOutcome::FAILURE, $this->sut->resolve('10015', ['00000'], ['10015']));
    }

    public function test_unknown_code_maps_to_indeterminate(): void
    {
        $this->assertSame(WithdrawalCallbackOutcome::INDETERMINATE, $this->sut->resolve('99999', ['00000'], ['10015']));
    }

    public function test_int_status_normalized_to_string(): void
    {
        $this->assertSame(WithdrawalCallbackOutcome::SUCCESS, $this->sut->resolve(1, ['1'], ['2']));
        $this->assertSame(WithdrawalCallbackOutcome::FAILURE, $this->sut->resolve(2, ['1'], ['2']));
    }

    public function test_indeterminate_keeps_status_22(): void
    {
        $order = \App\Models\WithdrawalOrder::factory()->create([
            'status' => \App\Enums\WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        ]);
        $result = $this->sut->update($order, WithdrawalCallbackOutcome::INDETERMINATE);
        $this->assertNull($result);
        $this->assertSame(22, $order->fresh()->status);
    }

    public function test_success_sets_24_and_failure_sets_29(): void
    {
        $ok = \App\Models\WithdrawalOrder::factory()->create(['status' => 22]);
        $this->sut->update($ok, WithdrawalCallbackOutcome::SUCCESS);
        $this->assertSame(24, $ok->fresh()->status);

        $fail = \App\Models\WithdrawalOrder::factory()->create(['status' => 22]);
        $this->sut->update($fail, WithdrawalCallbackOutcome::FAILURE);
        $this->assertSame(29, $fail->fresh()->status);
    }

    public function test_success_writes_payment_time_when_null(): void
    {
        $order = \App\Models\WithdrawalOrder::factory()->create(['status' => 22, 'payment_time' => null]);
        $this->sut->update($order, WithdrawalCallbackOutcome::SUCCESS);
        $this->assertNotNull($order->fresh()->payment_time);
    }

    public function test_success_does_not_overwrite_existing_payment_time(): void
    {
        $order = \App\Models\WithdrawalOrder::factory()->create([
            'status' => 22,
            'payment_time' => '2020-01-01 00:00:00',
        ]);
        $this->sut->update($order, WithdrawalCallbackOutcome::SUCCESS);
        $this->assertSame('2020-01-01 00:00:00', $order->fresh()->payment_time);
    }
}
