<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\Wallet\SyncMemberBalanceToWalletJob;
use App\Services\WalletService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 雙寫開關測試
 *
 * php artisan test tests/Unit/Services/WalletDualWriteTest.php
 */
class WalletDualWriteTest extends TestCase
{
    public function test_dual_write_is_disabled_by_default()
    {
        $this->assertFalse((new WalletService)->isDualWriteEnabled());
    }

    public function test_no_job_dispatched_when_switch_is_off()
    {
        // Arrange
        Queue::fake();
        config(['custom.multi_currency_wallet.dual_write' => false]);

        // Act
        (new WalletService)->dispatchShadowSync(123);

        // Assert
        Queue::assertNothingPushed();
    }

    public function test_job_dispatched_when_switch_is_on()
    {
        // Arrange
        Queue::fake();
        config(['custom.multi_currency_wallet.dual_write' => true]);

        // Act
        (new WalletService)->dispatchShadowSync(123);

        // Assert
        Queue::assertPushed(SyncMemberBalanceToWalletJob::class, 1);
    }

    public function test_member_balance_change_dispatches_shadow_sync()
    {
        // Arrange：掛點在 MemberObserver::updated——單點涵蓋全部 Eloquent 寫入路徑
        Queue::fake();
        config(['custom.multi_currency_wallet.dual_write' => true]);
        $member = $this->createMember($this->createAgentAndDomain());

        // Act
        $member->balance = '66.0000';
        $member->save();

        // Assert：有派、且 payload 帶對會員（observer 誤傳別的 id 時要紅）
        Queue::assertPushed(
            fn (SyncMemberBalanceToWalletJob $job) => $job->memberId === $member->id
        );
    }

    public function test_increment_also_dispatches_shadow_sync()
    {
        // Arrange：increment 是生產最高頻的餘額寫入形狀（單一錢包注單結算、
        // 兩支 app/SP 派彩都用它）——釘住它也觸發 model updated 事件
        Queue::fake();
        config(['custom.multi_currency_wallet.dual_write' => true]);
        $member = $this->createMember($this->createAgentAndDomain());

        // Act
        $member->increment('balance', 10);

        // Assert
        Queue::assertPushed(
            fn (SyncMemberBalanceToWalletJob $job) => $job->memberId === $member->id
        );
    }

    public function test_non_balance_change_does_not_dispatch()
    {
        // Arrange
        Queue::fake();
        config(['custom.multi_currency_wallet.dual_write' => true]);
        $member = $this->createMember($this->createAgentAndDomain());

        // Act：動別的欄位不派
        $member->nickname = 'shadow-probe';
        $member->save();

        // Assert
        Queue::assertNothingPushed();
    }
}
