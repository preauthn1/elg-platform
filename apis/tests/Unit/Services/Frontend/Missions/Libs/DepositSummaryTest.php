<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Frontend\Missions\Libs;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Mission;
use App\Models\TransactionLog;
use App\Services\Frontend\Missions\Libs\DepositSummary;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * target/tickets#4352：任務獎勵的儲值彙總（DepositSummary）比照活動 V2／新人專享，代理組別設置
 * AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT 開啟時，後台開分
 * （transaction_logs type=DEPOSIT，無對應 orders 列）也計入任務進度彙總。
 *
 * php artisan test tests/Unit/Services/Frontend/Missions/Libs/DepositSummaryTest.php
 */
class DepositSummaryTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createAmountMission(): Mission
    {
        return Mission::factory()->create([
            'agent_id' => $this->agent->id,
            'info' => [
                'action_config' => ['type' => 'deposit'],
                'reward_config' => ['type' => 'balance', 'value' => '100'],
                'condition_config' => ['type' => 'amount', 'value' => '30'],
            ],
        ]);
    }

    /** @return TransactionLog 模擬 CS 後台 newTransaction() type=DEPOSIT 建立的紀錄：沒有 orders 列 */
    private function createBackendDeposit(float $point): TransactionLog
    {
        return TransactionLog::create([
            'transaction_no' => 'ut_' . uniqid(),
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => $point,
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => 1000,
            'balance' => 1000 + $point,
            'vendor_balance' => 0,
            'status' => 1000,
            'remark' => '',
            'result' => '',
            'operator' => 'cs_operator',
            'admin' => 0,
        ]);
    }

    public function test_backend_deposit_excluded_when_setting_disabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT, 0);
        $mission = $this->createAmountMission();
        $this->createBackendDeposit(50);

        $start = now()->subHour()->toDateTimeString();
        $end = now()->addHour()->toDateTimeString();

        $summary = (new DepositSummary)->exec($mission, $start, $end)->get();

        $this->assertTrue($summary->where('member_id', $this->member->id)->isEmpty());
    }

    public function test_backend_deposit_included_when_setting_enabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT, 1);
        $mission = $this->createAmountMission();
        $this->createBackendDeposit(50);

        $start = now()->subHour()->toDateTimeString();
        $end = now()->addHour()->toDateTimeString();

        $summary = (new DepositSummary)->exec($mission, $start, $end)->get();

        $row = $summary->firstWhere('member_id', $this->member->id);
        $this->assertNotNull($row);
        $this->assertEquals(50, (float) $row->progress);
    }

    public function test_backend_deposit_adds_on_top_of_real_deposit_when_enabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT, 1);
        $mission = $this->createAmountMission();
        $this->createBackendDeposit(50);

        TransactionLog::create([
            'transaction_no' => 'ut_' . uniqid(),
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'vendor_id' => 0,
            'order_id' => 1,
            'type' => TransactionEnum::ORDER_DEPOSIT->value,
            'point' => 30,
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => 1000,
            'balance' => 1030,
            'vendor_balance' => 0,
            'status' => 1000,
            'remark' => '',
            'result' => '',
            'operator' => 'system',
            'admin' => 0,
        ]);

        $start = now()->subHour()->toDateTimeString();
        $end = now()->addHour()->toDateTimeString();

        $summary = (new DepositSummary)->exec($mission, $start, $end)->get();

        $row = $summary->firstWhere('member_id', $this->member->id);
        $this->assertNotNull($row);
        $this->assertEquals(80, (float) $row->progress);
    }
}
