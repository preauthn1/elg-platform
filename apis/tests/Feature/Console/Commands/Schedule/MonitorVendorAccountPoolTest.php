<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Schedule;

use App\Facades\BotNotify;
use App\Services\BotNotifyService;
use App\Services\VendorAccountPoolService;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 監測命令的門檻過濾、訊息組裝與通知行為。
 *
 * 偵測邏輯（表尾連續計數、同表多商戶取樣）在 VendorAccountPoolServiceTest，這裡整段 mock 掉，
 * 只驗命令自身。單一商戶與多商戶是兩條不同的組字串路徑，兩條都要測——2026-10-07 單一商戶那條
 * 曾因 sprintf 參數數量不符而在正式資料上炸掉，dev 全是多商戶所以沒驗出來。
 */
class MonitorVendorAccountPoolTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $stuck
     */
    private function mockDetect(array $stuck): void
    {
        $this->mock(VendorAccountPoolService::class, function (MockInterface $mock) use ($stuck): void {
            $mock->shouldReceive('detectStuckPools')->andReturn($stuck); // @phpstan-ignore method.notFound
        });
    }

    /**
     * 換掉 BotNotify facade；channel() 是流暢介面必須回自己，否則後面的 send() 打在 null 上
     */
    private function swapNotify(): MockInterface
    {
        $notify = Mockery::mock(BotNotifyService::class);
        $notify->shouldReceive('channel')->andReturnSelf();
        BotNotify::swap($notify);

        return $notify;
    }

    /**
     * 偽裝成正式環境：命令只在 production 送通知（dev 壞掉的商戶一大把，發了是噪音）
     */
    private function asProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    /**
     * @return array<string, mixed>
     */
    private function merchant(int $consecutive = 13, int $vendorInfoId = 185, string $vendorName = 'NextSpin'): array
    {
        return [
            'vendor_id' => 48,
            'vendor_name' => $vendorName,
            'vendor_info_id' => $vendorInfoId,
            'vendor_info_name' => 'NextSpin-casion365',
            'consecutive' => $consecutive,
            'latest_username' => 'cas01130361',
            'latest_created_at' => '2026-10-07 03:53:09',
            'unbound' => 9,
            'target' => 50,
        ];
    }

    #[Group('op3463')]
    #[TestDox('無異常：不發通知')]
    #[Group('rules/vendor-account-pool#R-23')]
    public function test_no_alert_when_nothing_is_stuck(): void
    {
        $this->mockDetect([]);
        $this->swapNotify()->shouldNotReceive('send');

        $this->artisan('vendor:monitor-account-pool')
            ->expectsOutputToContain('帳號池監測：無異常')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Group('op3463')]
    #[TestDox('連續筆數未達門檻：不發通知')]
    #[Group('rules/vendor-account-pool#R-23')]
    public function test_below_threshold_does_not_alert(): void
    {
        $this->mockDetect([$this->merchant(consecutive: 9)]);
        $this->swapNotify()->shouldNotReceive('send');

        $this->artisan('vendor:monitor-account-pool', ['--consecutive' => 10])
            ->expectsOutputToContain('帳號池監測：無異常')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Group('op3463')]
    #[TestDox('單一商戶：訊息含商戶、連續筆數、池水位與處置提示')]
    #[Group('rules/vendor-account-pool#R-24')]
    public function test_single_merchant_message(): void
    {
        $this->mockDetect([$this->merchant()]);

        $this->asProduction();
        $sent = '';
        $this->swapNotify()->shouldReceive('send')->once()->with(Mockery::capture($sent));

        $this->artisan('vendor:monitor-account-pool')->assertExitCode(Command::SUCCESS);

        $this->assertStringContainsString('【帳號池異常】NextSpin (48) / NextSpin-casion365 (info 185)', $sent);
        $this->assertStringContainsString('連續 13 筆建立未完成，最新 cas01130361 @ 2026-10-07 03:53:09', $sent);
        $this->assertStringContainsString('池水位 9／50', $sent);
        $this->assertStringContainsString('停用 vendor_infos 185', $sent);
    }

    #[Group('op3463')]
    #[TestDox('連續筆數達取樣上限時標示 ≥，避免被讀成剛好那麼多筆')]
    #[Group('rules/vendor-account-pool#R-24')]
    public function test_consecutive_at_sample_cap_is_marked(): void
    {
        $this->mockDetect([$this->merchant(consecutive: 20)]);

        $this->asProduction();
        $sent = '';
        $this->swapNotify()->shouldReceive('send')->once()->with(Mockery::capture($sent));

        $this->artisan('vendor:monitor-account-pool', ['--sample' => 20])->assertExitCode(Command::SUCCESS);

        $this->assertStringContainsString('連續 ≥20 筆建立未完成', $sent);
    }

    #[Group('op3463')]
    #[TestDox('多商戶合併一則，超過上限只列前幾個並標明其餘筆數')]
    #[Group('rules/vendor-account-pool#R-25')]
    public function test_multiple_merchants_are_capped_with_a_tail_notice(): void
    {
        $stuck = [];

        for ($i = 1; $i <= 11; $i++) {
            $stuck[] = $this->merchant(vendorInfoId: 100 + $i, vendorName: 'Vendor' . $i);
        }

        $this->mockDetect($stuck);

        $this->asProduction();
        $sent = '';
        $this->swapNotify()->shouldReceive('send')->once()->with(Mockery::capture($sent));

        $this->artisan('vendor:monitor-account-pool')->assertExitCode(Command::SUCCESS);

        $this->assertStringContainsString('【帳號池異常】11 個商戶', $sent);
        $this->assertStringContainsString('Vendor8', $sent);
        $this->assertStringNotContainsString('Vendor9', $sent, '超過上限的商戶不列入訊息');
        $this->assertStringContainsString('…另有 3 個商戶，完整清單見 log', $sent);
        // BotNotifyService 對整則訊息 Str::limit(1000)，超過會被靜默截斷
        $this->assertLessThan(900, mb_strlen($sent), '訊息需留餘裕給站名前綴');
    }

    #[Group('op3463')]
    #[TestDox('非正式環境即使命中也不發通知：dev 的三方多半打不通，壞掉的商戶一大把')]
    #[Group('rules/vendor-account-pool#R-26')]
    public function test_non_production_does_not_send(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'dev');
        $this->mockDetect([$this->merchant()]);
        $this->swapNotify()->shouldNotReceive('send');

        $this->artisan('vendor:monitor-account-pool')
            ->expectsOutputToContain('非正式環境 dev：未發送通知')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Group('op3463')]
    #[TestDox('--dry-run：印出結果但不發通知')]
    #[Group('rules/vendor-account-pool#R-26')]
    public function test_dry_run_does_not_send(): void
    {
        $this->mockDetect([$this->merchant()]);
        $this->swapNotify()->shouldNotReceive('send');

        $this->artisan('vendor:monitor-account-pool', ['--dry-run' => true])
            ->expectsOutputToContain('【帳號池異常】')
            ->assertExitCode(Command::SUCCESS);
    }
}
