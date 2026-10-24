<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Pages\Operations\OperationLogs;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 操作紀錄頁的時間一律以後台顯示時區（+08:00）呈現與過濾；DB 存的仍是 UTC。
 *
 * php artisan test tests/Feature/Olympus/OperationLogsTimezoneTest.php --compact
 */
class OperationLogsTimezoneTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'parent' => 0,
            'username' => 'ops_tz_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
        ]);
        $this->actingAs($this->user, 'admin');
    }

    #[TestDox('UTC 9/15 17:00 的紀錄顯示為台北 9/16 01:00，日期過濾也以台北日界為準')]
    public function test_created_at_is_shown_and_filtered_in_display_timezone(): void
    {
        // 這筆在 UTC 日曆屬 15 號、在台北日曆屬 16 號——顯示與過濾若有一邊沒換算就會露餡
        $log = $this->logCreatedAt('2026-09-15 17:00:00');
        $expected = Carbon::parse('2026-09-15 17:00:00', 'UTC')
            ->setTimezone(AdminPanelProvider::DISPLAY_TIMEZONE)
            ->format(OperationLogs::DATETIME_FORMAT);

        Livewire::test(OperationLogs::class)
            ->assertTableColumnFormattedStateSet('created_at', $expected, record: $log)
            ->filterTable('created_at', ['created_from' => '2026-09-16'])
            ->assertCanSeeTableRecords([$log])
            ->filterTable('created_at', ['created_until' => '2026-09-15'])
            ->assertCanNotSeeTableRecords([$log]);
    }

    private function logCreatedAt(string $utc): AdminOperationLog
    {
        $log = AdminOperationLog::create([
            'user_id' => $this->user->id,
            'operation' => AdminOperationEnum::SchemaOperation->value,
            'params' => [],
            'status' => AdminOperationStatusEnum::SUCCESS->value,
        ]);

        // created_at 在 $guarded 內，建完再改
        $log->created_at = Carbon::parse($utc, 'UTC');
        $log->save();

        return $log;
    }
}
