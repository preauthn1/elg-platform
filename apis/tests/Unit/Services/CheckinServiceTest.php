<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\CheckinActivity;
use App\Models\CheckinPrize;
use App\Models\MemberWallet;
use App\Repositories\CheckinRepository;
use App\Services\CheckinService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * CheckinService 測試
 *
 * php artisan test tests/Unit/Services/CheckinServiceTest.php
 */
class CheckinServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('custom.frontend.timezone', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bindService(CheckinRepository $checkinRepository): CheckinService
    {
        $this->instance(CheckinRepository::class, $checkinRepository);

        return new CheckinService($checkinRepository, new WalletService);
    }

    private function makeActivityWithPrizes(int $maxDay, array $prizeRows): CheckinActivity
    {
        $activity = new CheckinActivity;
        $activity->forceFill([
            'id' => 1,
            'flow_limit_multiple' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2030-12-31 23:59:59',
        ]);

        $prizes = collect();
        foreach ($prizeRows as $row) {
            $prizes->push(new CheckinPrize(array_merge([
                'checkin_activity_id' => 1,
            ], $row)));
        }
        $activity->setRelation('prizes', $prizes);

        return $activity;
    }

    public function test_checkin_records_throws_when_activity_not_found(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')
            ->once()
            ->with($member->agent->general_agent_id)
            ->andReturn(null);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Activity not found');

        $service->checkinRecords($member);
    }

    public function test_checkin_records_not_checked_in_today_starts_at_day_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->makeActivityWithPrizes(2, [
            ['day' => 1, 'prize_type' => 1, 'prize_amount' => '10.0000'],
            ['day' => 2, 'prize_type' => 2, 'prize_amount' => '20.0000'],
        ]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('getCheckinDates')->once()->with(1, $member->id)->andReturn([]);

        $service = $this->bindService($mock);
        $rows = $service->checkinRecords($member);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['day']);
        $this->assertFalse($rows[0]['is_checkin']);
        $this->assertTrue($rows[0]['checkin_day']);
        $this->assertSame(__('param.checkin.prize_type.1'), $rows[0]['prize'][0]['prize_type']);
        $this->assertSame('10.0000', $rows[0]['prize'][0]['prize_amount']);
    }

    public function test_checkin_records_checked_in_today_wraps_checkin_day_after_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->makeActivityWithPrizes(3, [
            ['day' => 1, 'prize_type' => 1, 'prize_amount' => '1'],
            ['day' => 2, 'prize_type' => 1, 'prize_amount' => '2'],
            ['day' => 3, 'prize_type' => 1, 'prize_amount' => '3'],
        ]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('getCheckinDates')->once()->andReturn([
            '2026-04-14',
            '2026-04-13',
            '2026-04-12',
        ]);

        $service = $this->bindService($mock);
        $rows = $service->checkinRecords($member);

        $this->assertCount(3, $rows);
        $this->assertTrue($rows[0]['is_checkin']);
        $this->assertTrue($rows[1]['is_checkin']);
        $this->assertTrue($rows[2]['is_checkin']);
        $this->assertTrue($rows[0]['checkin_day']);
    }

    public function test_checkin_throws_when_activity_not_found(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn(null);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Activity not found');

        $service->checkin($member);
    }

    public function test_checkin_throws_when_already_checked_in_today(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->makeActivityWithPrizes(1, [
            ['day' => 1, 'prize_type' => 1, 'prize_amount' => '1'],
        ]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(true);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Already checked in');

        $service->checkin($member);
    }

    public function test_checkin_throws_when_activity_out_of_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = new CheckinActivity;
        $activity->forceFill([
            'id' => 9,
            'flow_limit_multiple' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2020-06-01 00:00:00',
        ]);
        $activity->setRelation('prizes', collect());

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Activity not in date');

        $service->checkin($member);
    }

    public function test_checkin_throws_when_ip_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = new CheckinActivity;
        $activity->forceFill([
            'id' => 10,
            'flow_limit_multiple' => 0,
            'ip_block' => 1,
            'phone_block' => 0,
            'account_block' => 0,
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2030-12-31 23:59:59',
        ]);
        $activity->setRelation('prizes', collect());

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('confirmBlockRecord')
            ->once()
            ->with(10, Mockery::subset(['long_ip' => ip2long('0.0.0.0')]))
            ->andReturn(true);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('IP already checked in');

        $service->checkin($member);
    }

    public function test_checkin_throws_when_phone_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0912000333',
        ]);

        $activity = new CheckinActivity;
        $activity->forceFill([
            'id' => 11,
            'flow_limit_multiple' => 0,
            'ip_block' => 0,
            'phone_block' => 1,
            'account_block' => 0,
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2030-12-31 23:59:59',
        ]);
        $activity->setRelation('prizes', collect());

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('confirmBlockRecord')
            ->once()
            ->with(11, ['mobile' => '0912000333'])
            ->andReturn(true);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Phone already checked in');

        $service->checkin($member);
    }

    public function test_checkin_throws_when_account_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'first_name' => 'BlockMe',
        ]);

        $activity = new CheckinActivity;
        $activity->forceFill([
            'id' => 12,
            'flow_limit_multiple' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 1,
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2030-12-31 23:59:59',
        ]);
        $activity->setRelation('prizes', collect());

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('confirmBlockRecord')
            ->once()
            ->with(12, ['first_name' => 'BlockMe'])
            ->andReturn(true);

        $service = $this->bindService($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Account already checked in');

        $service->checkin($member);
    }

    public function test_checkin_returns_empty_array_when_no_prize_for_streak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->makeActivityWithPrizes(1, [
            ['day' => 1, 'prize_type' => 1, 'prize_amount' => '1'],
        ]);

        $checkinRecord = (object) ['id' => 9001];

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('checkin')->once()->with(1, $member->id)->andReturn($checkinRecord);
        $mock->shouldReceive('getCheckinDates')->once()->with(1, $member->id)->andReturn(['2026-04-14']);
        $mock->shouldReceive('getCheckinPrize')->once()->with(1, 1)->andReturn(collect());

        $service = $this->bindService($mock);

        $result = $service->checkin($member);

        $this->assertSame([], $result);
    }

    public function test_checkin_builds_prize_payload_for_prize_type_three(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        $activity = $this->makeActivityWithPrizes(1, [
            ['day' => 1, 'prize_type' => 1, 'prize_amount' => '1'],
        ]);

        $checkinRecord = (object) ['id' => 9002];
        $prize = new CheckinPrize;
        $prize->forceFill([
            'id' => 501,
            'checkin_activity_id' => 1,
            'day' => 1,
            'prize_type' => 3,
            'prize_amount' => '5.0000',
        ]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('checkin')->once()->with(1, $member->id)->andReturn($checkinRecord);
        $mock->shouldReceive('getCheckinDates')->once()->with(1, $member->id)->andReturn(['2026-04-14']);
        $mock->shouldReceive('getCheckinPrize')->once()->with(1, 1)->andReturn(collect([$prize]));
        $mock->shouldReceive('createCheckinPrizeRecord')->once()->with(9002, 501)->andReturn((object) ['id' => 7001]);

        $service = $this->bindService($mock);

        $result = $service->checkin($member);

        $this->assertCount(1, $result);
        $this->assertSame(__('param.checkin.prize_type.3'), $result[0]['prize_type']);
        $this->assertSame('5.0000', $result[0]['prize_amount']);
    }

    /**
     * 禮碼獎項的發放要落到 member_wallets，而且走 WalletService 的單一入口。
     *
     * 收斂前這條路徑是「先查、查不到才 MemberWallet::create()」，兩步之間有競態；
     * 收斂後由 getOrCreateWallet() 的 createOrFirst 保證同一個 (會員, 錢包類型) 只會有一筆。
     */
    public function test_checkin_rolling_chip_prize_writes_wallet_through_wallet_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00', 'UTC'));

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        // Arrange：該會員尚未有禮碼錢包
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
        ]);

        $activity = $this->makeActivityWithPrizes(1, [
            ['day' => 1, 'prize_type' => 2, 'prize_amount' => '5'],
        ]);

        $prize = new CheckinPrize;
        $prize->forceFill([
            'id' => 502,
            'checkin_activity_id' => 1,
            'day' => 1,
            'prize_type' => 2,
            'prize_amount' => '5.0000',
        ]);

        $mock = Mockery::mock(CheckinRepository::class);
        $mock->shouldReceive('getCheckinActivityByAgentId')->once()->andReturn($activity);
        $mock->shouldReceive('hasCheckedInToday')->once()->with($member->id)->andReturn(false);
        $mock->shouldReceive('checkin')->once()->with(1, $member->id)->andReturn((object) ['id' => 9003]);
        $mock->shouldReceive('getCheckinDates')->once()->with(1, $member->id)->andReturn(['2026-04-14']);
        $mock->shouldReceive('getCheckinPrize')->once()->with(1, 1)->andReturn(collect([$prize]));
        $mock->shouldReceive('createCheckinPrizeRecord')->once()->with(9003, 502)->andReturn((object) ['id' => 7002]);
        $mock->shouldReceive('createRollingChipLog')->once();

        // Act
        $this->bindService($mock)->checkin($member);

        // Assert：錢包被建出來、餘額等於獎金，且只有一筆
        $wallet = MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->get();

        $this->assertCount(1, $wallet, '同一個會員的禮碼錢包只能有一筆');
        $this->assertEquals(5, $wallet->first()->balance);
    }
}
