<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Operations\TransferMoneyBack;
use App\Jobs\RunAdminOperationJob;
use App\Models\AdminOperationLog;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\AdminOperationService;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 後台「轉回遊戲商玩家餘額」操作頁測試。
 *
 * php artisan test tests/Feature/Olympus/TransferMoneyBackPageTest.php --compact
 */
class TransferMoneyBackPageTest extends TestCase
{
    private Agent $agent;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        if (!Vendor::find(VendorIdEnum::MEGA888->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::MEGA888->value, 'name' => 'Mega888']);
        }
        // Mega888 是轉帳錢包：factory 預設 is_single_wallet = 1，會被下拉的轉帳錢包過濾排除
        Vendor::where('id', VendorIdEnum::MEGA888->value)->update(['is_single_wallet' => 0]);

        $this->agent = Agent::factory()->create();
        $vendorInfo = VendorInfo::factory()->create(['vendor_id' => VendorIdEnum::MEGA888->value]);
        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => VendorIdEnum::MEGA888->value,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);

        $this->user = User::create([
            'parent' => 0,
            'username' => 'ops_tester_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
        ]);
        $this->actingAs($this->user, 'admin');
    }

    #[TestDox('遊戲商＋代理送出後以 --vendorId／--agentId 派發，eventV2 預設 0 並走 long-running 連線')]
    public function test_execute_dispatches_with_vendor_and_agent(): void
    {
        Livewire::test(TransferMoneyBack::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::MEGA888->value,
                'agent_id' => $this->agent->id,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $log = AdminOperationLog::latest('id')->first();

        $this->assertSame('vendor:transfer_money_back', $log->operation);
        $this->assertEquals([
            '--vendorId' => (string) VendorIdEnum::MEGA888->value,
            '--agentId' => (string) $this->agent->id,
            '--eventV2' => '0',
            // 時間預算固定帶，指令在 job 3600 秒硬上限前自行收尾並印續跑起點
            '--maxSeconds' => (string) TransferMoneyBack::MAX_SECONDS,
        ], $log->params);
        Queue::assertPushed(RunAdminOperationJob::class, fn (RunAdminOperationJob $job): bool => $job->connection === 'redis-long-running');
    }

    #[TestDox('活動 V2 開關原樣帶給指令；頁面沒有手填起點／批量，不帶 --beforeId 與 --limit')]
    public function test_event_v2_passes_through_without_manual_batch_params(): void
    {
        Livewire::test(TransferMoneyBack::class)
            ->fillForm([
                'vendor_id' => VendorIdEnum::MEGA888->value,
                'agent_id' => $this->agent->id,
                'event_v2' => true,
            ])
            ->call('execute')
            ->assertHasNoFormErrors();

        $params = AdminOperationLog::latest('id')->first()->params;

        $this->assertSame('1', $params['--eventV2']);
        $this->assertArrayNotHasKey('--beforeId', $params);
        $this->assertArrayNotHasKey('--limit', $params);
        $this->assertArrayNotHasKey('--memberUsername', $params);
    }

    #[TestDox('上次執行印了續跑起點：回到頁面自動帶入該次的遊戲商／代理／活動 V2，執行時自動帶 --beforeId 接續')]
    public function test_page_resumes_from_last_run_output_automatically(): void
    {
        $this->auditRunWithResumeMarker(919001);

        $component = Livewire::test(TransferMoneyBack::class);
        $data = $component->get('data');

        $this->assertSame(919001, $component->get('autoResumeCursor'));
        $this->assertStringContainsString('919001', (string) $component->get('resumeHint'));
        $this->assertSame(VendorIdEnum::MEGA888->value, (int) $data['vendor_id']);
        $this->assertSame($this->agent->id, (int) $data['agent_id']);
        $this->assertTrue((bool) $data['event_v2']);

        $component->call('execute')->assertHasNoFormErrors();

        $this->assertSame('919001', AdminOperationLog::latest('id')->first()->params['--beforeId']);
    }

    #[TestDox('接續狀態下換了代理：改查新代理自己的續跑起點，沒有就不帶 --beforeId（從頭跑）')]
    public function test_changing_agent_switches_to_that_agent_own_cursor(): void
    {
        $this->auditRunWithResumeMarker(919001);
        $anotherAgent = Agent::factory()->create();
        AgentVendor::create([
            'agent_id' => $anotherAgent->id,
            'vendor_id' => VendorIdEnum::MEGA888->value,
            'vendor_info_id' => VendorInfo::where('vendor_id', VendorIdEnum::MEGA888->value)->value('id'),
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);

        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.agent_id', $anotherAgent->id);

        $this->assertNull($component->get('autoResumeCursor'));

        $component->call('execute')->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('--beforeId', AdminOperationLog::latest('id')->first()->params);
    }

    #[TestDox('指定會員帳號：帶 --memberUsername（去前後空白），且即使有中斷點也不帶 --beforeId')]
    public function test_single_member_passes_username_and_ignores_resume_cursor(): void
    {
        $this->auditRunWithResumeMarker(919001);

        Livewire::test(TransferMoneyBack::class)
            ->fillForm(['member_username' => ' TOGOLC28 '])
            ->call('execute')
            ->assertHasNoFormErrors();

        $params = AdminOperationLog::latest('id')->first()->params;

        $this->assertSame('TOGOLC28', $params['--memberUsername']);
        $this->assertArrayNotHasKey('--beforeId', $params);
    }

    #[TestDox('不同遊戲商可以同時跑：另一商有未完成的執行時照樣派發')]
    public function test_other_vendor_running_does_not_block(): void
    {
        $other = $this->transferWalletVendor(VendorIdEnum::PUSSY->value, 'Pussy888');
        $this->runningRun($other->id, $this->agent->id);

        Livewire::test(TransferMoneyBack::class)
            ->fillForm(['vendor_id' => VendorIdEnum::MEGA888->value, 'agent_id' => $this->agent->id])
            ->call('execute')
            ->assertHasNoFormErrors();

        $log = AdminOperationLog::latest('id')->first();
        $this->assertSame((string) VendorIdEnum::MEGA888->value, $log->params['--vendorId']);
    }

    #[TestDox('同一個遊戲商仍然互斥：有未完成的執行時擋下，不建立新紀錄')]
    public function test_same_vendor_still_blocks(): void
    {
        $blocking = $this->runningRun(VendorIdEnum::MEGA888->value, $this->agent->id);

        Livewire::test(TransferMoneyBack::class)
            ->fillForm(['vendor_id' => VendorIdEnum::MEGA888->value, 'agent_id' => $this->agent->id])
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertSame($blocking->id, AdminOperationLog::latest('id')->first()->id);
    }

    #[TestDox('續跑起點各商獨立：別的遊戲商停在哪與本商無關，不會被帶進來')]
    public function test_resume_cursor_is_per_vendor(): void
    {
        $other = $this->transferWalletVendor(VendorIdEnum::PUSSY->value, 'Pussy888');
        $this->auditRunWithResumeMarker(919001);                       // Mega888 的起點
        $this->auditRunWithResumeMarker(500500, $other->id);           // 另一商的起點（較新）

        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.vendor_id', VendorIdEnum::MEGA888->value)
            ->set('data.agent_id', $this->agent->id);

        $this->assertSame(919001, $component->get('autoResumeCursor'));
        $this->assertSame(919001, (int) $component->get('data.resume_before_id'));
    }

    #[TestDox('換遊戲商時代理仍開通就保留，沒開通才清空')]
    public function test_switching_vendor_keeps_agent_when_still_granted(): void
    {
        $shared = $this->transferWalletVendor(VendorIdEnum::PUSSY->value, 'Pussy888');
        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $shared->id,
            'vendor_info_id' => VendorInfo::factory()->create(['vendor_id' => $shared->id])->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);
        $exclusive = $this->transferWalletVendor(VendorIdEnum::SCR->value, 'SCR');

        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.vendor_id', VendorIdEnum::MEGA888->value)
            ->set('data.agent_id', $this->agent->id);

        // 有開通 → 保留
        $component->set('data.vendor_id', $shared->id);
        $this->assertSame($this->agent->id, (int) $component->get('data.agent_id'));

        // 沒開通 → 清空
        $component->set('data.vendor_id', $exclusive->id);
        $this->assertNull($component->get('data.agent_id'));
    }

    #[TestDox('頁面開著期間上一輪才收尾：送出當下重查，不沿用進頁面那一刻的空白起點')]
    public function test_resume_cursor_is_resolved_at_submit_time(): void
    {
        // 進頁面時還沒有任何執行紀錄 → 起點空白
        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.vendor_id', VendorIdEnum::MEGA888->value)
            ->set('data.agent_id', $this->agent->id);

        $this->assertNull($component->get('autoResumeCursor'));

        // 頁面開著的期間，上一輪收尾並寫下續跑起點（同一個範圍：同商、同代理、同 eventV2）
        $this->auditRunWithResumeMarker(919001, eventV2: '0');

        $component->call('execute')->assertHasNoFormErrors();

        $this->assertSame('919001', AdminOperationLog::latest('id')->first()->params['--beforeId']);
    }

    #[TestDox('手動覆寫續跑起點：照操作者填的值帶，不被送出當下的重查蓋掉')]
    public function test_manual_resume_cursor_overrides_auto_value(): void
    {
        $this->auditRunWithResumeMarker(919001);

        Livewire::test(TransferMoneyBack::class)
            ->set('data.resume_before_id', 123456)
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertSame('123456', AdminOperationLog::latest('id')->first()->params['--beforeId']);
    }

    #[TestDox('手填的續跑起點只算這一次：送出後欄位交還自動接續，下一輪接的是新的中斷點')]
    public function test_manual_resume_cursor_is_cleared_after_submit(): void
    {
        $this->auditRunWithResumeMarker(919001);

        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.resume_before_id', 123456)
            ->call('execute')
            ->assertHasNoFormErrors();

        // 送出後欄位回到自動值（剛派出去那筆還沒有輸出，所以是空的）：
        // 欄位空下一次送出就走重查（見 test_blank_resume_cursor_falls_back_to_latest_run），
        // 這一輪收尾寫下新的中斷點後按執行接的就是新的那個，而不是手填的舊值
        $this->assertEmpty($component->get('data.resume_before_id'));
    }

    #[TestDox('續跑起點被清成空字串：視為沒指定，送出當下重查並接續上一次')]
    public function test_blank_resume_cursor_falls_back_to_latest_run(): void
    {
        $this->auditRunWithResumeMarker(919001);

        $component = Livewire::test(TransferMoneyBack::class);
        $this->assertSame(919001, $component->get('autoResumeCursor'));

        // 執行中頁面每 5 秒輪詢一次，會把空欄位的 '' 送回後端；那不是操作者要從頭跑的意思
        $component->set('data.resume_before_id', '')
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertSame('919001', AdminOperationLog::latest('id')->first()->params['--beforeId']);
    }

    #[TestDox('續跑起點填 0：同樣視為沒指定，接續上一次而不是從頭跑')]
    public function test_zero_resume_cursor_falls_back_to_latest_run(): void
    {
        $this->auditRunWithResumeMarker(919001);

        Livewire::test(TransferMoneyBack::class)
            ->set('data.resume_before_id', 0)
            ->call('execute')
            ->assertHasNoFormErrors();

        $this->assertSame('919001', AdminOperationLog::latest('id')->first()->params['--beforeId']);
    }

    /** 造一個轉帳錢包遊戲商（下拉只列 is_single_wallet = 0） */
    private function transferWalletVendor(int $id, string $name): Vendor
    {
        $vendor = Vendor::find($id) ?? Vendor::factory()->create(['id' => $id, 'name' => $name]);
        Vendor::where('id', $id)->update(['is_single_wallet' => 0, 'name' => $name]);

        return $vendor->refresh();
    }

    /** 造一筆該遊戲商「執行中」的紀錄（防重複判定看的是未收尾狀態） */
    private function runningRun(int $vendorId, int $agentId): AdminOperationLog
    {
        return AdminOperationLog::create([
            'user_id' => $this->user->id,
            'operation' => AdminOperationEnum::TransferMoneyBack->value,
            'params' => ['--vendorId' => (string) $vendorId, '--agentId' => (string) $agentId, '--eventV2' => '0'],
            'status' => \App\Enums\AdminOperationStatusEnum::RUNNING->value,
            'started_at' => now(),
        ]);
    }

    /**
     * 造一筆「預算用盡、印了續跑起點」的上次執行紀錄（遊戲商 Mega888、代理 $this->agent、活動 V2 開）
     */
    private function auditRunWithResumeMarker(int $cursor, ?int $vendorId = null, string $eventV2 = '1'): AdminOperationLog
    {
        return app(AdminOperationService::class)->audit(
            AdminOperationEnum::TransferMoneyBack,
            userId: $this->user->id,
            params: ['--vendorId' => (string) ($vendorId ?? VendorIdEnum::MEGA888->value), '--agentId' => (string) $this->agent->id, '--eventV2' => $eventV2],
            output: sprintf("進度: 2000/65000, 帳號id: %d, 用戶: a (1), 遊戲帳號: x, 轉回金額(平台幣): 10\n處理完成：共 65000 筆，已處理 2000，成功 2000、失敗 0、略過 0，轉回總額(平台幣) 100\n時間預算 3300 秒用盡，剩餘 63000 筆未處理\n續跑起點 beforeId: %d\n明細檔：/x", $cursor, $cursor),
        );
    }

    #[TestDox('上次執行超過 24 小時（隔很久再抽分是新的一輪）：不自動帶入，只在欄位提示手動填')]
    public function test_page_does_not_prefill_stale_resume_cursor(): void
    {
        $log = app(AdminOperationService::class)->audit(
            AdminOperationEnum::TransferMoneyBack,
            userId: $this->user->id,
            params: ['--vendorId' => (string) VendorIdEnum::MEGA888->value, '--agentId' => (string) $this->agent->id, '--eventV2' => '0'],
            output: "處理完成：共 65000 筆，已處理 2000，成功 2000、失敗 0、略過 0，轉回總額(平台幣) 100\n續跑起點 beforeId: 919001\n明細檔：/x",
        );
        $log->forceFill(['finished_at' => now()->subHours(TransferMoneyBack::RESUME_MAX_AGE_HOURS + 1)])->save();

        $component = Livewire::test(TransferMoneyBack::class);

        $this->assertNull($component->get('autoResumeCursor'));
        $this->assertStringContainsString('919001', (string) $component->get('resumeHint'));
        $this->assertStringContainsString('從頭開始', (string) $component->get('resumeHint'));
    }

    #[TestDox('上次執行全部跑完（有摘要、無續跑起點）：不帶入起始帳號 id')]
    public function test_page_does_not_prefill_when_last_run_completed(): void
    {
        app(AdminOperationService::class)->audit(
            AdminOperationEnum::TransferMoneyBack,
            userId: $this->user->id,
            params: ['--vendorId' => (string) VendorIdEnum::MEGA888->value, '--agentId' => (string) $this->agent->id, '--eventV2' => '0'],
            output: "進度: 3/3, 帳號id: 100, 用戶: a (1), 遊戲帳號: x, 轉回金額(平台幣): 10\n處理完成：共 3 筆，已處理 3，成功 3、失敗 0、略過 0，轉回總額(平台幣) 30\n明細檔：/x",
        );

        $component = Livewire::test(TransferMoneyBack::class);

        $this->assertNull($component->get('autoResumeCursor'));
        $this->assertNull($component->get('resumeHint'));
    }

    #[TestDox('輸出沒有收尾摘要（超時被硬砍）：以最後一行進度的帳號 id + 1 為起點，把可能只做一半的那位含進下次')]
    public function test_resume_cursor_falls_back_to_last_progress_line_when_killed(): void
    {
        $output = "進度: 1/65000, 帳號id: 919616, 用戶: a (1), 遊戲帳號: x, 轉回金額(平台幣): 10\n進度: 2/65000, 帳號id: 919614, 用戶: b (2), 遊戲帳號: y, 轉回金額(平台幣): 5\nApp\\Jobs\\RunAdminOperationJob has timed out.";

        $this->assertSame(919615, TransferMoneyBack::resumeCursorFromOutput($output));
    }

    #[TestDox('遊戲商下拉只列轉帳錢包，單一錢包遊戲商不出現')]
    public function test_vendor_options_exclude_single_wallet_vendors(): void
    {
        if (!Vendor::find(VendorIdEnum::CLOTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::CLOTSW->value, 'name' => 'CLOT PLAY SW']);
        }
        Vendor::where('id', VendorIdEnum::CLOTSW->value)->update(['is_single_wallet' => 1]);

        $page = Livewire::test(TransferMoneyBack::class)->instance();
        assert($page instanceof TransferMoneyBack);
        $select = $page->getSchema('form')?->getComponent('vendor_id');
        assert($select instanceof Select);
        $options = $select->getOptions();

        $this->assertArrayHasKey(VendorIdEnum::MEGA888->value, $options);
        $this->assertArrayNotHasKey(VendorIdEnum::CLOTSW->value, $options);
    }

    #[TestDox('代理下拉只列有開通該遊戲商的代理')]
    public function test_agent_options_are_limited_to_agents_with_the_vendor(): void
    {
        $unrelatedAgent = Agent::factory()->create();

        $component = Livewire::test(TransferMoneyBack::class)
            ->set('data.vendor_id', VendorIdEnum::MEGA888->value);

        $page = $component->instance();
        assert($page instanceof TransferMoneyBack);
        $select = $page->getSchema('form')?->getComponent('agent_id');
        assert($select instanceof Select);
        $options = $select->getOptions();

        $this->assertArrayHasKey($this->agent->id, $options);
        $this->assertArrayNotHasKey($unrelatedAgent->id, $options);
    }
}
