<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Operations\SummaryBetRecord;
use App\Filament\Admin\Pages\Queries\BetRecordCorrection;
use App\Jobs\RunAdminOperationJob;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Olympus 後台「重跑注單彙總」操作頁測試。
 *
 * php artisan test tests/Feature/Olympus/SummaryBetRecordPageTest.php
 */
class SummaryBetRecordPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // 日期欄位下限是 now() 的相對區間（now-30 天），測試又以固定日期斷言：
        // 時間不凍結的話，寫死的日期總有一天滑出區間
        $this->travelTo('2026-08-21 12:00:00');

        if (!Vendor::find(VendorIdEnum::PTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::PTSW->value, 'name' => 'PTSW']);
        }

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

    #[Group('rules/summary-bet-record#R-17')]
    public function test_execute_dispatches_command_with_vendor_and_date(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-08-20',
                'dry_run' => true,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $log = AdminOperationLog::latest('id')->first();

        $this->assertSame('summary_bet_record', $log->operation);
        // MySQL JSON 欄位不保證鍵順序，比對值即可
        $this->assertEquals([
            '--vendor' => (string) VendorIdEnum::PTSW->value,
            '--date' => '2026-08-20',
            '--timezone' => '+08:00',
            '--dry-run' => true,
        ], $log->params);

        Queue::assertPushed(RunAdminOperationJob::class);
    }

    #[Group('rules/summary-bet-record#R-17')]
    public function test_execute_with_hour_reruns_single_hour(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-08-20',
                // 0 點是合法值，不可被 empty() 判斷吃掉
                'hour' => 0,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertEquals([
            '--vendor' => (string) VendorIdEnum::PTSW->value,
            '--date' => '2026-08-20',
            '--timezone' => '+08:00',
            '--hour' => '0',
        ], AdminOperationLog::latest('id')->first()->params);
    }

    #[Group('rules/summary-bet-record#R-17')]
    public function test_selected_timezone_is_passed_through_to_the_command(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => 'UTC',
                'date' => '2026-08-20',
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        // 整天：不帶 --hour，日界由指令依 --timezone 決定
        $this->assertEquals([
            '--vendor' => (string) VendorIdEnum::PTSW->value,
            '--date' => '2026-08-20',
            '--timezone' => 'UTC',
        ], AdminOperationLog::latest('id')->first()->params);
    }

    public function test_mount_prefills_form_from_query_string(): void
    {
        $component = Livewire::withQueryParams([
            'timezone' => 'UTC',
            'date' => '2026-08-20',
            'hour' => '0',
        ])->test(SummaryBetRecord::class);

        $data = $component->get('data');

        $this->assertSame('UTC', $data['timezone']);
        // 非原生 picker 的 Livewire 狀態固定是 Y-m-d H:i:s，送出時才依 format 取回 Y-m-d
        $this->assertStringStartsWith('2026-08-20', $data['date']);
        // 0 點是合法值，不可被空值判斷吃掉
        $this->assertSame('0', $data['hour']);
    }

    #[Group('rules/summary-bet-record#R-17')]
    public function test_dry_run_off_omits_the_flag(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-08-20',
                'dry_run' => false,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $params = AdminOperationLog::latest('id')->first()->params;

        $this->assertArrayNotHasKey('--dry-run', $params);
        // 未選小時即整天，不帶 --hour
        $this->assertArrayNotHasKey('--hour', $params);
    }

    #[Group('rules/summary-bet-record#R-17')]
    public function test_未支援的遊戲商不在選項中(): void
    {
        // SL（Siam Lotto）不在 config/summary_bet_record.php，選了應被表單擋下
        if (!Vendor::find(VendorIdEnum::SL->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::SL->value, 'name' => 'SL']);
        }

        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::SL->value,
                'timezone' => '+08:00',
                'date' => '2026-08-20',
            ])
            ->call('execute')
            ->assertHasFormErrors(['vendor_id']);

        Queue::assertNothingPushed();
    }

    /** 遊戲商只在補帳查詢頁也列出（有 driver）時才帶；時區換成時差小時數；小時不帶 */
    public function test_continue_action_carries_params_to_correction_page(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => 'UTC',
                'date' => '2026-08-20',
                'hour' => 3,
            ])
            ->callAction('continueToCorrection')
            ->assertRedirect(BetRecordCorrection::getUrl([
                'timezone' => 0,
                'date' => '2026-08-20',
                'vendor_id' => VendorIdEnum::PTSW->value,
            ]));
    }

    public function test_submitted_options_are_remembered_on_next_visit(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => 'UTC',
                'date' => '2026-08-20',
                'hour' => 5,
                'dry_run' => true,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        // 同一 session 再進頁面：沿用上次送出的選項
        $data = Livewire::test(SummaryBetRecord::class)->get('data');
        $this->assertSame(VendorIdEnum::PTSW->value, (int) $data['vendor_id']);
        $this->assertSame('UTC', $data['timezone']);
        $this->assertStringStartsWith('2026-08-20', $data['date']);
        $this->assertSame(5, (int) $data['hour']);
    }

    #[Group('rules/summary-bet-record#R-18')]
    public function test_choosing_a_vendor_prefills_its_reconcile_timezone(): void
    {
        Config::set('summary_bet_record.' . VendorIdEnum::PTSW->value . '.reconcile_timezone', -4);

        $component = Livewire::test(SummaryBetRecord::class)
            ->set('data.vendor_id', VendorIdEnum::PTSW->value);

        $this->assertSame('-04:00', $component->get('data')['timezone']);
    }

    #[Group('rules/summary-bet-record#R-18')]
    public function test_vendor_without_reconcile_timezone_defaults_to_utc8_not_calc_time_zone(): void
    {
        Vendor::find(VendorIdEnum::PTSW->value)->forceFill(['calc_time_zone' => -4])->save();
        Config::set('summary_bet_record.' . VendorIdEnum::PTSW->value, ['third_party_code' => 'PTSW', 'time_field' => 'payout_time']);

        $component = Livewire::test(SummaryBetRecord::class)
            ->set('data.vendor_id', VendorIdEnum::PTSW->value);

        $this->assertSame('+08:00', $component->get('data')['timezone']);
    }

    /** 運維只能回溯 MAX_DAYS_AGO（30）天，更舊的日期被表單驗證擋下 */
    public function test_ops_date_older_than_30_days_is_rejected(): void
    {
        $this->actingAs($this->makeUser(UserRoleEnum::OPS), 'admin');

        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-07-20',
            ])
            ->call('execute')
            ->assertHasFormErrors(['date']);

        Queue::assertNothingPushed();
    }

    /** 管理員不受 30 天下限，可送一個月前的日期（補舊月份對帳） */
    public function test_admin_can_submit_date_older_than_30_days(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-07-20',
                'dry_run' => true,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-07-20', AdminOperationLog::latest('id')->first()->params['--date']);
        Queue::assertPushed(RunAdminOperationJob::class);
    }

    /** 超級管理員同樣不受 30 天下限 */
    public function test_superadmin_can_submit_date_older_than_30_days(): void
    {
        $this->actingAs($this->makeUser(UserRoleEnum::SUPERADMIN), 'admin');

        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-07-20',
                'dry_run' => true,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        Queue::assertPushed(RunAdminOperationJob::class);
    }

    /** 管理員只能回溯 ADMIN_MAX_DAYS_AGO（60）天，62 天前的日期被表單驗證擋下 */
    public function test_admin_date_older_than_60_days_is_rejected(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-06-20',
                'dry_run' => true,
            ])
            ->call('execute')
            ->assertHasFormErrors(['date']);

        Queue::assertNothingPushed();
    }

    /** 4 天前（含）以上先跳確認視窗：按下執行不會直接派工 */
    public function test_date_4_days_ago_opens_confirmation_instead_of_running(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-08-17',
            ])
            ->call('mountAction', 'execute');

        $this->assertNull(AdminOperationLog::latest('id')->first());
        Queue::assertNothingPushed();
    }

    /** 3 天前以內不跳視窗，按下執行即派工 */
    public function test_date_within_3_days_runs_without_confirmation(): void
    {
        Livewire::test(SummaryBetRecord::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'timezone' => '+08:00',
                'date' => '2026-08-18',
            ])
            ->call('mountAction', 'execute');

        $this->assertNotNull(AdminOperationLog::latest('id')->first());
        Queue::assertPushed(RunAdminOperationJob::class);
    }
}
