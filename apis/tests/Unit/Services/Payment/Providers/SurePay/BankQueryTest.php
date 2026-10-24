<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\SurePay;

use App\Enums\Payment\PaymentChannelDirection;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Services\Payment\Providers\SurePay\BankQuery;
use App\Services\Payment\Providers\SurePay\Driver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SurePay 通道快照查詢測試（SupportsPaymentChannelQuery，資料源 api.md §8 /v1/bankquery）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/SurePay/BankQueryTest.php
 */
class BankQueryTest extends TestCase
{
    protected AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1, 'code' => 'MYR', 'name' => 'Malaysian Ringgit',
            'rate' => 1, 'status' => 1, 'admin' => 0,
        ]));

        // Driver 建構子會建 Deposit 並跑 SurePayTrait::validateConfig()，故須備齊六欄；
        // BankQuery 自身只要 API_URL＋API_KEY（見 test_bank_query_only_requires_url_and_key）
        $this->agentCashFlow = $this->makeAcf([
            'API_URL' => 'https://my.paymentgt.test',
            'MERCHANT_ID' => 'Surepay88',
            'CONTRACT_NAME' => 'Surepay88-OB',
            'API_KEY' => 'unit-test-api-key',
            'CALLBACK_API_KEY' => 'unit-test-callback-key',
            'CURRENCY' => 'MYR',
        ]);
    }

    private function makeAcf(array $info): AgentCashFlow
    {
        $acf = AgentCashFlow::create([
            'currency_id' => 1, 'cash_flow_id' => 97, 'cash_flow_info_id' => 1,
            'rate' => 1, 'info' => json_encode($info), 'status' => 1, 'admin' => 0,
        ]);
        $acf->info = $info;

        return $acf;
    }

    /**
     * 欄位組以 2026-08-28 對測試商戶 SPREE009Tm99s 的實打回應為準（見 api.md §8 實測補充）：
     * 限額鍵是 `bankminamt`／`bankmaxamt`（非文件寫的 `bankminamout`）、`banktype` 回小寫 d／w、
     * 另有文件未載明的 `created`。
     */
    public function test_maps_bankquery_rows_to_channel_snapshot(): void
    {
        Http::fake(['*/v1/bankquery' => Http::response([
            ['bankname' => 'MAYBANK', 'accname' => '王小明', 'bankaccno' => '512345678901',
                'iban' => '10022198', 'banktype' => 'd', 'bankminamt' => '10.00', 'bankmaxamt' => '1000.00',
                'created' => '2026-01-21 11:52:59'],
            ['bankname' => 'CIMB', 'accname' => 'JOHN TAN', 'bankaccno' => '998877665544',
                'iban' => '10022300', 'banktype' => 'w', 'bankminamt' => '50.00', 'bankmaxamt' => '20000.00',
                'created' => '2026-01-21 11:53:06'],
        ], 200)]);

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        // 商戶層：bankquery 沒有這些欄位，全 null 不偽造
        $this->assertNull($snapshot->merchantStatus);
        $this->assertNull($snapshot->availableBalance);

        $this->assertCount(2, $snapshot->channels);
        $this->assertSame('10022198', $snapshot->channels[0]->name, '通道識別＝iban 通道碼');
        $this->assertSame(PaymentChannelDirection::Deposit, $snapshot->channels[0]->direction);
        $this->assertSame('MAYBANK', $snapshot->channels[0]->accountBankName, 'bankname＝該通道收付款帳戶的開戶行');
        $this->assertSame(
            [],
            $snapshot->channels[0]->supportBankCodes,
            'bankquery 沒有「可出款到哪些銀行」的清單，不得把開戶行當支援銀行（會讓代付比對誤報整批缺口）'
        );
        $this->assertSame('10.0000', $snapshot->channels[0]->minAmount);
        $this->assertSame(PaymentChannelDirection::Payout, $snapshot->channels[1]->direction);
        $this->assertSame('20000.0000', $snapshot->channels[1]->maxAmount);

        // PII 不入標準欄位、raw 已遮罩（部分遮罩政策：帳號前 3 後 4、戶名頭尾字）
        $encoded = (string) json_encode($snapshot->raw, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('512345678901', $encoded, '完整銀行帳號不得出現在 raw');
        $this->assertStringContainsString('512*****8901', $encoded);
        $this->assertStringContainsString('王*明', $encoded);

        // 請求層：Bearer 憑證
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer [REDACTED]')
            && str_contains($request->url(), '/v1/bankquery'));
    }

    /**
     * 憑證缺欄 fail-fast：直接測 BankQuery 元件本身——透過 Driver 測不到，
     * Driver 建構子的 Deposit::validateConfig() 會先擋下，掩蓋本查詢自身的檢核。
     */
    public function test_throws_when_credentials_missing(): void
    {
        Http::fake();
        $acf = $this->makeAcf(['API_URL' => 'https://my.paymentgt.test', 'API_KEY' => '']);

        try {
            (new BankQuery($acf))->handle();
            $this->fail('缺 API_KEY 時應拋 ApiException');
        } catch (ApiException) {
            // 預期路徑；缺欄要在送出請求前擋下
        }

        Http::assertNothingSent();
    }

    /**
     * 不沿用共用 validateConfig：bank query 用不到 MERCHANT_ID／CONTRACT_NAME／
     * CALLBACK_API_KEY／CURRENCY，只有這兩欄也必須查得動（多要欄位＝製造假性失敗）。
     */
    public function test_bank_query_only_requires_url_and_key(): void
    {
        Http::fake(['*/v1/bankquery' => Http::response([
            ['bankname' => 'MAYBANK', 'iban' => '10022198', 'banktype' => 'd',
                'bankminamt' => '10.00', 'bankmaxamt' => '1000.00'],
        ], 200)]);

        $acf = $this->makeAcf(['API_URL' => 'https://my.paymentgt.test', 'API_KEY' => 'unit-test-api-key']);

        $this->assertCount(1, (new BankQuery($acf))->handle());
    }

    /**
     * fail-closed：規格未載明錯誤格式，關聯陣列（疑似錯誤 envelope）不得被靜默映射成
     * 「零通道」的空快照——那會讓排查者誤判商戶沒開通道。
     */
    public function test_throws_on_non_list_response_shape(): void
    {
        Http::fake(['*/v1/bankquery' => Http::response(['code' => '401', 'msg' => 'unauthorized'], 200)]);

        $this->expectException(ApiException::class);
        (new Driver($this->agentCashFlow))->queryPaymentChannels();
    }

    /**
     * 相容廠商文件的舊拼法：文件 §8 寫 `bankminamout`／`bankmaxamout` 與大寫 `D`／`W`，
     * 實打回的是 `bankminamt`／`bankmaxamt` 與小寫。兩套都要吃得下——
     * 三方哪天改回文件寫法時不會靜默變成「限額未回傳」。
     */
    public function test_accepts_documented_field_spelling_and_uppercase_banktype(): void
    {
        Http::fake(['*/v1/bankquery' => Http::response([
            ['bankname' => 'MAYBANK', 'iban' => '10022198', 'banktype' => 'D',
                'bankminamout' => '10.00', 'bankmaxamout' => '1000.00'],
        ], 200)]);

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        $this->assertSame(PaymentChannelDirection::Deposit, $snapshot->channels[0]->direction);
        $this->assertSame('10.0000', $snapshot->channels[0]->minAmount);
        $this->assertSame('1000.0000', $snapshot->channels[0]->maxAmount);
    }

    public function test_capability_matrix_channel_query_implementers(): void
    {
        $this->assertInstanceOf(
            \App\Services\Payment\Contracts\SupportsPaymentChannelQuery::class,
            new Driver($this->agentCashFlow),
        );
        $this->assertInstanceOf(
            \App\Services\Payment\Contracts\SupportsPaymentChannelQuery::class,
            new \App\Services\Payment\Providers\PayMate\Driver($this->agentCashFlow),
        );
    }
}
