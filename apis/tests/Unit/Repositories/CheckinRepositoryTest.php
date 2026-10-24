<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\CheckinActivity;
use App\Models\CheckinPrize;
use App\Models\CheckinRecord;
use App\Models\FlowLimit;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Repositories\CheckinRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CheckinRepository 測試
 *
 * php artisan test tests/Unit/Repositories/CheckinRepositoryTest.php
 */
class CheckinRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.timezone', 'UTC');
        Config::set('custom.frontend.timezone', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function repository(): CheckinRepository
    {
        return new CheckinRepository;
    }

    private function seedActivity(int $generalAgentId): CheckinActivity
    {
        return CheckinActivity::create([
            'agent_id' => $generalAgentId,
            'flow_limit_multiple' => 2,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'start_date' => '2026-04-01 00:00:00',
            'end_date' => '2026-12-31 23:59:59',
            'status' => 1,
        ]);
    }

    public function test_get_checkin_activity_by_agent_id_returns_activity_with_prizes(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 10:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $generalAgentId = $agent->general_agent_id;

        $activity = $this->seedActivity($generalAgentId);
        CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 1,
            'prize_type' => 1,
            'prize_amount' => '10.0000',
        ]);
        CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 2,
            'prize_type' => 2,
            'prize_amount' => '20.0000',
        ]);

        $found = $this->repository()->getCheckinActivityByAgentId($generalAgentId);

        $this->assertNotNull($found);
        $this->assertSame($activity->id, $found->id);
        $this->assertCount(2, $found->prizes);
    }

    public function test_get_checkin_activity_by_agent_id_returns_null_when_out_of_range(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 10:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $generalAgentId = $agent->general_agent_id;

        CheckinActivity::create([
            'agent_id' => $generalAgentId,
            'flow_limit_multiple' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'start_date' => '2025-01-01 00:00:00',
            'end_date' => '2025-06-01 00:00:00',
            'status' => 1,
        ]);

        $found = $this->repository()->getCheckinActivityByAgentId($generalAgentId);

        $this->assertNull($found);
    }

    public function test_has_checked_in_today_returns_true_when_record_exists(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->seedActivity($agent->general_agent_id);

        CheckinRecord::forceCreate([
            'checkin_activity_id' => $activity->id,
            'member_id' => $member->id,
            'created_at' => '2026-04-14 12:00:00',
            'updated_at' => '2026-04-14 12:00:00',
        ]);

        $result = DB::transaction(fn () => $this->repository()->hasCheckedInToday($member->id));

        $this->assertTrue($result);
    }

    public function test_confirm_block_record_detects_duplicate_ip(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $activity = $this->seedActivity($agent->general_agent_id);

        $longIp = (int) ip2long('0.0.0.0');

        $other = $this->createMember($agent, [
            'username' => 'm_other_' . uniqid(),
            'last_login_ip' => $longIp,
        ]);

        CheckinRecord::forceCreate([
            'checkin_activity_id' => $activity->id,
            'member_id' => $other->id,
            'created_at' => '2026-04-14 11:00:00',
            'updated_at' => '2026-04-14 11:00:00',
        ]);

        $repo = $this->repository();

        $this->assertTrue($repo->confirmBlockRecord($activity->id, ['long_ip' => $longIp]));
    }

    public function test_get_checkin_dates_throws_when_activity_has_no_prize(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);
        $activity = $this->seedActivity($agent->general_agent_id);

        $repo = $this->repository();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('No corresponding prize found for this activity');

        $repo->getCheckinDates($activity->id, $member->id);
    }

    public function test_get_checkin_dates_returns_dates_newest_first(): void
    {
        Config::set('custom.frontend.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);
        $activity = $this->seedActivity($agent->general_agent_id);

        CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 1,
            'prize_type' => 1,
            'prize_amount' => '1',
        ]);

        CheckinRecord::insert([
            [
                'checkin_activity_id' => $activity->id,
                'member_id' => $member->id,
                'created_at' => '2026-04-10 12:00:00',
                'updated_at' => '2026-04-10 12:00:00',
            ],
            [
                'checkin_activity_id' => $activity->id,
                'member_id' => $member->id,
                'created_at' => '2026-04-14 09:00:00',
                'updated_at' => '2026-04-14 09:00:00',
            ],
        ]);

        $dates = $this->repository()->getCheckinDates($activity->id, $member->id);

        $this->assertSame(['2026-04-14', '2026-04-10'], $dates);
    }

    public function test_get_checkin_prize_and_get_checkin_all_prize(): void
    {
        $agent = $this->createAgentAndDomain();
        $activity = $this->seedActivity($agent->general_agent_id);

        CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 1,
            'prize_type' => 1,
            'prize_amount' => '5.0000',
        ]);
        $p2 = CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 2,
            'prize_type' => 2,
            'prize_amount' => '7.0000',
        ]);

        $repo = $this->repository();

        $day2 = $repo->getCheckinPrize($activity->id, 2);
        $this->assertCount(1, $day2);
        $this->assertSame($p2->id, $day2->first()->id);

        $all = $repo->getCheckinAllPrize($activity->id);
        $this->assertCount(2, $all);
        $this->assertSame(2, $all->first()->day);
    }

    public function test_checkin_creates_record(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);
        $activity = $this->seedActivity($agent->general_agent_id);

        $record = $this->repository()->checkin($activity->id, $member->id);

        $this->assertSame($activity->id, $record->checkin_activity_id);
        $this->assertSame($member->id, $record->member_id);
        $this->assertDatabaseHas('checkin_records', [
            'id' => $record->id,
            'checkin_activity_id' => $activity->id,
            'member_id' => $member->id,
        ]);
    }

    public function test_create_checkin_prize_record(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);
        $activity = $this->seedActivity($agent->general_agent_id);
        $prize = CheckinPrize::create([
            'checkin_activity_id' => $activity->id,
            'day' => 1,
            'prize_type' => 1,
            'prize_amount' => '1',
        ]);
        $checkin = CheckinRecord::create([
            'checkin_activity_id' => $activity->id,
            'member_id' => $member->id,
        ]);

        $row = $this->repository()->createCheckinPrizeRecord($checkin->id, $prize->id);

        $this->assertDatabaseHas('checkin_prize_records', [
            'id' => $row->id,
            'checkin_record_id' => $checkin->id,
            'checkin_prize_id' => $prize->id,
        ]);
    }

    public function test_create_wallet_transaction_log_sets_enums(): void
    {
        $data = [
            'transaction_no' => '90001',
            'agent_id' => 1,
            'member_id' => 2,
            'point' => '10.0000',
            'before_balance' => '0.0000',
            'balance' => '10.0000',
        ];

        $log = $this->repository()->createWalletTransactionLog($data);

        $this->assertInstanceOf(WalletTransactionLog::class, $log);
        $this->assertSame(WalletEnum::CODE_GAME, $log->wallet_type_id);
        $this->assertSame(WalletTransactionEnum::GAME_WALLET_CHECK_IN, $log->type);
        $this->assertSame(TransactionStatusEnum::STATUS_SUCCESS, $log->status);
    }

    public function test_create_transaction_log_sets_defaults(): void
    {
        $data = [
            'transaction_no' => '90002',
            'agent_id' => 1,
            'member_id' => 2,
            'point' => '3.0000',
            'before_balance' => '1.0000',
            'balance' => '4.0000',
        ];

        $log = $this->repository()->createTransactionLog($data);

        $this->assertInstanceOf(TransactionLog::class, $log);
        $this->assertSame(TransactionEnum::CHECKIN_REWARD, $log->type);
        $this->assertSame('打卡活動', $log->remark);
        $this->assertSame(0, $log->admin);
    }

    public function test_create_flow_limit_sets_type_and_event(): void
    {
        $data = [
            'member_id' => 99,
            'reference_id' => 10,
            'wallet_transaction_log_id' => 0,
            'flow_limit' => '100.0000',
        ];

        $flow = $this->repository()->createFlowLimit($data);

        $this->assertInstanceOf(FlowLimit::class, $flow);
        $this->assertSame(1, $flow->type);
        $this->assertSame(0, $flow->event_id);
        $this->assertSame(0, (int) $flow->reach);
    }

    public function test_create_rolling_chip_log_sets_type_and_status(): void
    {
        $data = [
            'agent_id' => 1,
            'member_id' => 2,
            'amount' => '5.0000',
            'before_balance' => '0.0000',
            'balance' => '5.0000',
            'remark' => '打卡活動',
        ];

        $log = $this->repository()->createRollingChipLog($data);

        $this->assertInstanceOf(RollingChipLog::class, $log);
        $this->assertSame(RollingChipLogTypeEnum::REWARD, $log->type);
        $this->assertSame(1000, $log->status);
    }
}
