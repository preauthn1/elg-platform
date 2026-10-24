<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\CacheKeys\CacheKeys;
use App\Enums\AdminOperationEnum;
use App\Enums\StatusEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages\EditVendor;
use App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages\ListVendor;
use App\Helpers\Common;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorManagementService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 遊戲商管理（target/tickets#4415）：列表搜尋篩選、修改驗證、停用／啟用、操作紀錄與快取，
 * 以及不可修改欄位（code、tname、單一錢包、info、rate、percentage）在服務層一律丟棄。
 *
 * php artisan test tests/Feature/Olympus/VendorManagementTest.php --compact
 */
class VendorManagementTest extends TestCase
{
    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = User::create([
            'parent' => 0,
            'username' => 'vendor_ops_' . uniqid(),
            'email' => 'vendor_ops_' . uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => UserRoleEnum::OPS->value,
            'admin' => 0,
        ]);
        $this->actingAs($this->ops, 'admin');
    }

    private function latestLog(AdminOperationEnum $operation, int $vendorId): ?AdminOperationLog
    {
        return AdminOperationLog::where('operation', $operation->value)
            ->where('params->vendor_id', $vendorId)
            ->latest('id')
            ->first();
    }

    #[TestDox('編輯頁表單最外層為單欄，各區塊佔滿整列不並排')]
    public function test_edit_form_is_single_column(): void
    {
        $vendor = Vendor::factory()->create();

        $form = Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])->instance()->form;

        $this->assertSame(1, $form->getColumns('lg'));
    }

    #[TestDox('運維可進列表，依編號、名稱搜尋只出現該筆，狀態篩選「關閉」只出現關閉的')]
    public function test_ops_can_search_and_filter(): void
    {
        $target = Vendor::factory()->create(['name' => 'zz_target_' . uniqid(), 'status' => StatusEnum::DISABLE->value]);
        $other = Vendor::factory()->create(['name' => 'zz_other_' . uniqid(), 'status' => StatusEnum::ENABLE->value]);

        Livewire::test(ListVendor::class)
            ->assertOk()
            ->searchTable($target->name)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListVendor::class)
            ->searchTable((string) $target->id)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListVendor::class)
            ->filterTable('status', StatusEnum::DISABLE->value)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[TestDox('白名單填兩個合法 IP 儲存成功，操作紀錄只列有變動的欄位')]
    public function test_update_whitelist_writes_audit_with_changed_fields_only(): void
    {
        $vendor = Vendor::factory()->create(['whitelist' => null]);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['whitelist' => '0.0.0.0, 0.0.0.0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.0.0.0,0.0.0.0', $vendor->fresh()->whitelist);

        $log = $this->latestLog(AdminOperationEnum::UpdateVendor, $vendor->id);
        $this->assertNotNull($log);
        $this->assertSame($this->ops->id, $log->user_id);
        $this->assertSame(['whitelist'], array_keys($log->params['changes']));
        $this->assertNull($log->params['changes']['whitelist']['from']);
        $this->assertSame('0.0.0.0,0.0.0.0', $log->params['changes']['whitelist']['to']);
    }

    #[TestDox('白名單填網段儲存失敗，資料庫不變')]
    public function test_invalid_whitelist_is_rejected(): void
    {
        $vendor = Vendor::factory()->create(['whitelist' => '0.0.0.0']);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['whitelist' => '0.0.0.0/24'])
            ->call('save')
            ->assertHasFormErrors(['whitelist']);

        $this->assertSame('0.0.0.0', $vendor->fresh()->whitelist);
        $this->assertNull($this->latestLog(AdminOperationEnum::UpdateVendor, $vendor->id));
    }

    #[TestDox('什麼都沒改就儲存：不寫操作紀錄（Toggle 的 bool 與 DB 的 0/1 不算變動）')]
    public function test_saving_without_changes_writes_no_audit(): void
    {
        $vendor = Vendor::factory()->create();

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->latestLog(AdminOperationEnum::UpdateVendor, $vendor->id));
    }

    #[TestDox('列表停用後狀態為關閉、資料列仍在；再啟用恢復正常，兩次都留紀錄')]
    public function test_disable_then_enable(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusEnum::ENABLE->value]);

        Livewire::test(ListVendor::class)
            ->assertTableActionHidden('enable', $vendor)
            ->callTableAction('disable', $vendor);

        $this->assertSame(StatusEnum::DISABLE->value, (int) $vendor->fresh()->status);
        $this->assertNotNull($this->latestLog(AdminOperationEnum::DisableVendor, $vendor->id));

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->callAction('enable');

        $this->assertSame(StatusEnum::ENABLE->value, (int) $vendor->fresh()->status);
        $this->assertNotNull($this->latestLog(AdminOperationEnum::EnableVendor, $vendor->id));
    }

    #[TestDox('修改不能順手改狀態：傳進 status 會被丟棄，狀態只能走停用／啟用')]
    public function test_update_ignores_status(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusEnum::ENABLE->value, 'whitelist' => null]);

        $changes = app(VendorManagementService::class)->update(
            $vendor,
            ['status' => StatusEnum::DISABLE->value, 'whitelist' => '0.0.0.0'],
            $this->ops->id,
        );

        $this->assertSame(['whitelist'], array_keys($changes));
        $this->assertSame(StatusEnum::ENABLE->value, (int) $vendor->fresh()->status);
    }

    #[TestDox('不可修改的欄位（code、tname、單一錢包、info、rate、percentage）即使送進服務層也會被丟棄')]
    public function test_update_ignores_non_editable_columns(): void
    {
        $vendor = Vendor::factory()->create([
            'code' => 'orig_code',
            'tname' => 'orig_tname',
            'is_single_wallet' => 0,
            'info' => '{"CLIENT_SECRET":"fake-real-secret-7777"}',
            'rate' => 1,
            'percentage' => 0,
            'whitelist' => null,
        ]);

        $changes = app(VendorManagementService::class)->update(
            $vendor,
            [
                'code' => 'hacked',
                'tname' => 'hacked',
                'is_single_wallet' => true,
                'info' => '{"CLIENT_SECRET":"hacked"}',
                'rate' => 9,
                'percentage' => 9,
                'whitelist' => '0.0.0.0',
            ],
            $this->ops->id,
        );

        $this->assertSame(['whitelist'], array_keys($changes));
        $fresh = $vendor->fresh();
        $this->assertSame('orig_code', $fresh->code);
        $this->assertSame('orig_tname', $fresh->tname);
        $this->assertSame(0, (int) $fresh->is_single_wallet);
        $this->assertSame('{"CLIENT_SECRET":"fake-real-secret-7777"}', $fresh->info);
        $this->assertSame(0, Common::numCompare((string) $fresh->rate, '1', 5));
        $this->assertSame(0, Common::numCompare((string) $fresh->percentage, '0', 5));
    }

    #[TestDox('暫不開放的顯示開關與結算參數即使送進服務層也會被丟棄')]
    public function test_update_ignores_out_of_scope_columns(): void
    {
        $vendor = Vendor::factory()->create(['is_new' => 0, 'is_free' => 0, 'valid_bet_rate' => 1, 'whitelist' => null]);

        $changes = app(VendorManagementService::class)->update(
            $vendor,
            ['is_new' => true, 'is_free' => true, 'valid_bet_rate' => 9, 'whitelist' => '0.0.0.0'],
            $this->ops->id,
        );

        $this->assertSame(['whitelist'], array_keys($changes));
        $fresh = $vendor->fresh();
        $this->assertSame(0, (int) $fresh->is_new);
        $this->assertSame(0, (int) $fresh->is_free);
        $this->assertSame(0, Common::numCompare((string) $fresh->valid_bet_rate, '1', 2));
    }

    #[TestDox('儲存後遊戲商快取已清除，不用等快取過期')]
    public function test_save_forgets_vendor_cache(): void
    {
        $vendor = Vendor::factory()->create(['whitelist' => null]);
        Cache::put(CacheKeys::vendor($vendor->id), $vendor, 3600);

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['whitelist' => '0.0.0.0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Cache::has(CacheKeys::vendor($vendor->id)));
    }

    #[TestDox('不提供刪除與新增（VendorPolicy 未定義即拒絕）')]
    #[Group('rules/vendor-and-channel-management#R-20')]
    public function test_delete_and_create_are_denied(): void
    {
        $vendor = Vendor::factory()->create();

        $this->assertFalse($this->ops->can('delete', $vendor));
        $this->assertFalse($this->ops->can('create', Vendor::class));
        $this->assertTrue($this->ops->can('update', $vendor));
    }
}
