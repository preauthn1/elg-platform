<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\QueueJobRunStatusEnum;
use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Operations\UpdateGameList;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Models\Vendor;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\NeedsGameListSourceUrl;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 後台「更新遊戲清單」操作頁測試。
 *
 * php artisan test tests/Feature/Olympus/UpdateGameListPageTest.php --compact
 */
class UpdateGameListPageTest extends TestCase
{
    private const string SOURCE_URL = 'https://sharepoint.test/sites/x/_layouts/15/download.aspx?share=test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        if (!Vendor::find(VendorIdEnum::PTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::PTSW->value, 'name' => 'PlayTech SW']);
        }

        $this->actingAs(User::create([
            'parent' => 0,
            'username' => 'ops_tester_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
        ]), 'admin');
    }

    #[TestDox('PTSW 帶入來源網址後以 --sheet-url 傳給指令')]
    #[Group('rules/game-list-sync#R-12')]
    public function test_source_url_is_passed_as_sheet_url_option(): void
    {
        Livewire::test(UpdateGameList::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::PTSW->value,
                'sheet_url' => self::SOURCE_URL,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $params = AdminOperationLog::latest('id')->first()->params;

        $this->assertSame(self::SOURCE_URL, $params['--sheet-url']);
        $this->assertSame((string) VendorIdEnum::PTSW->value, $params['--vendor-id']);
    }

    #[TestDox('PTSW 未填來源網址時擋在表單驗證，不留下操作紀錄')]
    #[Group('rules/game-list-sync#R-11')]
    public function test_source_url_is_required_for_ptsw(): void
    {
        $before = AdminOperationLog::max('id');

        Livewire::test(UpdateGameList::class)
            ->fillForm(['vendor_id' => VendorIdEnum::PTSW->value])
            ->call('execute')
            ->assertHasFormErrors(['sheet_url']);

        // 擋在驗證就不該派發，否則 job 跑起來只會拿到「缺來源網址」的失敗
        $this->assertSame($before, AdminOperationLog::max('id'));
    }

    #[TestDox('三方有清單 API 的遊戲商不需要來源網址，欄位不出現也不擋送出')]
    #[Group('rules/game-list-sync#R-11')]
    public function test_source_url_is_not_required_for_api_based_vendors(): void
    {
        $vendorId = $this->apiBasedGameListVendorId();

        Livewire::test(UpdateGameList::class)
            ->fillForm(['vendor_id' => $vendorId])
            ->call('execute')
            ->assertHasNoFormErrors();

        $params = AdminOperationLog::latest('id')->first()->params;

        $this->assertArrayNotHasKey('--sheet-url', $params);
    }

    #[TestDox('派發即建執行列（未處理）並以 queue_job_run_id 關聯，輸出與即時狀態由該列承載')]
    public function test_dispatch_creates_pending_queue_job_run(): void
    {
        Livewire::test(UpdateGameList::class)
            ->fillForm(['vendor_id' => $this->apiBasedGameListVendorId()])
            ->call('execute')
            ->assertHasNoFormErrors();

        $log = AdminOperationLog::latest('id')->first();

        $this->assertNotNull($log->queue_job_run_id);
        $this->assertSame(QueueJobRunStatusEnum::PENDING, $log->queueJobRun->status);
    }

    /**
     * 取一個 driver 有清單能力、但不需外部提供來源網址的 vendor id
     */
    private function apiBasedGameListVendorId(): int
    {
        foreach (config('vendor.driver') as $vendorId => $driverClass) {
            if (!is_a($driverClass, HasGameList::class, true)) {
                continue;
            }

            if (is_a($driverClass, NeedsGameListSourceUrl::class, true)) {
                continue;
            }

            if (Vendor::find($vendorId) === null) {
                Vendor::factory()->create(['id' => $vendorId, 'name' => 'GameList Vendor ' . $vendorId]);
            }

            return (int) $vendorId;
        }

        $this->fail('找不到任何不需外部來源網址的 HasGameList 遊戲商');
    }
}
