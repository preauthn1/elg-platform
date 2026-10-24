<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FPay;

use App\Enums\Payment\PaymentChannelDirection;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Providers\FPay\Driver;
use App\Services\Payment\Providers\FPay\WithdrawBankList;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FPay 代付銀行清單查詢測試（SupportsPaymentChannelQuery，資料源 api.md「銀行列表查詢」
 * POST /wallet/withdraw_bank_list）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawBankListTest.php
 */
class WithdrawBankListTest extends TestCase
{
    private Currency $currency;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->agentCashFlow = $this->makeAcf([
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => 'test_merchant_no',
            'APP_KEY' => 'test_app_key',
            'APP_SECRET' => 'test_app_secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function makeAcf(array $info): AgentCashFlow
    {
        $acf = AgentCashFlow::create([
            'cash_flow_id' => 68,
            'cash_flow_info_id' => 1,
            'currency_id' => $this->currency->id,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $acf->info = $info;

        return $acf;
    }

    public function test_maps_bank_list_to_single_payout_channel(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => [
                ['currency' => 'IDR', 'bank_name' => 'Bank Central Asia', 'id' => '7'],
                ['currency' => 'IDR', 'bank_name' => 'Bank Mandiri', 'id' => '2'],
            ],
        ], 200)]);

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        // 這支查詢沒有商戶層欄位，全 null 不偽造
        $this->assertNull($snapshot->merchantStatus);
        $this->assertNull($snapshot->payoutStatus);
        $this->assertNull($snapshot->availableBalance);

        $this->assertCount(1, $snapshot->channels);
        $channel = $snapshot->channels[0];
        $this->assertSame('withdraw_bank_list', $channel->name);
        $this->assertSame(
            PaymentChannelDirection::Payout,
            $channel->direction,
            '必須標成代付方向，命令才會拿它比對代付 ACF 的 available_banks'
        );
        $this->assertSame(['7', '2'], $channel->supportBankCodes);
        $this->assertNull($channel->minAmount, '這支查詢沒有限額，不得偽造');
        $this->assertNull($channel->maxAmount);

        // 銀行名稱只留在 raw，供人工對照新舊代號
        $this->assertStringContainsString('Bank Central Asia', (string) json_encode($snapshot->raw));

        // 請求只帶 username＋currency，不先打 /merchant/auth
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/wallet/withdraw_bank_list')
            && $request['username'] === 'test_merchant_no'
            && $request['currency'] === $this->currency->code);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/merchant/auth'));
    }

    /**
     * 缺 id 的列略過：空字串若進 supportBankCodes，比對會誤報「三方有、我方沒有」。
     */
    public function test_skips_rows_without_bank_id(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => [
                ['currency' => 'IDR', 'bank_name' => 'Bank Negara Indonesia', 'id' => '3'],
                ['currency' => 'IDR', 'bank_name' => 'Missing Id Bank'],
                ['currency' => 'IDR', 'bank_name' => 'Empty Id Bank', 'id' => ''],
            ],
        ], 200)]);

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        $this->assertSame(['3'], $snapshot->channels[0]->supportBankCodes);
    }

    /**
     * 三方明確拒絕時要帶出三方訊息，排查者才分得出是帳號、幣別還是其他問題。
     */
    public function test_throws_with_third_party_message_when_status_false(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => false,
            'message' => 'Currency invalid',
        ], 200)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Currency invalid');

        (new WithdrawBankList($this->agentCashFlow))->handle();
    }

    /**
     * fail-closed：data 不是清單時不得映射成「零家銀行」，否則會被誤判成 FPay 關閉了代付。
     */
    public function test_throws_when_data_is_not_a_list(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => ['currency' => 'IDR', 'bank_name' => 'Bank Negara Indonesia', 'id' => '3'],
        ], 200)]);

        $this->expectException(ApiException::class);

        (new WithdrawBankList($this->agentCashFlow))->handle();
    }

    public function test_throws_when_data_missing(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response(['status' => true], 200)]);

        $this->expectException(ApiException::class);

        (new WithdrawBankList($this->agentCashFlow))->handle();
    }

    /**
     * 缺 MERCHANT_NO 要在送出前擋下，不送出必定失敗的請求。
     */
    public function test_throws_before_sending_when_merchant_no_missing(): void
    {
        Http::fake();
        $acf = $this->makeAcf(['API_URL' => 'https://api.fpay.test']);

        try {
            (new WithdrawBankList($acf))->handle();
            $this->fail('缺 MERCHANT_NO 時應拋 ApiException');
        } catch (ApiException) {
            // 預期路徑
        }

        Http::assertNothingSent();
    }

    /**
     * 只要 API_URL＋MERCHANT_NO 就查得動；APP_KEY／APP_SECRET 這支查詢用不到，不得多要。
     */
    public function test_only_requires_api_url_and_merchant_no(): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => [['currency' => 'IDR', 'bank_name' => 'Bank Negara Indonesia', 'id' => '3']],
        ], 200)]);

        $acf = $this->makeAcf(['API_URL' => 'https://api.fpay.test', 'MERCHANT_NO' => 'test_merchant_no']);

        $this->assertCount(1, (new WithdrawBankList($acf))->handle());
    }

    public function test_driver_supports_channel_query(): void
    {
        $this->assertInstanceOf(SupportsPaymentChannelQuery::class, new Driver($this->agentCashFlow));
    }
}
