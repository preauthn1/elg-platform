<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Contracts\SupportsPermanentDepositAddress;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\CCPayment\ChannelKey;
use App\Services\Payment\Providers\CCPayment\Driver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 代收：永久地址模式的取址與解綁（經 Driver 的契約入口），以及「不建存款單」的守衛。
 *
 * 幣與鏈由支付通道的 order type key（CCPAYMENT.{幣別代號}_{三方鏈代號}）決定，coinId 執行期依幣別代號
 * 向金流商 getCoinList 查（快取一天、找不到先重拉一次）；後台沒啟用的幣或鏈一律拋出、不送請求。
 * 商戶 info 只放憑證；資料檔宣告的每個 key 都必須是這個格式（本檔釘住）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/DepositTest.php
 */
class DepositTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $agentCashFlow;

    private AgentCashFlowOrderType $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_dep_' . uniqid()]);
        [, $this->agentCashFlow, , $this->channel] = $this->createDefaultCashFlow($this->agent, 129, 1);
        $this->setOrderTypeKey('CCPAYMENT.USDT_TRX');

        $this->agentCashFlow->info = [
            'API_URL' => self::API_URL,
            'APP_ID' => 'test_app_id',
            'APP_SECRET' => 'test_app_secret',
        ];
        $this->agentCashFlow->save();
        $this->agentCashFlow = $this->agentCashFlow->fresh();
    }

    /** 支付通道的 order type key 決定幣與鏈；共用 helper 建的 key 是佔位值，測試自己指定 */
    private function setOrderTypeKey(string $key): void
    {
        OrderType::whereKey($this->channel->order_type_id)->update(['key' => $key]);
        $this->channel = $this->channel->fresh();
    }

    private function driver(): Driver
    {
        return new Driver($this->agentCashFlow);
    }

    /**
     * 假的 getCoinList 回應（商戶後台啟用的代幣）。預設 USDT（coinId 1280，TRX 與 ETH 鏈）
     * 與測試幣 TETH（coinId 1891，ETH_SEPOLIA 鏈）。
     *
     * @param  list<array<string, mixed>>|null  $coins
     * @return array<string, mixed>
     */
    private function coinListBody(?array $coins = null): array
    {
        return [
            'code' => 10000,
            'msg' => 'success',
            'data' => ['coins' => $coins ?? [
                ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX'], 'ETH' => ['chain' => 'ETH']]],
                ['coinId' => 1891, 'symbol' => 'TETH', 'status' => 'Normal', 'networks' => ['ETH_SEPOLIA' => ['chain' => 'ETH_SEPOLIA']]],
            ]],
        ];
    }

    private function coinListRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === self::API_URL . '/getCoinList')->count();
    }

    public function test_driver_supports_permanent_deposit_address(): void
    {
        $this->assertInstanceOf(SupportsPermanentDepositAddress::class, $this->driver());
    }

    /**
     * 地址歸屬範圍跟著 APP_ID 走（換 APP_SECRET 不變），而且不含 APP_ID 原值（會進快取鍵）
     */
    public function test_address_scope_changes_with_app_id_only_and_hides_the_raw_value(): void
    {
        $scope = $this->driver()->permanentDepositAddressScope();

        $this->agentCashFlow->info = array_merge($this->agentCashFlow->info, ['APP_SECRET' => 'rotated_secret']);
        $this->assertSame($scope, $this->driver()->permanentDepositAddressScope());

        $this->agentCashFlow->info = array_merge($this->agentCashFlow->info, ['APP_ID' => 'another_app_id']);
        $this->assertNotSame($scope, $this->driver()->permanentDepositAddressScope());
        $this->assertStringNotContainsString('test_app_id', $scope);
    }

    public function test_get_or_create_address_sends_reference_id_chain_and_callback_url(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TXYZabc', 'memo' => ''],
            ]),
        ]);

        $result = $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);

        $this->assertSame('TXYZabc', $result->address);
        $this->assertNull($result->memo);

        $expectedBody = json_encode([
            'referenceId' => $this->channel->id . '-' . $this->member->id,
            'chain' => 'TRX',
            'notifyUrl' => Common::depositCallbackUrl($this->agentCashFlow->id),
        ], JSON_UNESCAPED_SLASHES);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getOrCreateAppDepositAddress'
            && $request->body() === $expectedBody);
    }

    public function test_get_or_create_address_keeps_memo_when_provider_returns_one(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'rXYZabc', 'memo' => '123456'],
            ]),
        ]);

        $result = $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);

        $this->assertSame('123456', $result->memo);
    }

    public function test_get_or_create_address_throws_before_sending_when_chain_is_not_enabled_for_the_coin(): void
    {
        $this->setOrderTypeKey('CCPAYMENT.USDT_UNKNOWN');
        Http::fake([self::API_URL . '/getCoinList' => Http::response($this->coinListBody())]);

        try {
            $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNotSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getOrCreateAppDepositAddress');
            // 快取裡找不到會重新向金流商拉一次再判定（後台剛啟用的幣或鏈不必等快取過期）
            $this->assertSame(2, $this->coinListRequests());
        }
    }

    public function test_get_or_create_address_throws_before_sending_when_coin_is_not_enabled(): void
    {
        $this->setOrderTypeKey('CCPAYMENT.BTC_BTC');
        Http::fake([self::API_URL . '/getCoinList' => Http::response($this->coinListBody())]);

        try {
            $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNotSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getOrCreateAppDepositAddress');
        }
    }

    public function test_malformed_order_type_key_throws_without_calling_provider(): void
    {
        $this->setOrderTypeKey('--');
        Http::fake();

        try {
            $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNothingSent();
        }
    }

    public function test_coin_list_is_fetched_once_and_cached_for_later_lookups(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TXYZabc', 'memo' => ''],
            ]),
        ]);

        $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);
        $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);

        $this->assertSame(1, $this->coinListRequests());
    }

    public function test_newly_enabled_coin_is_found_after_refreshing_the_cached_coin_list(): void
    {
        $this->setOrderTypeKey('CCPAYMENT.TETH_ETH_SEPOLIA');
        $usdtOnly = [['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]]];
        Http::fake([
            self::API_URL . '/getCoinList' => Http::sequence()
                ->push($this->coinListBody($usdtOnly))
                ->push($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => '0xabc', 'memo' => ''],
            ]),
        ]);

        $result = $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);

        $this->assertSame('0xabc', $result->address);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getOrCreateAppDepositAddress'
            && str_contains($request->body(), '"chain":"ETH_SEPOLIA"'));
        $this->assertSame(2, $this->coinListRequests());
    }

    public function test_channel_key_splits_symbol_and_chain_at_the_first_underscore(): void
    {
        $this->assertSame(['symbol' => 'USDT', 'chain' => 'TRX'], ChannelKey::parse('CCPAYMENT.USDT_TRX'));
        $this->assertSame(['symbol' => 'TETH', 'chain' => 'ETH_SEPOLIA'], ChannelKey::parse('CCPAYMENT.TETH_ETH_SEPOLIA'));
        $this->assertSame('CCPAYMENT.USDT_TRX', ChannelKey::build('USDT', 'TRX'));
        $this->assertNull(ChannelKey::parse('CCPAYMENT.USDT'));
        $this->assertNull(ChannelKey::parse('PAYESSENCE.TUSDT'));
        $this->assertNull(ChannelKey::parse('--'));
    }

    public function test_every_deposit_channel_key_in_provider_data_file_is_in_symbol_and_chain_form(): void
    {
        $config = require app_path('Console/Commands/CashFlow/Data/providers/CCPayment.php');

        $this->assertNotEmpty($config['deposit']['channels']);
        foreach ($config['deposit']['channels'] as $channel) {
            $this->assertNotNull(ChannelKey::parse($channel['key']), '資料檔的支付通道 key 必須是 CCPAYMENT.{幣別代號}_{三方鏈代號}');
            // 永久地址模式：會員自行轉帳、金額由回調決定，儲值頁不要金額欄位（只針對 CCPayment）
            $this->assertFalse($channel['amount_field'] ?? true, sprintf('%s 必須宣告 amount_field => false', $channel['key']));
        }
    }

    public function test_get_or_create_address_throws_when_response_has_no_address(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['memo' => ''],
            ]),
        ]);

        $this->expectException(ApiException::class);

        $this->driver()->getOrCreatePermanentDepositAddress($this->member, $this->channel);
    }

    public function test_unbind_sends_channel_chain_and_address(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/addressUnbinding' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => [
                    'unbound' => [['chain' => 'TRX', 'address' => 'TXYZabc']],
                    'unboundAt' => 1741783734,
                    'userID' => '',
                    'referenceID' => $this->channel->id . '-' . $this->member->id,
                ],
            ]),
        ]);

        $this->driver()->unbindPermanentDepositAddress($this->channel, 'TXYZabc');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/addressUnbinding'
            && $request->body() === '{"chain":"TRX","address":"TXYZabc"}');
    }

    public function test_deposit_refuses_to_create_orders_for_permanent_address_channel(): void
    {
        Http::fake();
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => new Order(['order_no' => 'CCP20260915000001']),
            'callbackUrl' => Common::depositCallbackUrl($this->agentCashFlow->id),
            'returnUrl' => '',
            'ip' => '0.0.0.0',
        ]);

        try {
            $this->driver()->deposit($request);
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNothingSent();
        }
    }
}
