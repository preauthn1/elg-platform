<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FlashPay;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\FlashPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/Payment/Providers/FlashPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $info = [
            'API_URL' => 'https://example.invalid/v3',
            'CLIENT_ID' => '8e363c65-6dec-11f1-8a6d-0200009ee8a2',
            'SECRET_KEY' => '5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97',
            'CURRENCY' => 'SGD',
            'available_banks' => [],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($info),
        ]);
        $this->agentCashFlow->info = $info;
    }

    public function test_success(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 500,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'status_code' => 0,
                'status_msg' => 'NO_ERROR',
                'tran_token' => 'payout-token-123',
                'tran_customer' => $this->member->username,
                'bank_code' => 'DBS',
                'bank_account_name' => 'Test User',
                'bank_account_number' => '1234567890',
                'tran_amount' => 500,
                'tran_unique' => 'FLASH' . time(),
                'tran_fee' => 2.50,
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from([
            'order' => $withdrawalOrder,
        ]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
    }

    public function test_api_error_response(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 500,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'status_code' => -1,
                'status_msg' => 'INSUFFICIENT_BALANCE',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from([
            'order' => $withdrawalOrder,
        ]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
    }
}
