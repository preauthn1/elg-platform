<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WalletEnum;
use App\Models\MemberWallet;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PaymentService 錢包建立測試
 *
 * 原本守 ensureGameWalletExists()（金流入帳前預建 type=2 遊戲錢包）。
 * type=2 已廢除（決議⑬）、預建步驟隨之移除，本檔改守兩件事：
 * 預建方法不得被加回來，以及入金路徑的源頭（getOrCreateWallet）對
 * type=2 只查不建——存量清除（另案）後不會再長回來。
 *
 * php artisan test tests/Unit/Services/PaymentServiceWalletTest.php
 */
class PaymentServiceWalletTest extends TestCase
{
    public function test_pre_create_step_is_removed(): void
    {
        // ensureGameWalletExists 曾是入金路徑自動建 type=2 的來源；
        // revert 或重新引入同名方法時本測試轉紅，提醒回頭看決議⑬。
        $this->assertFalse(
            method_exists(PaymentService::class, 'ensureGameWalletExists'),
            'type=2 已廢除，入金前預建遊戲錢包的步驟不得加回來'
        );
    }

    public function test_deposit_source_no_longer_creates_game_wallet_for_multi_wallet_agent(): void
    {
        // Arrange：多錢包代理的會員，尚無遊戲錢包
        $agent = $this->createAgentAndDomain();
        $this->createAgentSetting($agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        Cache::flush();
        $member = $this->createMember($agent);

        // Act：入金路徑取錢包的源頭，對已廢除類型只查不建
        $wallet = (new WalletService)->getOrCreateWallet($member->id, WalletEnum::CODE_GAME);

        // Assert
        $this->assertNull($wallet);
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);
    }

    public function test_existing_wallet_balance_is_untouched(): void
    {
        // Arrange：存量 type=2 錢包還在（清除前的過渡期）
        $agent = $this->createAgentAndDomain();
        $this->createAgentSetting($agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        Cache::flush();
        $member = $this->createMember($agent);
        $wallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 777,
        ]);

        // Act：存量照樣讀得到、餘額原封不動
        $found = (new WalletService)->getOrCreateWallet($member->id, WalletEnum::CODE_GAME);

        // Assert
        $this->assertSame($wallet->id, $found?->id);
        $this->assertSame(1, MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->count());
        $this->assertEquals(777, $wallet->fresh()->balance);
    }
}
