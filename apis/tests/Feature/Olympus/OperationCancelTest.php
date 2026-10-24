<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Enums\UserStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Filament\Admin\Pages\Operations\CreateVendorAccount;
use App\Filament\Admin\Pages\Operations\TransferMoneyBack;
use App\Models\AdminOperationLog;
use App\Models\QueueJobRun;
use App\Models\User;
use App\Services\AdminOperationService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 系統操作頁「中斷」：worker 被硬砍後紀錄卡在執行中，操作者不必等 retry_after 就能解鎖。
 *
 * php artisan test tests/Feature/Olympus/OperationCancelTest.php --compact
 */
class OperationCancelTest extends TestCase
{
    private User $user;

    private AdminOperationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(AdminOperationService::class);
        $this->user = User::create([
            'parent' => 0,
            'username' => 'ops_cancel_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
        ]);
        $this->actingAs($this->user, 'admin');
    }

    #[TestDox('卡在執行中的紀錄可中斷（標記為失敗）：操作紀錄與執行列都收尾，已回寫的進度保留、人工註記接在後面')]
    public function test_cancel_marks_stuck_run_as_failed_and_keeps_progress(): void
    {
        $log = $this->stuckRun("進度: 1810/5274, 帳號id: 437250\n");

        $this->assertTrue($this->service->cancel($log, $this->user->id));

        $log->refresh();
        $this->assertSame(AdminOperationStatusEnum::FAILED, $log->status);
        $this->assertNotNull($log->finished_at);

        $run = QueueJobRun::find($log->queue_job_run_id);
        $this->assertSame(QueueJobRunStatusEnum::FAILED->value, $run->status->value);
        // 續跑起點靠最後一行進度解析，註記不得蓋掉它
        $this->assertStringContainsString('帳號id: 437250', $run->output);
        $this->assertStringContainsString('手動中斷', $run->output);
        $this->assertStringContainsString($this->user->username, $run->output);
    }

    #[TestDox('已收尾的紀錄不受影響：回傳 false、狀態不變')]
    public function test_cancel_does_not_touch_finished_run(): void
    {
        $log = $this->service->audit(
            AdminOperationEnum::TransferMoneyBack,
            userId: $this->user->id,
            params: [],
            output: '處理完成：共 3 筆',
        );

        $this->assertFalse($this->service->cancel($log, $this->user->id));
        $this->assertSame(AdminOperationStatusEnum::SUCCESS, $log->refresh()->status);
    }

    #[TestDox('中斷後同一操作立刻可以再派發，不必等 staleSeconds 或 retry_after')]
    public function test_cancel_unblocks_next_dispatch(): void
    {
        $log = $this->stuckRun();

        // 未取消前：防重複擋下，不建立新紀錄
        $blocked = $this->service->dispatch(AdminOperationEnum::TransferMoneyBack, [], $this->user->id);
        $this->assertNull($blocked['log']);
        $this->assertSame($log->id, $blocked['blocking']->id);

        $this->service->cancel($log, $this->user->id);

        $allowed = $this->service->dispatch(AdminOperationEnum::TransferMoneyBack, [], $this->user->id);
        $this->assertNull($allowed['blocking']);
        $this->assertNotNull($allowed['log']);
    }

    #[TestDox('轉回餘額頁（指令會讀中斷旗標）：心跳還在跳也給按，按下只寫旗標、狀態留給指令自己收尾')]
    public function test_page_cancel_action_requests_graceful_stop_while_alive(): void
    {
        $log = $this->stuckRun();

        // 執行列剛建立，心跳＝現在 → 進程還活著，但這支指令停得下來，照樣給按
        Livewire::test(TransferMoneyBack::class)
            ->assertActionVisible('cancel')
            ->callAction('cancel');

        $log->refresh();
        $this->assertNotNull($log->cancel_requested_at);
        // 指令自己跑到安全點才收尾，這裡不能先把它標成失敗
        $this->assertSame(AdminOperationStatusEnum::RUNNING, $log->status);
    }

    #[TestDox('轉回餘額頁：心跳斷了代表沒人會來讀旗標，按下直接標為失敗，收尾後按鈕消失')]
    public function test_page_cancel_action_marks_failed_after_heartbeat_lost(): void
    {
        $log = $this->stuckRun();

        // 超過心跳門檻沒有任何寫入 → 研判進程已死
        $this->travel(3)->minutes();

        Livewire::test(TransferMoneyBack::class)
            ->assertActionVisible('cancel')
            ->callAction('cancel');

        $this->assertSame(AdminOperationStatusEnum::FAILED, $log->refresh()->status);
        $this->assertNull($log->cancel_requested_at);

        // 收尾後按鈕消失
        Livewire::test(TransferMoneyBack::class)->assertActionHidden('cancel');
    }

    #[TestDox('其他操作頁不受影響：指令讀不到旗標，心跳還在跳時仍不給按')]
    public function test_page_cancel_action_stays_hidden_for_pages_without_cancel_signal(): void
    {
        $this->stuckRun(operation: AdminOperationEnum::CreateVendorAccount);

        Livewire::test(CreateVendorAccount::class)->assertActionHidden('cancel');

        $this->travel(3)->minutes();

        Livewire::test(CreateVendorAccount::class)->assertActionVisible('cancel');
    }

    /**
     * 造一筆「已派發、worker 取走後被硬砍」的紀錄：狀態停在執行中，執行列留著半路的輸出。
     */
    private function stuckRun(string $streamedOutput = '', AdminOperationEnum $operation = AdminOperationEnum::TransferMoneyBack): AdminOperationLog
    {
        $log = $this->service->dispatch(
            $operation,
            ['--vendorId' => (string) VendorIdEnum::MEGA888->value],
            $this->user->id,
        )['log'];

        $log->forceFill([
            'status' => AdminOperationStatusEnum::RUNNING->value,
            'started_at' => now(),
        ])->save();

        if ($streamedOutput !== '') {
            QueueJobRun::streamOutput($log->queue_job_run_id, $streamedOutput);
        }

        return $log;
    }
}
