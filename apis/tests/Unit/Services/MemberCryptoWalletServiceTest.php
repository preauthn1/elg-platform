<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Services\MemberCryptoWalletService;
use App\Services\Payment\Contracts\SupportsPermanentDepositAddress;
use App\Services\Payment\Data\PermanentDepositAddress;
use App\Services\PaymentService;
use Tests\TestCase;

/**
 * MemberCryptoWalletService 測試
 *
 * 前半是既有的地址池行為（FonPay 模式）；後半是永久地址模式的「取得或建立」。
 * member_crypto_wallets 是多條支付通道共用的表（鍵為 agent_cash_flow_order_type_id），
 * 所以永久地址的測試都用第二條支付通道塞同樣的列來驗隔離：拔掉服務裡的通道條件，這些測試必須變紅。
 *
 * php artisan test tests/Unit/Services/MemberCryptoWalletServiceTest.php
 */
class MemberCryptoWalletServiceTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private AgentCashFlowOrderType $channel;

    private AgentCashFlowOrderType $otherChannel;

    /**
     * 永久地址測試的共用資料：一位會員、兩條支付通道。
     * 不放 setUp：既有的地址池測試各自建代理，createAgentAndDomain() 同一測試內呼叫兩次會撞網域唯一鍵。
     */
    private function prepareChannels(): void
    {
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'mcw_perm_' . uniqid()]);
        [, , , $this->channel] = $this->createDefaultCashFlow($this->agent, 129, 1);
        [, , , $this->otherChannel] = $this->createDefaultCashFlow($this->agent, 81, 1);
    }

    private function service(): MemberCryptoWalletService
    {
        return app(MemberCryptoWalletService::class);
    }

    // =========================================================================
    // 既有行為：地址池（FonPay 模式）
    // =========================================================================

    public function test_get_all_wallets_returns_only_active_for_member(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mcw_' . uniqid()]);
        $acotId = random_int(10000, 99999);

        MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $acotId,
            'member_id' => $member->id,
            'address' => 'addr-active-' . uniqid(),
            'status' => 1,
            'balance' => 0,
            'admin' => 0,
        ]);
        MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $acotId,
            'member_id' => $member->id,
            'address' => 'addr-inactive-' . uniqid(),
            'status' => 0,
            'balance' => 0,
            'admin' => 0,
        ]);

        $list = $this->service()->getAllWallets($member->id);

        $this->assertCount(1, $list);
        $this->assertSame(1, (int) $list->first()->status);
    }

    public function test_get_wallet_returns_existing_member_wallet(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mcw2_' . uniqid()]);
        $acotId = random_int(10000, 99999);

        $existing = MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $acotId,
            'member_id' => $member->id,
            'address' => 'existing-' . uniqid(),
            'status' => 1,
            'balance' => '1.0000',
            'admin' => 0,
        ]);

        $found = $this->service()->getWallet($acotId, $member->id);

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_get_wallet_assigns_unassigned_pool_wallet(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mcw3_' . uniqid()]);
        $acotId = random_int(10000, 99999);

        $pool = MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $acotId,
            'member_id' => 0,
            'address' => 'pool-' . uniqid(),
            'status' => 1,
            'balance' => 0,
            'admin' => 0,
        ]);

        $found = $this->service()->getWallet($acotId, $member->id);

        $this->assertNotNull($found);
        $this->assertSame($pool->id, $found->id);
        $this->assertSame($member->id, (int) $found->fresh()->member_id);
    }

    public function test_get_wallet_returns_null_when_no_match_and_pool_empty(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mcw4_' . uniqid()]);
        $acotId = random_int(10000, 99999);

        $this->assertNull($this->service()->getWallet($acotId, $member->id));
    }

    // =========================================================================
    // 永久地址模式：取得或建立
    // =========================================================================

    /**
     * 假金流商：依序回傳給定的地址，並記錄解綁呼叫
     *
     * @param  list<string>  $addresses
     */
    private function fakeProvider(array $addresses): object
    {
        $provider = new class($addresses) implements SupportsPermanentDepositAddress
        {
            /** @var list<string> */
            public array $unbound = [];

            public int $calls = 0;

            public string $scope = 'scope-a';

            public bool $fail = false;

            /** @param list<string> $addresses */
            public function __construct(private array $addresses) {}

            public function getOrCreatePermanentDepositAddress(Member $member, AgentCashFlowOrderType $channel): PermanentDepositAddress
            {
                $this->calls++;

                if ($this->fail) {
                    throw new ApiException('provider down');
                }

                return new PermanentDepositAddress((string) array_shift($this->addresses));
            }

            public function permanentDepositAddressScope(): string
            {
                return $this->scope;
            }

            public function unbindPermanentDepositAddress(AgentCashFlowOrderType $channel, string $address): void
            {
                $this->unbound[] = $address;
            }
        };

        $this->mock(PaymentService::class, fn ($mock) => $mock->shouldReceive('getProvider')->andReturn($provider));

        return $provider;
    }

    private function createWallet(AgentCashFlowOrderType $channel, string $address, int $status = 1, ?int $memberId = null): MemberCryptoWallet
    {
        return MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $channel->id,
            'member_id' => $memberId ?? $this->member->id,
            'address' => $address,
            'status' => $status,
            'balance' => 0,
            'admin' => 0,
        ]);
    }

    public function test_keeps_existing_wallet_when_provider_returns_the_same_address(): void
    {
        $this->prepareChannels();
        $existing = $this->createWallet($this->channel, 'TExisting');
        $this->createWallet($this->otherChannel, 'TOther');
        $provider = $this->fakeProvider(['TExisting']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame($existing->id, $wallet->id);
        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, MemberCryptoWallet::where('agent_cash_flow_order_type_id', $this->channel->id)->count());
    }

    /**
     * 商戶換了 APP_ID：金流商回新地址，舊列改停用但保留（回調反查還要用），新地址成為啟用列
     */
    public function test_rotates_wallet_when_provider_returns_a_new_address(): void
    {
        $this->prepareChannels();
        $old = $this->createWallet($this->channel, 'TOldMerchant');
        $this->createWallet($this->otherChannel, 'TOther');
        $this->fakeProvider(['TNewMerchant']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame('TNewMerchant', $wallet->address);
        $this->assertSame(1, $wallet->status);
        $this->assertDatabaseHas('member_crypto_wallets', ['id' => $old->id, 'address' => 'TOldMerchant', 'status' => 0]);
        $this->assertDatabaseHas('member_crypto_wallets', [
            'agent_cash_flow_order_type_id' => $this->otherChannel->id,
            'address' => 'TOther',
            'status' => 1,
        ]);
    }

    public function test_creates_wallet_from_provider_when_member_has_none_on_this_channel(): void
    {
        $this->prepareChannels();
        // 另一條支付通道已有啟用列，不可被當成本通道的地址
        $this->createWallet($this->otherChannel, 'TOther');
        $provider = $this->fakeProvider(['TNew']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame('TNew', $wallet->address);
        $this->assertSame($this->channel->id, $wallet->agent_cash_flow_order_type_id);
        $this->assertSame($this->member->id, $wallet->member_id);
        $this->assertSame(1, $wallet->status);
        $this->assertSame(1, $provider->calls);
        $this->assertDatabaseHas('member_crypto_wallets', [
            'agent_cash_flow_order_type_id' => $this->otherChannel->id,
            'address' => 'TOther',
            'status' => 1,
        ]);
    }

    public function test_disabled_row_is_kept_and_new_address_is_added(): void
    {
        $this->prepareChannels();
        $old = $this->createWallet($this->channel, 'TOld', status: 0);
        $this->fakeProvider(['TNew']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame('TNew', $wallet->address);
        $this->assertNotSame($old->id, $wallet->id);
        $this->assertDatabaseHas('member_crypto_wallets', ['id' => $old->id, 'address' => 'TOld', 'status' => 0]);
    }

    public function test_unbinds_and_refetches_when_provider_returns_a_disabled_address(): void
    {
        $this->prepareChannels();
        $this->createWallet($this->channel, 'TRisky', status: 0);
        $provider = $this->fakeProvider(['TRisky', 'TFresh']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame('TFresh', $wallet->address);
        $this->assertSame(['TRisky'], $provider->unbound);
        $this->assertSame(2, $provider->calls);
    }

    public function test_throws_when_provider_keeps_returning_the_disabled_address(): void
    {
        $this->prepareChannels();
        $this->createWallet($this->channel, 'TRisky', status: 0);
        $this->fakeProvider(['TRisky', 'TRisky']);

        $this->expectException(ApiException::class);

        $this->service()->getOrCreateForChannel($this->member, $this->channel);
    }

    public function test_throws_when_address_belongs_to_another_member(): void
    {
        $this->prepareChannels();
        $other = $this->createMember($this->agent, ['username' => 'mcw_other_' . uniqid()]);
        $this->createWallet($this->channel, 'TTaken', memberId: $other->id);
        $this->fakeProvider(['TTaken']);

        $this->expectException(ApiException::class);

        $this->service()->getOrCreateForChannel($this->member, $this->channel);
    }

    public function test_throws_when_provider_does_not_support_permanent_address(): void
    {
        $this->prepareChannels();
        $this->mock(PaymentService::class, fn ($mock) => $mock->shouldReceive('getProvider')->andReturn(new \stdClass));

        $this->expectException(ApiException::class);

        $this->service()->getOrCreateForChannel($this->member, $this->channel);
    }

    public function test_trims_address_before_saving(): void
    {
        $this->prepareChannels();
        $this->fakeProvider([' TNew ']);

        $wallet = $this->service()->getOrCreateForChannel($this->member, $this->channel);

        $this->assertSame('TNew', $wallet->address);
    }

    // =========================================================================
    // 永久地址模式：存款方式清單（即時確認＋快取）
    // =========================================================================

    private function listingChannels(): \Illuminate\Support\Collection
    {
        return collect([$this->channel->load('agentCashFlow')]);
    }

    public function test_listing_reuses_verified_address_without_calling_provider_again(): void
    {
        $this->prepareChannels();
        $this->createWallet($this->channel, 'TExisting');
        $provider = $this->fakeProvider(['TExisting', 'TShouldNotBeFetched']);

        $first = $this->service()->walletsForChannels($this->member, $this->listingChannels());
        $second = $this->service()->walletsForChannels($this->member, $this->listingChannels());

        $this->assertSame('TExisting', $first->get($this->channel->id)->address);
        $this->assertSame('TExisting', $second->get($this->channel->id)->address);
        $this->assertSame(1, $provider->calls);
    }

    /**
     * 快取鍵含地址歸屬範圍：商戶換了 APP_ID，快取對不到，下一次開清單就向新商戶確認並換發
     */
    public function test_listing_rechecks_and_rotates_when_address_scope_changes(): void
    {
        $this->prepareChannels();
        $old = $this->createWallet($this->channel, 'TOldMerchant');
        $provider = $this->fakeProvider(['TOldMerchant', 'TNewMerchant']);

        $this->service()->walletsForChannels($this->member, $this->listingChannels());
        $provider->scope = 'scope-b';
        $wallets = $this->service()->walletsForChannels($this->member, $this->listingChannels());

        $this->assertSame('TNewMerchant', $wallets->get($this->channel->id)->address);
        $this->assertSame(2, $provider->calls);
        $this->assertDatabaseHas('member_crypto_wallets', ['id' => $old->id, 'status' => 0]);
    }

    /**
     * 確認失敗時連表裡既有的地址也不給：沒確認過的地址可能屬於舊商戶
     */
    public function test_listing_leaves_address_empty_when_provider_fails(): void
    {
        $this->prepareChannels();
        $this->createWallet($this->channel, 'TUnverified');
        $provider = $this->fakeProvider([]);
        $provider->fail = true;

        $wallets = $this->service()->walletsForChannels($this->member, $this->listingChannels());

        $this->assertFalse($wallets->has($this->channel->id));
    }

    /**
     * 冷卻是支付通道層級：別的會員取址失敗觸發冷卻，已確認過地址的會員照樣拿得到
     */
    public function test_listing_cooldown_does_not_hide_verified_address(): void
    {
        $this->prepareChannels();
        $this->createWallet($this->channel, 'TVerified');
        $provider = $this->fakeProvider(['TVerified']);
        $this->service()->walletsForChannels($this->member, $this->listingChannels());

        \Illuminate\Support\Facades\Cache::put(
            \App\CacheKeys\CacheKeys::cryptoAddressFetchCooldown($this->channel->id),
            true,
            60
        );
        $wallets = $this->service()->walletsForChannels($this->member, $this->listingChannels());

        $this->assertSame('TVerified', $wallets->get($this->channel->id)->address);
        $this->assertSame(1, $provider->calls);
    }
}
