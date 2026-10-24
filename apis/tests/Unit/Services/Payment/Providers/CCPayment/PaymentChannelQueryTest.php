<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Services\Payment\Providers\CCPayment\ChannelKey;
use App\Services\Payment\Providers\CCPayment\Driver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 通道快照查詢測試（SupportsPaymentChannelQuery，資料源 api.md §7 getCoinList／getChainList）
 *
 * 守的是通道名格式：`cash-flow:third-party-query` 的代收比對拿 `order_types.key` 與三方通道名逐字比對，
 * 所以快照的通道名必須與 ChannelKey 同一個格式，否則已配置的通道會被列成「三方有、我方未配置的線路」。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/PaymentChannelQueryTest.php
 */
class PaymentChannelQueryTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => 129,
            'info' => [
                'API_URL' => self::API_URL,
                'APP_ID' => 'test_app_id',
                'APP_SECRET' => 'test_app_secret',
            ],
        ]);
    }

    private function fakeCoinAndChainLists(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['coins' => [
                    [
                        'coinId' => 1280,
                        'symbol' => 'USDT',
                        'status' => 'Normal',
                        'networks' => [
                            'TRX' => ['chain' => 'TRX'],
                            'ETH' => ['chain' => 'ETH'],
                        ],
                    ],
                    [
                        'coinId' => 1891,
                        'symbol' => 'TETH',
                        'status' => 'Normal',
                        'networks' => [
                            'ETH_SEPOLIA' => ['chain' => 'ETH_SEPOLIA'],
                        ],
                    ],
                ]],
            ]),
            self::API_URL . '/getChainList' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['chains' => [
                    ['chain' => 'TRX', 'status' => 'Normal'],
                    ['chain' => 'ETH', 'status' => 'Maintenance'],
                    ['chain' => 'ETH_SEPOLIA', 'status' => 'Normal'],
                ]],
            ]),
        ]);
    }

    public function test_channel_names_use_the_order_type_key_format(): void
    {
        $this->fakeCoinAndChainLists();

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        $this->assertSame(
            ['CCPAYMENT.USDT_TRX', 'CCPAYMENT.USDT_ETH', 'CCPAYMENT.TETH_ETH_SEPOLIA'],
            array_map(static fn ($channel): string => $channel->name, $snapshot->channels)
        );
    }

    public function test_every_channel_name_parses_back_to_its_symbol_and_chain(): void
    {
        $this->fakeCoinAndChainLists();

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        // 鏈代號含底線（ETH_SEPOLIA）也要能還原；supportBankCodes 放的是提款銀行三方代碼的同一個格式
        // （{幣別代號}_{三方鏈代號}），命令的銀行碼比對才對得上
        foreach ($snapshot->channels as $channel) {
            $parsed = ChannelKey::parse($channel->name);

            $this->assertNotNull($parsed, $channel->name . ' 不是 ChannelKey 格式');
            $this->assertSame([ChannelKey::coinChain($parsed['symbol'], $parsed['chain'])], $channel->supportBankCodes);
            $this->assertSame($parsed, ChannelKey::parseCoinChain($channel->supportBankCodes[0]));
        }
    }

    public function test_chain_status_is_reported_per_channel(): void
    {
        $this->fakeCoinAndChainLists();

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        $this->assertSame('Normal', $snapshot->channels[0]->status);
        $this->assertSame('Maintenance', $snapshot->channels[1]->status);
    }
}
