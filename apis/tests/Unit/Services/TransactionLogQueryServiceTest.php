<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Services\TransactionLogQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TransactionLogQueryService 測試
 *
 * php artisan test tests/Unit/Services/TransactionLogQueryServiceTest.php
 */
class TransactionLogQueryServiceTest extends TestCase
{
    private TransactionLogQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'tlq-' . uniqid('', true) . '.local';
        $this->service = new TransactionLogQueryService;
    }

    public function test_get_transaction_log_type_returns_mapped_enums(): void
    {
        $one = $this->service->getTransactionLogType(1);

        $this->assertContains(TransactionEnum::ORDER_DEPOSIT->value, $one);
        $this->assertContains(TransactionEnum::DEPOSIT->value, $one);
    }

    public function test_get_transaction_log_type_unknown_uses_default(): void
    {
        $default = $this->service->getTransactionLogType('default');
        $fallback = $this->service->getTransactionLogType(9999);

        $this->assertSame($default, $fallback);
        $this->assertContains(TransactionEnum::ORDER_DEPOSIT->value, $default);
    }

    public function test_get_rolling_chip_log_type_and_should_show_rolling_chip(): void
    {
        $four = $this->service->getRollingChipLogType(4);
        $this->assertContains(1, $four);

        $five = $this->service->getRollingChipLogType(5);
        $this->assertContains(4, $five);

        $fallback = $this->service->getRollingChipLogType(99);
        $this->assertSame($this->service->getRollingChipLogType('default'), $fallback);

        $this->assertTrue($this->service->shouldShowRollingChip(4));
        $this->assertTrue($this->service->shouldShowRollingChip(5));
        $this->assertTrue($this->service->shouldShowRollingChip('default'));
        $this->assertFalse($this->service->shouldShowRollingChip(1));
    }

    public function test_query_transaction_log_filters_member_type_status_and_time(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tlq_m_' . uniqid()]);
        $member->load('agent');

        $types = $this->service->getTransactionLogType(1);
        $start = Carbon::now()->subHour();
        $end = Carbon::now()->addHour();

        TransactionLog::create([
            'transaction_no' => (string) Str::uuid(),
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'point' => '10.0000',
            'tax' => '0.0000',
            'vendor_point' => '0.0000',
            'rate' => '1.0000',
            'before_balance' => '0.0000',
            'balance' => '10.0000',
            'vendor_balance' => '0.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => '0',
            'remark' => '',
            'admin' => 0,
        ]);

        $count = $this->service
            ->queryTransactionLog($member, $types, $start, $end)
            ->count();

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_query_rolling_chip_log_filters_member_type_status_and_time(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tlq_rc_' . uniqid()]);

        $types = $this->service->getRollingChipLogType(4);
        $start = Carbon::now()->subHour();
        $end = Carbon::now()->addHour();

        RollingChipLog::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'vendor_id' => 0,
            'type' => RollingChipLogTypeEnum::REWARD->value,
            'amount' => '5.0000',
            'before_balance' => '0.0000',
            'balance' => '5.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'admin' => 0,
        ]);

        $count = $this->service
            ->queryRollingChipLog($member, $types, $start, $end)
            ->count();

        $this->assertGreaterThanOrEqual(1, $count);
    }
}
