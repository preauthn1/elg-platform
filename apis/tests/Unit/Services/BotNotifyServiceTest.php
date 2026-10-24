<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Facades\BotNotify;
use App\Services\BotNotifyService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesTelegramDispatch;
use Tests\TestCase;

/**
 * BotNotifyService 兩種送法的派發時機
 *
 *  - send()：交易結束後派發，commit 或 rollBack 都派發；rollBack 那次開頭加上已回滾註記
 *  - sendOnCommit()：只在 commit 後派發，rollBack 時整則丟掉
 *
 * FakesTelegramDispatch 只關掉 testing 早退，這裡驗的是真的 deliver() 路徑：
 * 派發時機的旗標要從送法一路傳到 telegram()，漏傳一處這裡就會失敗。
 *
 * php artisan test tests/Unit/Services/BotNotifyServiceTest.php
 */
class BotNotifyServiceTest extends TestCase
{
    use FakesTelegramDispatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeTelegramDispatch();
    }

    public function test_send_dispatches_immediately_without_transaction(): void
    {
        BotNotify::send('send-without-transaction');

        $alerts = $this->dispatchedTelegramAlerts('send-without-transaction');
        $this->assertCount(1, $alerts);
        $this->assertStringNotContainsString(BotNotifyService::ROLLED_BACK_NOTICE, $alerts->first()->message);
    }

    public function test_send_dispatches_once_after_rollback_with_notice(): void
    {
        DB::beginTransaction();
        BotNotify::send('send-then-rollback');

        // 交易結束前不派發
        $this->assertCount(0, $this->dispatchedTelegramAlerts('send-then-rollback'));

        DB::rollBack();

        $alerts = $this->dispatchedTelegramAlerts('send-then-rollback');
        $this->assertCount(1, $alerts);
        // 已回滾註記在最前面；站台前綴與訊息本體仍由真的 deliver() 產生，收件人取預設頻道的 chat id
        $this->assertStringStartsWith(BotNotifyService::ROLLED_BACK_NOTICE, $alerts->first()->message);
        $this->assertStringEndsWith('] send-then-rollback', $alerts->first()->message);
        $this->assertSame('test-chat', $alerts->first()->chatId);
    }

    public function test_send_dispatches_once_after_commit_without_notice(): void
    {
        DB::beginTransaction();
        BotNotify::send('send-then-commit');
        DB::commit();

        $alerts = $this->dispatchedTelegramAlerts('send-then-commit');
        $this->assertCount(1, $alerts);
        $this->assertStringNotContainsString(BotNotifyService::ROLLED_BACK_NOTICE, $alerts->first()->message);
    }

    public function test_send_on_commit_drops_notification_when_transaction_rolls_back(): void
    {
        DB::beginTransaction();
        BotNotify::sendOnCommit('send-on-commit-then-rollback');
        DB::rollBack();

        $this->assertCount(0, $this->dispatchedTelegramAlerts('send-on-commit-then-rollback'));
    }

    public function test_send_on_commit_dispatches_after_commit(): void
    {
        DB::beginTransaction();
        BotNotify::sendOnCommit('send-on-commit-then-commit');
        DB::commit();

        $this->assertCount(1, $this->dispatchedTelegramAlerts('send-on-commit-then-commit'));
    }
}
