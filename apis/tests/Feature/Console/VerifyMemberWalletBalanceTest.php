<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\WalletEnum;
use App\Models\MemberWallet;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 錢包餘額每日校驗測試
 *
 * php artisan test tests/Feature/Console/VerifyMemberWalletBalanceTest.php
 */
class VerifyMemberWalletBalanceTest extends TestCase
{
    #[Group('rules/currency-and-wallet-currency#R-15')]
    public function test_it_reports_no_diff_when_balances_match()
    {
        // Arrange（幣別與校驗無關，factory 直接給 0——不經 organization，測試庫該表為空）
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $member->balance = '100.0000';
        $member->save();

        MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_PC,
            'balance' => '100.0000000000',
        ]);

        // Act & Assert
        $this->artisan('wallet:verify-balance', ['--member-id' => $member->id])
            ->expectsOutputToContain('差異 0 筆')
            ->assertExitCode(0);
    }

    #[Group('rules/currency-and-wallet-currency#R-15')]
    public function test_it_detects_mismatch()
    {
        // Arrange
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $member->balance = '100.0000';
        $member->save();

        MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_PC,
            'balance' => '99.0000000000',
        ]);

        // Act & Assert
        $this->artisan('wallet:verify-balance', ['--member-id' => $member->id])
            ->expectsOutputToContain('差異 1 筆')
            ->assertExitCode(0);
    }

    #[Group('rules/currency-and-wallet-currency#R-15')]
    public function test_member_without_shadow_wallet_is_not_counted_as_diff()
    {
        // Arrange：影子錢包還沒建（雙寫開關未開啟過），不算差異
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $member->balance = '100.0000';
        $member->save();

        // Act & Assert
        $this->artisan('wallet:verify-balance', ['--member-id' => $member->id])
            ->expectsOutputToContain('差異 0 筆')
            ->assertExitCode(0);
    }
}
