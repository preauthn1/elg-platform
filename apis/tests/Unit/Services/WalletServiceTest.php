<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\CurrencyEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WalletService 測試
 *
 * php artisan test tests/Unit/Services/WalletServiceTest.php
 */
class WalletServiceTest extends TestCase
{
    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP,
            'balance' => 0,
        ]);
    }

    public function test_get_single_wallet_balance_by_member()
    {
        // Arrange
        $vendorInfo = new VendorInfo;
        $vendorInfo->rate = 1.0;

        $this->member->balance = 1000;
        $this->member->save();

        // Act
        $balance = (new WalletService)->getSingleWalletBalanceByMember($vendorInfo, $this->member);

        // Assert
        $this->assertEquals(1000, $balance);
    }

    public function test_get_single_wallet_balance_by_member_with_rolling_chips()
    {
        // Arrange
        $vendorInfo = new VendorInfo;
        $vendorInfo->rate = 1.0;
        $vendorInfo->rolling_chip_status = 1;

        $this->member->balance = 1000;
        $this->member->save();

        $this->rollingChipWallet->balance = 1000;
        $this->rollingChipWallet->save();

        // Act
        $balance = (new WalletService)->getSingleWalletBalanceByMember($vendorInfo, $this->member);

        // Assert
        $this->assertEquals(2000, $balance);
    }

    /**
     * 進交易後第一個 SQL 必須是鎖 members 主鍵列，不能先 lazy load agent／活動狀態：
     * RR 下快照定在第一次一般讀，先讀再等鎖會讓鎖後的一般讀看到舊照片（target/tickets#4294）。
     */
    public function test_get_member_locked_wallets_locks_member_row_before_any_plain_read()
    {
        $vendorInfo = new VendorInfo;
        $vendorInfo->rolling_chip_status = 1;
        $freshMember = Member::query()->findOrFail($this->member->id); // 未載入任何 relation

        DB::enableQueryLog();
        DB::flushQueryLog();
        $walletDto = DB::transaction(fn () => (new WalletService)->getMemberLockedWallets($freshMember, $vendorInfo));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        $firstSql = strtolower($queries[0]['query']);
        $this->assertStringContainsString('from `members`', $firstSql);
        $this->assertStringEndsWith('for update', $firstSql);
        $this->assertSame($this->member->id, $walletDto->wallet->id);
        $this->assertSame($this->rollingChipWallet->id, $walletDto->rollingChipWallet->id);
    }

    public function test_get_or_create_wallet_creates_when_absent()
    {
        // Arrange：該會員沒有活動錢包
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
        ]);

        // Act
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_WALLET
        );

        // Assert
        $this->assertSame(WalletEnum::CODE_EVENT_WALLET, $wallet->wallet_type_id);
        $this->assertEquals(0, $wallet->balance);
        $this->assertSame(1, $wallet->status);
        $this->assertDatabaseHas('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
        ]);
    }

    public function test_get_or_create_wallet_returns_existing_without_duplicating()
    {
        // Arrange：禮碼錢包已在 setUp 建好，餘額改成 500、狀態欄位改成非預設值
        $this->rollingChipWallet->balance = 500;
        $this->rollingChipWallet->first_deposit = '2026-08-01 00:00:00';
        $this->rollingChipWallet->status = 2;
        $this->rollingChipWallet->admin = 99;
        $this->rollingChipWallet->save();

        // Act
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_ROLLING_CHIP
        );

        // Assert：拿到同一筆、沒有建出第二筆
        $this->assertSame($this->rollingChipWallet->id, $wallet->id);
        $this->assertEquals(500, $wallet->balance);
        $this->assertSame(1, MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->count());

        // Assert：建立參數（values）只在新建時生效，既有列的欄位一律不得被覆寫
        $this->assertSame('2026-08-01 00:00:00', $wallet->first_deposit);
        $this->assertSame(2, $wallet->status);
        $this->assertSame(99, $wallet->admin);
    }

    public function test_member_wallets_table_has_currency_id_column()
    {
        $this->assertTrue(Schema::hasColumn('member_wallets', 'currency_id'));
    }

    public function test_new_wallet_defaults_currency_id_to_zero()
    {
        // Arrange & Act
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_WALLET
        );

        // Assert：Task 6 只加欄位，尚未帶幣別，預設為 sentinel 0。
        // createOrFirst 剛建立的 model 不會帶回 DB default 欄位，須 refresh 後斷言。
        $this->assertSame(0, $wallet->refresh()->currency_id);
    }

    public function test_get_or_create_wallet_applies_initial_balance_only_on_creation()
    {
        // Act 1：新建時 initialBalance 生效（活動類「建立即帶初始點數」的需求）
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_RANKING,
            initialBalance: '88.5000',
        );

        $this->assertEquals(88.5, $wallet->balance);

        // Act 2：已存在時再帶不同的 initialBalance——既有餘額不得被改
        $again = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_RANKING,
            initialBalance: '999',
        );

        $this->assertSame($wallet->id, $again->id);
        $this->assertEquals(88.5, $again->fresh()->balance, '錢包已存在時 initialBalance 不得覆蓋既有餘額');
    }

    public function test_get_locked_wallet_creates_when_absent_inside_transaction()
    {
        // Arrange：該會員沒有活動錢包（原本用 CODE_SHOP，1004 已列廢除型別改為只查不建，
        // 「查無建立」的正常路徑改以保留型別驗證）
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
        ]);

        // Act：getLockedWallet 需在交易內才能上鎖（鎖本身無法在單連線測試中斷言）
        $wallet = DB::transaction(fn (): ?MemberWallet => (new WalletService)->getLockedWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_WALLET
        ));

        // Assert
        $this->assertSame(WalletEnum::CODE_EVENT_WALLET, $wallet->wallet_type_id);
        $this->assertEquals(0, $wallet->balance);
        $this->assertDatabaseHas('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
        ]);
    }

    /** 把 setUp 的 agent 掛上一個帶幣別的組織（測試庫 organizations 為空表，須自行佈置） */
    private function attachOrganization(int $currencyId): Organization
    {
        $organization = Organization::create([
            'name' => 'org-' . bin2hex(random_bytes(6)),
            'currency_id' => $currencyId,
            'memo' => '',
        ]);

        $this->agent->organization_id = $organization->id;
        $this->agent->save();
        $this->agent->unsetRelation('organization');
        $this->member->unsetRelation('agent');

        return $organization;
    }

    public function test_wallet_gets_organization_currency_when_not_specified()
    {
        // Arrange：掛上帶幣別的組織（用白名單內的實幣別，不用變體 id）
        $this->attachOrganization(CurrencyEnum::MYR->value);

        // Act：不指定幣別建立貨幣類錢包
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_WALLET
        );

        // Assert：自動解析為組織幣別
        $this->assertSame(CurrencyEnum::MYR->value, $wallet->refresh()->currency_id);
    }

    public function test_non_currency_aware_wallet_is_forced_to_zero()
    {
        // Arrange & Act：排行榜點數不是錢，即使指定幣別也要被強制歸零
        $this->attachOrganization(CurrencyEnum::MYR->value);

        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_RANKING,
            currencyId: CurrencyEnum::SGD->value
        );

        // Assert
        $this->assertSame(0, $wallet->refresh()->currency_id);
    }

    public function test_explicit_currency_id_is_respected_for_currency_aware_wallet()
    {
        // Act：呼叫端明確指定幣別（設計決策 12：依訂單記錄的幣別，不看會員當下狀態）
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_EVENT_WALLET,
            currencyId: CurrencyEnum::SGD->value
        );

        // Assert
        $this->assertSame(CurrencyEnum::SGD->value, $wallet->refresh()->currency_id);
    }

    public function test_member_currency_id_accessor_reads_organization()
    {
        // Arrange
        $this->attachOrganization(CurrencyEnum::MYR->value);

        // Assert：accessor 經 agent->organization 取幣別
        $this->assertSame(CurrencyEnum::MYR->value, $this->member->currency_id);

        // Assert：組織不存在時回 0（六站實測有 organization_id 指向不存在列與幣別未設定的組織）
        $orphan = $this->createMember($this->agent, ['username' => 'orphan-' . bin2hex(random_bytes(6))]);
        $orphan->agent->organization_id = 999999999;
        $orphan->agent->save();
        $orphan->unsetRelation('agent');
        $this->assertSame(0, $orphan->currency_id);
    }

    public function test_existing_wallet_kept_when_resolved_currency_differs()
    {
        // Arrange：既有錢包 currency_id = 0（模擬部署後的全部存量列），組織幣別 = 5
        $this->attachOrganization(CurrencyEnum::MYR->value);
        $this->assertSame(0, $this->rollingChipWallet->refresh()->currency_id);

        // Act：第一期查詢走「會員＋類型」兩元組，不因解析出的幣別與存量列不同而誤建新列
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_ROLLING_CHIP
        );

        // Assert：拿回既有列、幣別不被改寫、沒有第二筆
        $this->assertSame($this->rollingChipWallet->id, $wallet->id);
        $this->assertSame(0, $wallet->refresh()->currency_id);
        $this->assertSame(1, MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->count());
    }

    public function test_get_or_create_wallet_never_creates_deprecated_type()
    {
        // Act：已廢除類型只查不建（決議⑧第四前置：清除存量前先關掉自動建立來源）
        $wallet = (new WalletService)->getOrCreateWallet(
            $this->member->id,
            WalletEnum::CODE_GAME
        );

        // Assert
        $this->assertNull($wallet);
        $this->assertSame(0, MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->count());
    }

    public function test_get_locked_wallet_reads_existing_deprecated_wallet_without_creating()
    {
        // Arrange：存量 type=2 還在（清除前的過渡期）
        $existing = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 250,
        ]);

        // Act：存量讀得到（帶鎖）
        $wallet = DB::transaction(fn (): ?MemberWallet => (new WalletService)->getLockedWallet(
            $this->member->id,
            WalletEnum::CODE_GAME
        ));

        // Assert
        $this->assertSame($existing->id, $wallet?->id);

        // Act & Assert：另一會員無存量——查無回 null、不建立
        $other = $this->createMember($this->agent, ['username' => 'nogame-' . bin2hex(random_bytes(6))]);
        $none = DB::transaction(fn (): ?MemberWallet => (new WalletService)->getLockedWallet(
            $other->id,
            WalletEnum::CODE_GAME
        ));
        $this->assertNull($none);
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $other->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);
    }

    public function test_get_member_all_wallets_does_not_create_deprecated_game_wallet()
    {
        // Arrange：一開始沒有 type=2（getMemberAllWallets 原本無條件建立，是存量再生的主要來源）
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);

        // Act
        $data = (new WalletService)->getMemberAllWallets($this->member);

        // Assert：不建立、回 null
        $this->assertNull($data->gameWallet);
        $this->assertDatabaseMissing('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);
    }

    public function test_get_member_all_wallets_returns_existing_game_wallet()
    {
        // Arrange：存量 type=2 還在時要照樣讀得到（清除前的過渡期）
        $existing = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 0,
        ]);

        // Act
        $data = (new WalletService)->getMemberAllWallets($this->member);

        // Assert
        $this->assertSame($existing->id, $data->gameWallet?->id);
    }

    public function test_get_locked_wallet_returns_existing_without_duplicating()
    {
        // Arrange：禮碼錢包已在 setUp 建好
        $this->rollingChipWallet->balance = 300;
        $this->rollingChipWallet->save();

        // Act：既有錢包走「先 SELECT ... FOR UPDATE」路徑，不經 createOrFirst
        $wallet = DB::transaction(fn (): MemberWallet => (new WalletService)->getLockedWallet(
            $this->member->id,
            WalletEnum::CODE_ROLLING_CHIP
        ));

        // Assert：拿到同一筆、沒有建出第二筆
        $this->assertSame($this->rollingChipWallet->id, $wallet->id);
        $this->assertEquals(300, $wallet->balance);
        $this->assertSame(1, MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->count());
    }
}
