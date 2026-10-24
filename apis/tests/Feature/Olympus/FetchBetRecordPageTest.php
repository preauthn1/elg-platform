<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Operations\FetchBetRecord;
use App\Filament\Admin\Pages\Operations\SummaryBetRecord;
use App\Jobs\RunAdminOperationJob;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInfo;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 後台「拉取注單」操作頁測試。
 *
 * php artisan test tests/Feature/Olympus/FetchBetRecordPageTest.php
 */
class FetchBetRecordPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // 表單的日期範圍是 now() 的相對區間（minDate = now-30 天、maxDate = now），
        // 測試又以固定日期斷言換算結果：時間不凍結，寫死的日期總有一天滑出區間
        $this->travelTo('2026-08-21 12:00:00');

        if (!Vendor::find(VendorIdEnum::TITAN_EVO->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::TITAN_EVO->value, 'name' => 'TITAN EVO']);
        }
        // Titan EVO 是轉帳錢包：factory 預設 is_single_wallet = 1，會被乾跑鎖定誤判
        Vendor::where('id', VendorIdEnum::TITAN_EVO->value)->update(['is_single_wallet' => 0]);

        $this->actingAs($this->makeUser(UserRoleEnum::ADMIN), 'admin');
    }

    private function makeUser(UserRoleEnum $role): User
    {
        return User::create([
            'parent' => 0,
            'username' => 'role_' . strtolower($role->name) . '_' . uniqid(),
            'email' => 'role_' . uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => $role->value,
            'admin' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    private function executeAndGetParams(array $formData): array
    {
        Livewire::test(FetchBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::TITAN_EVO->value,
                ...$formData,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        return AdminOperationLog::latest('id')->first()->params;
    }

    #[Group('rules/bet-record-pull#R-28')]
    #[Group('rules/bet-record-pull#R-29')]
    public function test_execute_with_hour_converts_taipei_to_utc(): void
    {
        // 預設時區 UTC+8，0 點是合法值不可被 empty() 判斷吃掉
        $params = $this->executeAndGetParams([
            'timezone' => '+08:00',
            'date' => '2026-08-20',
            'hour' => 0,
        ]);

        // 台北 08-20 00:00 ～ 01:00 = UTC 08-19 16:00 ～ 17:00
        // MySQL JSON 欄位不保證鍵順序，比對值即可
        $this->assertEquals([
            '--vendor' => (string) VendorIdEnum::TITAN_EVO->value,
            '--start' => '2026-08-19@16',
            '--end' => '2026-08-19@17',
            '--direct' => true,
            // 「使用三方回應快取」預設開啟
            '--use-cache' => true,
        ], $params);

        $log = AdminOperationLog::latest('id')->first();
        $this->assertSame('fetch_bet_record', $log->operation);
        // 長時操作必須走 long-running 連線：預設 redis 線 retry_after=90，
        // 整天補拉跑超過 90 秒會被重派誤判 MaxAttemptsExceeded
        Queue::assertPushed(RunAdminOperationJob::class, fn (RunAdminOperationJob $job): bool => $job->connection === 'redis-long-running');
    }

    #[TestDox('單一錢包遊戲商強制乾跑：表單開關被繞過也只送 --dry-run')]
    #[Group('rules/bet-record-pull#R-32')]
    public function test_single_wallet_vendor_is_forced_to_dry_run(): void
    {
        if (!Vendor::find(VendorIdEnum::CLOTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::CLOTSW->value, 'name' => 'CLOT PLAY SW']);
        }
        Vendor::where('id', VendorIdEnum::CLOTSW->value)->update(['is_single_wallet' => 1]);

        $params = $this->executeAndGetParams([
            'vendor_id' => VendorIdEnum::CLOTSW->value,
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'hour' => 0,
            'dry_run' => false,
        ]);

        $this->assertArrayHasKey('--dry-run', $params);
        $this->assertArrayNotHasKey('--direct', $params);
    }

    #[Group('rules/bet-record-pull#R-28')]
    public function test_utc_timezone_passes_hour_through(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'hour' => 23,
        ]);

        $this->assertSame('2026-08-20@23', $params['--start']);
        $this->assertSame('2026-08-21@00', $params['--end']);
    }

    /** Titan 系列同時在兩頁的清單內（config/summary_bet_record.php 已納入），遊戲商也一併帶過去 */
    #[Group('rules/bet-record-pull#R-35')]
    public function test_continue_action_carries_time_params_to_summary_page(): void
    {
        Livewire::test(FetchBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::TITAN_EVO->value,
                'timezone' => 'UTC',
                'date' => '2026-08-20',
                'hour' => 0,
            ])
            ->callAction('continueToSummary')
            ->assertRedirect(SummaryBetRecord::getUrl([
                'timezone' => 'UTC',
                'date' => '2026-08-20',
                'hour' => 0,
                'vendor_id' => VendorIdEnum::TITAN_EVO->value,
            ]));
    }

    #[Group('rules/bet-record-pull#R-28')]
    public function test_empty_hour_pulls_whole_day_in_selected_timezone(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
        ]);

        $this->assertSame('2026-08-20@00', $params['--start']);
        $this->assertSame('2026-08-21@00', $params['--end']);
    }

    #[TestDox('勾選「更新既有列」時帶 --update')]
    #[Group('rules/bet-record-pull#R-29')]
    public function test_update_toggle_passes_update_flag(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'update' => true,
        ]);

        $this->assertTrue($params['--update']);
    }

    #[TestDox('指定商戶時帶 --vendor-info')]
    #[Group('rules/bet-record-pull#R-29')]
    public function test_vendor_info_passes_flag(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::TITAN_EVO->value,
            'status' => 1,
        ]);

        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'vendor_info_id' => $vendorInfo->id,
        ]);

        $this->assertSame((string) $vendorInfo->id, $params['--vendor-info']);
    }

    #[TestDox('乾跑模式以 --dry-run 取代 --direct')]
    #[Group('rules/bet-record-pull#R-29')]
    public function test_dry_run_toggle_replaces_direct_with_dry_run(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'dry_run' => true,
        ]);

        $this->assertTrue($params['--dry-run']);
        $this->assertArrayNotHasKey('--direct', $params);
    }

    #[TestDox('關閉「使用三方回應快取」時不帶 --use-cache')]
    #[Group('rules/bet-record-pull#R-29')]
    public function test_use_cache_toggle_off_omits_the_flag(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'use_cache' => false,
        ]);

        $this->assertArrayNotHasKey('--use-cache', $params);
    }

    #[TestDox('選擇有注單彙總設定的遊戲商時自動帶其對帳時區 reconcile_timezone')]
    #[Group('rules/bet-record-pull#R-27')]
    public function test_choosing_a_vendor_prefills_its_reconcile_timezone(): void
    {
        Config::set('summary_bet_record.' . VendorIdEnum::TITAN_EVO->value . '.reconcile_timezone', 0);

        $component = Livewire::test(FetchBetRecord::class)
            ->set('data.vendor_id', VendorIdEnum::TITAN_EVO->value);

        $this->assertSame('UTC', $component->get('data')['timezone']);
    }

    #[TestDox('不在注單彙總設定的遊戲商沿用 calc_time_zone 為時區')]
    #[Group('rules/bet-record-pull#R-27')]
    public function test_vendor_outside_summary_config_prefills_its_calc_time_zone(): void
    {
        Config::set('summary_bet_record', []);
        Vendor::find(VendorIdEnum::TITAN_EVO->value)->forceFill(['calc_time_zone' => -4])->save();

        $component = Livewire::test(FetchBetRecord::class)
            ->set('data.vendor_id', VendorIdEnum::TITAN_EVO->value);

        $this->assertSame('-04:00', $component->get('data')['timezone']);
    }

    /** 運維只能回溯 MAX_DAYS_AGO（30）天，32 天前的日期被表單驗證擋下 */
    #[Group('rules/bet-record-pull#R-30')]
    public function test_ops_date_older_than_30_days_is_rejected(): void
    {
        $this->actingAs($this->makeUser(UserRoleEnum::OPS), 'admin');

        Livewire::test(FetchBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::TITAN_EVO->value,
                'timezone' => 'UTC',
                'date' => '2026-07-20',
            ])
            ->call('execute')
            ->assertHasFormErrors(['date']);

        Queue::assertNothingPushed();
    }

    /** 管理員可回溯 ADMIN_MAX_DAYS_AGO（60）天，32 天前的日期照常派工 */
    #[Group('rules/bet-record-pull#R-30')]
    public function test_admin_can_submit_date_within_60_days(): void
    {
        $params = $this->executeAndGetParams([
            'timezone' => 'UTC',
            'date' => '2026-07-20',
        ]);

        $this->assertSame('2026-07-20@00', $params['--start']);
        Queue::assertPushed(RunAdminOperationJob::class);
    }

    /** 管理員超過 ADMIN_MAX_DAYS_AGO（60）天同樣被擋：62 天前的日期被表單驗證擋下 */
    #[Group('rules/bet-record-pull#R-30')]
    public function test_admin_date_older_than_60_days_is_rejected(): void
    {
        Livewire::test(FetchBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::TITAN_EVO->value,
                'timezone' => 'UTC',
                'date' => '2026-06-20',
            ])
            ->call('execute')
            ->assertHasFormErrors(['date']);

        Queue::assertNothingPushed();
    }
}
