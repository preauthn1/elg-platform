<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\CashFlowTypeEnum;
use App\Enums\StatusEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages\EditCashFlow;
use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages\ListCashFlow;
use App\Helpers\Common;
use App\Models\AdminOperationLog;
use App\Models\CashFlow;
use App\Models\User;
use App\Services\CashFlowManagementService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 金流商管理（target/tickets#4446）：列表搜尋篩選、白名單修改與操作紀錄，
 * 以及「只有白名單可改」在服務層的把關——表單欄位設唯讀擋不住被竄改的請求。
 *
 * php artisan test tests/Feature/Olympus/CashFlowManagementTest.php --compact
 */
class CashFlowManagementTest extends TestCase
{
    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = User::create([
            'parent' => 0,
            'username' => 'cf_ops_' . uniqid(),
            'email' => 'cf_ops_' . uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => UserRoleEnum::OPS->value,
            'admin' => 0,
        ]);
        $this->actingAs($this->ops, 'admin');
    }

    private function createCashFlow(array $overrides = []): CashFlow
    {
        return CashFlow::create(array_merge([
            'third_party_code' => 'D_TEST' . random_int(1000, 9999),
            'name' => 'TestPay - Deposit ' . uniqid(),
            'rate' => '1.00000',
            'percentage' => '0.00000',
            'type' => CashFlowTypeEnum::DEPOSIT->value,
            'status' => StatusEnum::ENABLE->value,
            'remark' => '',
            'info' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function latestLog(int $cashFlowId): ?AdminOperationLog
    {
        return AdminOperationLog::where('operation', AdminOperationEnum::UpdateCashFlowWhitelist->value)
            ->where('params->cash_flow_id', $cashFlowId)
            ->latest('id')
            ->first();
    }

    #[TestDox('編輯頁表單最外層為單欄，各區塊佔滿整列不並排')]
    public function test_edit_form_is_single_column(): void
    {
        $cashFlow = $this->createCashFlow();

        $form = Livewire::test(EditCashFlow::class, ['record' => $cashFlow->getRouteKey()])->instance()->form;

        $this->assertSame(1, $form->getColumns('lg'));
    }

    #[TestDox('運維可進列表，依三方代碼搜尋只出現該筆，支付類別篩選「代付」排除代收')]
    public function test_ops_can_search_and_filter(): void
    {
        $deposit = $this->createCashFlow(['third_party_code' => 'D_ZZTARGET', 'type' => CashFlowTypeEnum::DEPOSIT->value]);
        $withdraw = $this->createCashFlow(['third_party_code' => 'W_ZZOTHER', 'type' => CashFlowTypeEnum::WITHDRAW->value]);

        Livewire::test(ListCashFlow::class)
            ->assertOk()
            ->searchTable('D_ZZTARGET')
            ->assertCanSeeTableRecords([$deposit])
            ->assertCanNotSeeTableRecords([$withdraw]);

        Livewire::test(ListCashFlow::class)
            ->filterTable('type', CashFlowTypeEnum::WITHDRAW->value)
            ->assertCanSeeTableRecords([$withdraw])
            ->assertCanNotSeeTableRecords([$deposit]);
    }

    #[TestDox('白名單填兩個合法 IP 儲存成功，去掉空白後存檔並留操作紀錄')]
    public function test_update_whitelist_writes_audit(): void
    {
        $cashFlow = $this->createCashFlow(['whitelist' => null]);

        Livewire::test(EditCashFlow::class, ['record' => $cashFlow->getRouteKey()])
            ->fillForm(['whitelist' => '0.0.0.0, 0.0.0.0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.0.0.0,0.0.0.0', $cashFlow->fresh()->whitelist);

        $log = $this->latestLog($cashFlow->id);
        $this->assertNotNull($log);
        $this->assertSame($this->ops->id, $log->user_id);
        $this->assertNull($log->params['changes']['whitelist']['from']);
        $this->assertSame('0.0.0.0,0.0.0.0', $log->params['changes']['whitelist']['to']);
    }

    #[TestDox('白名單填網段儲存失敗，資料庫不變也不留紀錄')]
    public function test_invalid_whitelist_is_rejected(): void
    {
        $cashFlow = $this->createCashFlow(['whitelist' => '0.0.0.0']);

        Livewire::test(EditCashFlow::class, ['record' => $cashFlow->getRouteKey()])
            ->fillForm(['whitelist' => '0.0.0.0/24'])
            ->call('save')
            ->assertHasFormErrors(['whitelist']);

        $this->assertSame('0.0.0.0', $cashFlow->fresh()->whitelist);
        $this->assertNull($this->latestLog($cashFlow->id));
    }

    #[TestDox('清空白名單存成 null（對無驗簽金流商等於回調全拒，不是關掉檢查）')]
    public function test_clearing_whitelist_stores_null(): void
    {
        $cashFlow = $this->createCashFlow(['whitelist' => '0.0.0.0']);

        Livewire::test(EditCashFlow::class, ['record' => $cashFlow->getRouteKey()])
            ->fillForm(['whitelist' => '   '])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($cashFlow->fresh()->whitelist);
        $this->assertSame('0.0.0.0', $this->latestLog($cashFlow->id)?->params['changes']['whitelist']['from']);
    }

    #[TestDox('什麼都沒改就儲存：不寫操作紀錄')]
    public function test_saving_without_changes_writes_no_audit(): void
    {
        $cashFlow = $this->createCashFlow(['whitelist' => '0.0.0.0']);

        Livewire::test(EditCashFlow::class, ['record' => $cashFlow->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.0.0.0', $cashFlow->fresh()->whitelist);
        $this->assertNull($this->latestLog($cashFlow->id));
    }

    #[TestDox('只有白名單可改：其餘欄位即使送進服務層也一律丟棄')]
    public function test_update_ignores_every_column_but_whitelist(): void
    {
        $cashFlow = $this->createCashFlow([
            'third_party_code' => 'D_ORIG',
            'name' => 'orig name',
            'type' => CashFlowTypeEnum::DEPOSIT->value,
            'rate' => '1.00000',
            'percentage' => '0.00000',
            'status' => StatusEnum::ENABLE->value,
            'remark' => 'orig remark',
            'whitelist' => null,
        ]);

        app(CashFlowManagementService::class)->updateWhitelist(
            $cashFlow,
            [
                'third_party_code' => 'D_HACKED',
                'name' => 'hacked',
                'type' => CashFlowTypeEnum::WITHDRAW->value,
                'rate' => '9.00000',
                'percentage' => '9.00000',
                'status' => StatusEnum::DISABLE->value,
                'remark' => 'hacked',
                'admin' => 999,
                'whitelist' => '0.0.0.0',
            ],
            $this->ops->id,
        );

        $fresh = $cashFlow->fresh();
        $this->assertSame('D_ORIG', $fresh->third_party_code);
        $this->assertSame('orig name', $fresh->name);
        $this->assertSame(CashFlowTypeEnum::DEPOSIT->value, (int) $fresh->type);
        $this->assertSame(0, Common::numCompare((string) $fresh->rate, '1', 5));
        $this->assertSame(0, Common::numCompare((string) $fresh->percentage, '0', 5));
        $this->assertSame(StatusEnum::ENABLE->value, (int) $fresh->status);
        $this->assertSame('orig remark', $fresh->remark);
        $this->assertSame(0, (int) $fresh->admin);

        // 唯一該變的就是白名單
        $this->assertSame('0.0.0.0', $fresh->whitelist);
    }
}
