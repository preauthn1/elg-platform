<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Enums\CashFlowEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 永久地址模式：存款方式清單（deposit/top-up-types、deposit/types）替還沒有地址的會員自動取址，
 * 會員點選支付通道時也能主動取址（deposit/crypto-address），以及建單端點對這類支付通道的擋門。
 *
 * php artisan test tests/Feature/Frontend/Deposit/DepositCryptoAddressTest.php
 */
class DepositCryptoAddressTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private Member $member;

    private string $loginToken = '';

    private OrderType $cryptoOrderType;

    private AgentCashFlowOrderType $cryptoChannel;

    private OrderType $bankOrderType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_addr_' . uniqid()]);
        $this->loginToken = [REDACTED];

        $this->agent->cash_flow_group_id = 1;
        $this->agent->save();

        // 幣與鏈由支付通道的 order type key 決定，coinId 依幣別代號向假的 getCoinList 查；商戶 info 只放憑證
        [$this->cryptoOrderType, $this->cryptoChannel] = $this->createChannel(
            cashFlowId: 129,
            thirdPartyCode: 'D_CCPAYMENT',
            info: [
                'API_URL' => self::API_URL,
                'APP_ID' => 'test_app_id',
                'APP_SECRET' => 'test_app_secret',
            ],
            key: 'CCPAYMENT.USDT_TRX',
        );
        [$this->bankOrderType] = $this->createChannel(cashFlowId: 1, thirdPartyCode: 'D_BANK', info: []);
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{0: OrderType, 1: AgentCashFlowOrderType}
     */
    private function createChannel(int $cashFlowId, string $thirdPartyCode, array $info, string $key = '--'): array
    {
        $cashFlow = CashFlow::create([
            'id' => $cashFlowId,
            'name' => 'Cash flow ' . $cashFlowId,
            'third_party_code' => $thirdPartyCode,
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'status' => 1,
            'info' => $info,
            'admin' => 0,
        ]);

        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'Channel ' . $cashFlowId,
            'type' => 1,
            'status' => 1,
            'key' => $key,
            'info' => '',
            'admin' => 0,
        ]);

        $channel = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'status' => 1,
            'settings' => '',
        ]);

        return [$orderType, $channel];
    }

    /**
     * 假的 getCoinList 回應：商戶後台啟用 USDT（coinId 1280）在 TRX 鏈
     *
     * @return array<string, mixed>
     */
    private function coinListBody(): array
    {
        return [
            'code' => 10000,
            'msg' => 'success',
            'data' => ['coins' => [
                ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
            ]],
        ];
    }

    private function requestAddress(int $orderTypeId): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/deposit/crypto-address',
            $this->encryptParams(['order_type_id' => $orderTypeId]),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    private function createOrder(int $orderTypeId): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/deposit/create-order',
            $this->encryptParams([
                'order_type_id' => $orderTypeId,
                'amount' => 30,
                'domain' => 'example.invalid',
            ]),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /** 存款方式清單（平面）：deposit/top-up-types */
    private function listTopUpTypes(): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/deposit/top-up-types',
            $this->encryptParams([]),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /** 存款方式清單（依類型分組）：deposit/types */
    private function listDepositTypes(): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/deposit/types',
            $this->encryptParams([]),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /**
     * 從清單回應找出指定支付通道那一列；top-up-types 的 data 是平面陣列，types 的 data 是 {type, items} 分組。
     *
     * @return array<string, mixed>
     */
    private function findListItem(TestResponse $response, int $orderTypeId): array
    {
        $items = collect($response->json('data'))->flatMap(
            fn (array $row): array => array_key_exists('items', $row) ? $row['items'] : [$row]
        );
        $item = $items->firstWhere('order_type_id', $orderTypeId);

        $this->assertNotNull($item, sprintf('order type %d is missing from the listing', $orderTypeId));

        return $item;
    }

    #[Group('rules/provider-ccpayment#R-3')]
    public function test_fetches_address_from_provider_and_stores_it_when_member_has_none(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TXYZabc', 'memo' => ''],
            ]),
        ]);

        $response = $this->requestAddress($this->cryptoOrderType->id);

        $response->assertStatus(200);
        $this->assertSame('TXYZabc', $response->json('data.crypto_wallet_address'));
        $this->assertDatabaseHas('member_crypto_wallets', [
            'id' => $response->json('data.crypto_wallet_id'),
            'agent_cash_flow_order_type_id' => $this->cryptoChannel->id,
            'member_id' => $this->member->id,
            'address' => 'TXYZabc',
            'status' => 1,
        ]);
    }

    #[Group('rules/provider-ccpayment#R-3')]
    public function test_returns_existing_address_when_provider_confirms_it(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TExisting', 'memo' => ''],
            ]),
        ]);
        $existing = MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $this->cryptoChannel->id,
            'member_id' => $this->member->id,
            'address' => 'TExisting',
            'status' => 1,
            'balance' => 0,
            'admin' => 0,
        ]);

        $response = $this->requestAddress($this->cryptoOrderType->id);

        $response->assertStatus(200);
        $this->assertSame($existing->id, $response->json('data.crypto_wallet_id'));
        $this->assertSame('TExisting', $response->json('data.crypto_wallet_address'));
        $this->assertSame(1, MemberCryptoWallet::where('member_id', $this->member->id)->count());
    }

    #[Group('rules/provider-ccpayment#R-3')]
    public function test_rejects_channel_that_does_not_bind_a_crypto_wallet(): void
    {
        Http::fake();

        $this->requestAddress($this->bankOrderType->id)->assertStatus(400);

        Http::assertNothingSent();
    }

    #[Group('rules/provider-ccpayment#R-4')]
    public function test_create_order_is_refused_for_crypto_wallet_channel_and_creates_no_order(): void
    {
        Http::fake();

        $this->createOrder($this->cryptoOrderType->id)->assertStatus(400);

        $this->assertDatabaseMissing('orders', ['member_id' => $this->member->id]);
        Http::assertNothingSent();
    }

    // ---- 存款方式清單自動取址（開儲值頁就取址，不必等 App 點選） ----

    #[Group('rules/provider-ccpayment#R-1')]
    public function test_top_up_types_fetches_permanent_address_for_member_without_one(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TListed', 'memo' => ''],
            ]),
        ]);

        $response = $this->listTopUpTypes();

        $response->assertStatus(200);
        $item = $this->findListItem($response, $this->cryptoOrderType->id);
        $this->assertSame('TListed', $item['crypto_wallet_address']);
        $this->assertDatabaseHas('member_crypto_wallets', [
            'id' => $item['crypto_wallet_id'],
            'agent_cash_flow_order_type_id' => $this->cryptoChannel->id,
            'member_id' => $this->member->id,
            'address' => 'TListed',
            'status' => 1,
        ]);
        // 一次 getCoinList（查 coinId）加一次取址
        Http::assertSentCount(2);
    }

    #[Group('rules/provider-ccpayment#R-1')]
    public function test_deposit_types_fetches_permanent_address_for_member_without_one(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TGrouped', 'memo' => ''],
            ]),
        ]);

        $response = $this->listDepositTypes();

        $response->assertStatus(200);
        $item = $this->findListItem($response, $this->cryptoOrderType->id);
        $this->assertSame('TGrouped', $item['crypto_wallet_address']);
        $this->assertDatabaseHas('member_crypto_wallets', [
            'id' => $item['crypto_wallet_id'],
            'agent_cash_flow_order_type_id' => $this->cryptoChannel->id,
            'member_id' => $this->member->id,
            'address' => 'TGrouped',
            'status' => 1,
        ]);
        // 一次 getCoinList（查 coinId）加一次取址
        Http::assertSentCount(2);
    }

    #[Group('rules/provider-ccpayment#R-2')]
    public function test_listing_keeps_other_channels_and_leaves_address_empty_when_provider_fails(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response('', 500),
        ]);

        $response = $this->listTopUpTypes();

        $response->assertStatus(200);
        $crypto = $this->findListItem($response, $this->cryptoOrderType->id);
        $this->assertSame(0, $crypto['crypto_wallet_id']);
        $this->assertSame('', $crypto['crypto_wallet_address']);
        $this->findListItem($response, $this->bankOrderType->id);
        $this->assertDatabaseMissing('member_crypto_wallets', ['member_id' => $this->member->id]);

        // 失敗後同一支付通道冷卻：再開清單不再向金流商取址，避免金流商故障時每次開儲值頁都等到逾時
        // （第一次清單打了 getCoinList 與取址兩支，第二次零請求）
        $this->listTopUpTypes()->assertStatus(200);
        Http::assertSentCount(2);
    }

    /**
     * 商戶換了 APP_ID：表裡的地址屬於舊商戶，清單要顯示目前商戶回的地址，舊列改停用保留
     */
    #[Group('rules/provider-ccpayment#R-1')]
    public function test_listing_replaces_address_from_previous_merchant(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TNewMerchant', 'memo' => ''],
            ]),
        ]);
        $old = MemberCryptoWallet::create([
            'agent_cash_flow_order_type_id' => $this->cryptoChannel->id,
            'member_id' => $this->member->id,
            'address' => 'TOldMerchant',
            'status' => 1,
            'balance' => 0,
            'admin' => 0,
        ]);

        $response = $this->listTopUpTypes();

        $response->assertStatus(200);
        $item = $this->findListItem($response, $this->cryptoOrderType->id);
        $this->assertNotSame($old->id, $item['crypto_wallet_id']);
        $this->assertSame('TNewMerchant', $item['crypto_wallet_address']);
        $this->assertDatabaseHas('member_crypto_wallets', ['id' => $old->id, 'address' => 'TOldMerchant', 'status' => 0]);
    }

    public function test_listing_does_not_fetch_for_address_pool_channel(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TExisting', 'memo' => ''],
            ]),
        ]);
        // 地址池模式（FonPay）：地址由營運預建再撥給會員，清單不向金流商取址
        [$poolOrderType] = $this->createChannel(cashFlowId: CashFlowEnum::DEPOSIT_FON_PAY, thirdPartyCode: 'D_FONPAY', info: []);

        $response = $this->listTopUpTypes();

        $response->assertStatus(200);
        $item = $this->findListItem($response, $poolOrderType->id);
        $this->assertSame(0, $item['crypto_wallet_id']);
        $this->assertSame('', $item['crypto_wallet_address']);
        // 只打了 CCPayment 通道的確認，地址池通道沒有任何請求
        Http::assertNotSent(fn ($request): bool => !str_starts_with($request->url(), self::API_URL));
    }
}
