<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Schedule;

use App\Console\Commands\Schedule\VendorDetect;
use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Param;
use App\Models\Vendor;
use App\Models\VendorMaintenance;
use App\Models\VendorRequestFail;
use App\Services\BotNotifyService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * VendorDetect 排程指令測試
 *
 * php artisan test tests/Unit/Console/Commands/Schedule/VendorDetectTest.php --compact
 */
class VendorDetectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'vd-' . uniqid('', true) . '.local';
        Carbon::setTestNow('2026-06-11 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[TestDox('沒有待檢測遊戲商時正常結束')]
    public function test_returns_success_when_no_pending_vendors(): void
    {
        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->expectsOutputToContain('沒有待檢測的遊戲商')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('略過單錢包遊戲商的 vendor_request_fails')]
    public function test_skips_single_wallet_vendors(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createTransferWalletVendor();
        $singleWalletVendor = Vendor::factory()->create([
            'code' => 'vd_sw_' . uniqid('', true),
            'is_single_wallet' => 1,
        ]);

        VendorRequestFail::create([
            'vendor_id' => $singleWalletVendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => 0,
            'status' => 0,
        ]);

        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->expectsOutputToContain('沒有待檢測的遊戲商')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('略過超過一週的 vendor_request_fails')]
    public function test_skips_vendor_request_fails_older_than_one_week(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createTransferWalletVendor();
        $member = $this->createMember($agent, ['username' => 'vd_old_' . uniqid('', true)]);

        $fail = VendorRequestFail::create([
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => 0,
        ]);
        $fail->forceFill([
            'created_at' => now()->subWeek()->subDay(),
            'updated_at' => now()->subWeek()->subDay(),
        ])->saveQuietly();

        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->expectsOutputToContain('沒有待檢測的遊戲商')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('member_id 無效時略過該遊戲商')]
    public function test_skips_vendor_when_member_id_is_invalid(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createTransferWalletVendor();

        VendorRequestFail::create([
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => 0,
            'status' => 0,
        ]);

        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->expectsOutputToContain("vendor {$vendor->id} 找不到可用的 member (member_id=0)")
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 0,
        ]);
    }

    #[TestDox('餘額查詢成功且先前已通知失敗時，標記恢復並發送通知')]
    public function test_marks_recovered_and_sends_notification_when_balance_success_and_previous_status_is_one(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 1);

        $maintenance = VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subMinutes(10)->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->mockVendorServiceBalanceSuccess();
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '已恢復轉點')
                && str_contains($message, $agent->username)
                && str_contains($message, $vendor->name)));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 2,
        ]);
        $this->assertSame(0, $maintenance->fresh()->status);
    }

    #[TestDox('餘額查詢成功且先前未通知失敗時，只標記恢復不發通知')]
    public function test_marks_recovered_without_notification_when_balance_success_and_previous_status_is_zero(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 2,
        ]);
    }

    #[TestDox('首次餘額查詢失敗時，新增維護、升級 status 並發送通知')]
    public function test_creates_maintenance_and_sends_notification_on_first_balance_failure(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        $this->mockVendorServiceBalanceFailure();
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '無法取得餘額')
                && str_contains($message, $agent->username)
                && str_contains($message, $vendor->name)));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'type' => 1,
            'status' => 1,
        ]);
    }

    #[TestDox('餘額持續失敗且 status 已為 1 時，延長維護但不發送通知')]
    public function test_extends_maintenance_without_notification_when_balance_failure_previous_status_is_one(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 1);

        $maintenance = VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subMinutes(50)->toDateTimeString(),
            'end_time' => now()->addMinutes(10)->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->mockVendorServiceBalanceFailure();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 1,
        ]);
        $this->assertSame(
            now()->addHour()->toDateTimeString(),
            $maintenance->fresh()->end_time,
        );
        $this->assertSame('自動維護排程延長維護時間', $maintenance->fresh()->memo);
    }

    #[TestDox('餘額持續失敗且維護已過期時，重建維護並發送通知')]
    public function test_creates_maintenance_and_sends_notification_when_balance_failure_and_maintenance_expired(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 1);

        VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subHours(2)->toDateTimeString(),
            'end_time' => now()->subHour()->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->mockVendorServiceBalanceFailure();
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '無法取得餘額')
                && str_contains($message, $agent->username)
                && str_contains($message, $vendor->name)));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'type' => 1,
            'status' => 1,
            'end_time' => now()->addHour()->toDateTimeString(),
            'memo' => '自動維護排程建立維護',
        ]);
    }

    #[TestDox('後台手動維護（admin 非 0）餘額恢復時不被排程取消')]
    public function test_keeps_manual_maintenance_when_balance_success(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 1);

        $manualMaintenance = VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subMinutes(10)->toDateTimeString(),
            'end_time' => now()->addDay()->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'memo' => '客服手動掛維護',
            'admin' => 88097,
        ]);

        $this->mockVendorServiceBalanceSuccess();
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '已恢復轉點')
                && str_contains($message, '後台手動維護仍生效')));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $fresh = $manualMaintenance->fresh();
        $this->assertSame(1, $fresh->status);
        $this->assertSame('客服手動掛維護', $fresh->memo);
        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 2,
        ]);
    }

    #[TestDox('後台手動維護生效期間餘額失敗時，不延長也不新建自動維護')]
    public function test_does_not_touch_or_duplicate_maintenance_when_manual_maintenance_active(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        $manualMaintenance = VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subMinutes(10)->toDateTimeString(),
            'end_time' => now()->addMinutes(10)->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'memo' => '客服手動掛維護',
            'admin' => 88097,
        ]);

        $this->mockVendorServiceBalanceFailure();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $fresh = $manualMaintenance->fresh();
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $fresh->end_time);
        $this->assertSame('客服手動掛維護', $fresh->memo);
        $this->assertSame(1, VendorMaintenance::query()->where('vendor_id', $vendor->id)->count());
    }

    #[TestDox('直接使用 vendor_request_fails.member_id 作為探測帳號')]
    public function test_uses_member_id_from_vendor_request_fail(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        $this->mockVendorServiceBalanceSuccess();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => 2,
        ]);
    }

    #[TestDox('已有有效維護時，餘額失敗只延長維護不重複發送通知')]
    public function test_extends_existing_maintenance_without_notification_when_balance_failure(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        VendorRequestFail::create([
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => 1,
        ]);

        VendorMaintenance::create([
            'vendor_id' => $vendor->id,
            'type' => 1,
            'start_time' => now()->subMinutes(10)->toDateTimeString(),
            'end_time' => now()->addMinutes(10)->toDateTimeString(),
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->mockVendorServiceBalanceFailure();
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('member 探測失敗但總代探針探測成功時，標記恢復且不掛維護')]
    public function test_marks_recovered_when_member_probe_fails_but_general_agent_probe_succeeds(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);
        $probeMember = $this->createProbeMember($agent);

        // member 探測失敗、探針 member 探測成功
        $this->mockVendorServiceBalancePerMember([
            $member->id => false,
            $probeMember->id => true,
        ]);
        $this->mockBotNotifyNeverSent();

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 2,
        ]);
        $this->assertDatabaseMissing('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'status' => 1,
        ]);
    }

    #[TestDox('member 探測失敗且總代探針也失敗時，照舊掛維護')]
    public function test_creates_maintenance_when_member_probe_and_general_agent_probe_both_fail(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);
        $probeMember = $this->createProbeMember($agent);

        // member 與探針 member 探測皆失敗
        $this->mockVendorServiceBalancePerMember([
            $member->id => false,
            $probeMember->id => false,
        ]);
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '無法取得餘額')));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'type' => 1,
            'status' => 1,
        ]);
    }

    #[TestDox('member 探測失敗且該總代無探針帳號設定時，照舊掛維護')]
    public function test_creates_maintenance_when_member_probe_fails_and_no_probe_configured(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        // 無任何 vendor_detect 探針 param，member 探測失敗
        $this->mockVendorServiceBalancePerMember([
            $member->id => false,
        ]);
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '無法取得餘額')));

        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'type' => 1,
            'status' => 1,
        ]);
    }

    #[TestDox('探針會員 hierarchy 無總代導致 general_agent_id 解析為 null 時，排程不 FAILURE 且該 vendor 仍走掛維護路徑')]
    public function test_does_not_fail_when_probe_member_general_agent_id_resolve_returns_null(): void
    {
        [$agent, $member, $vendor] = $this->seedPendingVendor(status: 0);

        // 探針會員掛在「上層 hierarchy 無 GENERAL_AGENT」的代理下，
        // 其 agent->general_agent_id accessor 對 null 取屬性回傳 null（PHP 8.4 為 E_WARNING，非 Throwable）
        $this->createBrokenProbeMember();

        // member 自身探測失敗；唯一的探針因 general_agent_id 解析為 null 被安全略過 → 無有效探針
        $this->mockVendorServiceBalancePerMember([
            $member->id => false,
        ]);
        $botNotify = $this->mockBotNotify();
        $botNotify->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (string $message): bool => str_contains($message, '無法取得餘額')));

        // 解析結果為 null 不應冒泡到 handle() 的 catch(Throwable) 而使整輪 FAILURE
        $this->artisan(VendorDetect::class)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('vendor_maintenances', [
            'vendor_id' => $vendor->id,
            'agent_id' => $agent->id,
            'type' => 1,
            'status' => 1,
        ]);
    }

    /**
     * 建立一個「上層 hierarchy 無 GENERAL_AGENT」的探針 Member。
     *
     * 其代理為 AGENT 角色且 hierarchy_path 僅含自身，故 Agent::generalAgentId() accessor
     * 查不到總代、對 null 取屬性回傳 null，用以驗證解析失敗的安全略過路徑。
     */
    private function createBrokenProbeMember(): Member
    {
        $unique = bin2hex(random_bytes(8));

        $brokenAgent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'role_id' => RoleEnum::AGENT->value,
        ]);
        $brokenAgent->hierarchy_path = "/{$brokenAgent->id}";
        $brokenAgent->hierarchy = "[-1,{$brokenAgent->id},-2]";
        $brokenAgent->save();

        $probeMember = $this->createMember($brokenAgent, ['username' => 'vd_brk_' . uniqid('', true)]);

        Param::create([
            'type' => 'vendor_detect',
            'parameter' => 'probe_' . uniqid('', true),
            'value' => 0,
            'info' => json_encode(['account' => $probeMember->username]),
            'sort' => 0,
            'status' => 1,
            'remark' => 'vendor_detect broken probe',
            'admin' => 0,
        ]);

        return $probeMember;
    }

    /**
     * 建立並登記某總代的 vendor_detect 探針 Member（example.invalid.account = 探針 username）。
     */
    private function createProbeMember(Agent $agent): Member
    {
        $probeMember = $this->createMember($agent, ['username' => 'vd_probe_' . uniqid('', true)]);

        Param::create([
            'type' => 'vendor_detect',
            'parameter' => 'probe_' . uniqid('', true),
            'value' => 0,
            'info' => json_encode(['account' => $probeMember->username]),
            'sort' => 0,
            'status' => 1,
            'remark' => 'vendor_detect probe',
            'admin' => 0,
        ]);

        return $probeMember;
    }

    /**
     * @return array{0: Agent, 1: Member, 2: Vendor}
     */
    private function seedPendingVendor(int $status): array
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createTransferWalletVendor();
        $member = $this->createMember($agent, ['username' => 'vd_' . uniqid('', true)]);

        VendorRequestFail::create([
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => $status,
        ]);

        return [$agent, $member, $vendor];
    }

    private function createTransferWalletVendor(): Vendor
    {
        return Vendor::factory()->create([
            'code' => 'vd_tw_' . str_replace('.', '_', uniqid('', true)),
            'is_single_wallet' => 0,
        ]);
    }

    private function mockVendorServiceBalanceSuccess(): void
    {
        $driver = Mockery::mock(AbstractTransferWallet::class);
        $driver->allows('getBalance')->andReturn('100.0000');

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->allows('getDriver')
            ->with(Mockery::type('int'), Mockery::type(Member::class))
            ->andReturn($driver);

        $this->instance(VendorService::class, $vendorService);
    }

    private function mockVendorServiceBalanceFailure(): void
    {
        $driver = Mockery::mock(AbstractTransferWallet::class);
        $driver->allows('getBalance')->andThrow(new \RuntimeException('balance query failed'));

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->allows('getDriver')
            ->with(Mockery::type('int'), Mockery::type(Member::class))
            ->andReturn($driver);

        $this->instance(VendorService::class, $vendorService);
    }

    /**
     * 依傳入的 member 決定 getBalance() 成功或拋例外，用於驗證兩段式探測。
     *
     * @param  array<int, bool>  $resultsByMemberId  member_id => 是否成功取得餘額
     */
    private function mockVendorServiceBalancePerMember(array $resultsByMemberId): void
    {
        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->allows('getDriver')
            ->with(Mockery::type('int'), Mockery::type(Member::class))
            ->andReturnUsing(function (int $vendorId, Member $member) use ($resultsByMemberId): AbstractTransferWallet {
                $shouldSucceed = $resultsByMemberId[$member->id] ?? false;

                $driver = Mockery::mock(AbstractTransferWallet::class);

                if ($shouldSucceed) {
                    $driver->allows('getBalance')->andReturn('100.0000');
                } else {
                    $driver->allows('getBalance')->andThrow(new \RuntimeException('balance query failed'));
                }

                return $driver;
            });

        $this->instance(VendorService::class, $vendorService);
    }

    private function mockBotNotify(): MockInterface
    {
        $mock = Mockery::mock(BotNotifyService::class);
        $mock->allows('channel')->andReturnSelf();
        $this->instance(BotNotifyService::class, $mock);

        return $mock;
    }

    private function mockBotNotifyNeverSent(): void
    {
        $mock = $this->mockBotNotify();
        $mock->shouldNotReceive('send');
    }
}
