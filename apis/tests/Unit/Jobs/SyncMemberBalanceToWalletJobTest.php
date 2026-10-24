<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\CurrencyEnum;
use App\Enums\WalletEnum;
use App\Jobs\Wallet\SyncMemberBalanceToWalletJob;
use App\Models\Agent;
use App\Models\MemberWallet;
use App\Models\Organization;
use Tests\TestCase;

/**
 * 影子同步 Job 測試
 *
 * php artisan test tests/Unit/Jobs/SyncMemberBalanceToWalletJobTest.php
 */
class SyncMemberBalanceToWalletJobTest extends TestCase
{
    /** 建立掛在帶幣別組織下的代理（測試庫 organizations 為空表，須自行佈置） */
    private function createAgentWithOrganization(int $currencyId): Agent
    {
        $organization = Organization::create([
            'name' => 'org-' . bin2hex(random_bytes(6)),
            'currency_id' => $currencyId,
            'memo' => '',
        ]);

        return $this->createAgentAndDomain(['organization_id' => $organization->id]);
    }

    public function test_it_syncs_member_balance_into_main_wallet()
    {
        // Arrange
        $agent = $this->createAgentWithOrganization(CurrencyEnum::MYR->value);
        $member = $this->createMember($agent);
        $member->balance = '1234.5678';
        $member->save();

        // Act
        (new SyncMemberBalanceToWalletJob($member->id))->handle();

        // Assert：一般錢包被建立、幣別為組織幣別、餘額 4 位補零到 10 位不失真
        $wallet = MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_PC)
            ->sole();

        $this->assertSame(CurrencyEnum::MYR->value, $wallet->currency_id);
        $this->assertSame('1234.5678000000', $wallet->balance);
    }

    public function test_it_is_idempotent()
    {
        // Arrange
        $agent = $this->createAgentWithOrganization(CurrencyEnum::MYR->value);
        $member = $this->createMember($agent);
        $member->balance = '500.0000';
        $member->save();

        // Act：跑兩次
        (new SyncMemberBalanceToWalletJob($member->id))->handle();
        (new SyncMemberBalanceToWalletJob($member->id))->handle();

        // Assert：只有一筆、餘額是覆寫不是累加（不會變 1000）、幣別不漂移
        $wallet = MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_PC)
            ->sole();

        $this->assertSame('500.0000000000', $wallet->balance);
        $this->assertSame(CurrencyEnum::MYR->value, $wallet->currency_id);
    }

    public function test_it_resyncs_latest_balance_on_rerun()
    {
        // Arrange
        $agent = $this->createAgentWithOrganization(CurrencyEnum::MYR->value);
        $member = $this->createMember($agent);
        $member->balance = '100.0000';
        $member->save();
        (new SyncMemberBalanceToWalletJob($member->id))->handle();

        // Act：真相（members.balance）變了之後重跑
        $member->balance = '75.2500';
        $member->save();
        (new SyncMemberBalanceToWalletJob($member->id))->handle();

        // Assert：影子跟上最新真相（覆寫語意——真相永遠在 members.balance）
        $this->assertSame('75.2500000000', MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_PC)
            ->value('balance'));
    }

    public function test_it_does_nothing_when_member_is_missing()
    {
        // Act：不存在的會員不應拋錯，也不該建錢包
        (new SyncMemberBalanceToWalletJob(999999999))->handle();

        // Assert
        $this->assertSame(0, MemberWallet::query()
            ->where('member_id', 999999999)
            ->count());
    }

    public function test_member_without_organization_syncs_with_currency_zero()
    {
        // Arrange：六站實測有 organization_id 懸空與幣別未設定的組織——
        // 幣別解析回 0（sentinel），同步照做不擋（餘額比對是影子資料的目的，幣別可後補）
        $member = $this->createMember($this->createAgentAndDomain());
        $member->balance = '88.0000';
        $member->save();

        // Act
        (new SyncMemberBalanceToWalletJob($member->id))->handle();

        // Assert
        $wallet = MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_PC)
            ->sole();

        $this->assertSame(0, $wallet->currency_id);
        $this->assertSame('88.0000000000', $wallet->balance);
    }
}
