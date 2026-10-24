<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Models\MemberBindingLog;
use App\Models\Order;
use App\Models\RealnameValidationRequest;
use App\Models\TransactionLog;
use App\Services\SystemNotifyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SystemNotifyService 測試
 *
 * php artisan test tests/Unit/Services/SystemNotifyServiceTest.php
 */
class SystemNotifyServiceTest extends TestCase
{
    private SystemNotifyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'snsvc-' . uniqid('', true) . '.local';
        $this->service = new SystemNotifyService;
    }

    public function test_get_order_logs_maps_status_and_includes_order_type_name(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(30000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'sn_ord_' . uniqid()]);

        $order = Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => 0,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'order_no' => 'SNO' . Str::random(20),
            'third_party_order_no' => '',
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'admin' => 0,
        ]);

        $start = Carbon::now()->subHour();
        $rows = $this->service->getOrderLogs($start, $member->id, 1);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('orders', $row['tag']);
        $this->assertSame('success', $row['status']);
        $this->assertSame($order->id, $row['data_id']);
    }

    public function test_get_transaction_logs_includes_matching_types(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sn_tl_' . uniqid()]);

        TransactionLog::create([
            'transaction_no' => (string) Str::uuid(),
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::REGISTRATION_GIFT->value,
            'point' => '1.0000',
            'tax' => '0.0000',
            'vendor_point' => '0.0000',
            'rate' => '1.0000',
            'before_balance' => '0.0000',
            'balance' => '1.0000',
            'vendor_balance' => '0.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => '0',
            'remark' => '',
            'admin' => 0,
        ]);

        $start = Carbon::now()->subMinute();
        $rows = $this->service->getTransactionLogs($start, $member->id);

        $this->assertCount(1, $rows);
        $this->assertSame('reward', $rows->first()['tag']);
    }

    public function test_get_member_binding_logs_formats_status(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sn_mb_' . uniqid()]);

        MemberBindingLog::create([
            'type' => 1,
            'member_id' => $member->id,
            'before_value' => null,
            'value' => '0999',
            'ip' => '0.0.0.0',
            'validate_type' => 1,
            'status' => 1,
            'remark' => '',
            'admin' => 0,
        ]);

        $start = Carbon::now()->subMinute();
        $rows = $this->service->getMemberBindingLogs($start, $member->id);

        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows->first()['status']);
        $this->assertSame('system', $rows->first()['tag']);
    }

    public function test_get_realname_validation_request_logs_maps_passed_status(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sn_rn_' . uniqid()]);

        RealnameValidationRequest::create([
            'member_id' => $member->id,
            'username' => $member->username,
            'validator_id' => null,
            'realname' => 'Tester',
            'bank_name' => 'Test Bank',
            'bank_account' => '000111',
            'status' => 1,
            'remark' => '',
        ]);

        $start = Carbon::now()->subMinute();
        $rows = $this->service->getRealnameValidationRequestLogs($start, $member->id);

        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows->first()['status']);
    }
}
