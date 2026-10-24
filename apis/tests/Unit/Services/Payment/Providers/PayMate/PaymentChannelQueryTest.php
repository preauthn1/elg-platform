<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Enums\Payment\PaymentChannelDirection;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Services\Payment\Providers\PayMate\Driver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayMate 通道快照查詢測試（SupportsPaymentChannelQuery，資料源 api.md 3.4 /setting）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/PaymentChannelQueryTest.php
 */
class PaymentChannelQueryTest extends TestCase
{
    // 同 namespace（Tests\Unit\Services\Payment\Providers\PayMate）內的既有 trait，免 import
    use PayMateSignatureHelper;

    protected AgentCashFlow $agentCashFlow;

    private const MERCHANT_NO = 'PM134126';

    private const MD5_KEY = 'c3f839790505a40973613d51bb8e2b8e';

    protected function setUp(): void
    {
        parent::setUp();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1, 'code' => 'MYR', 'name' => 'Malaysian Ringgit',
            'rate' => 1, 'status' => 1, 'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1, 'cash_flow_id' => 121, 'cash_flow_info_id' => 1,
            'rate' => 1, 'info' => json_encode($info), 'status' => 1, 'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
    }

    public function test_maps_setting_response_to_channel_snapshot(): void
    {
        Http::fake(['*' => Http::response($this->buildResponseEnvelope([
            'code' => '0000',
            'msg' => '查詢成功',
            'merchantStatus' => 'ON',
            'depositStatus' => 'ON',
            'payoutStatus' => 'OFF',
            'payments' => [
                ['name' => 'P2PDEPOSIT', 'status' => 'ON', 'minAmount' => '10.00', 'maxAmount' => '1000.00', 'supportBankCode' => ''],
                ['name' => 'PAYOUT', 'status' => 'ON', 'minAmount' => '50.00', 'maxAmount' => '4999.00', 'supportBankCode' => 'MAYBANK,CIMB'],
            ],
        ], self::MERCHANT_NO, self::MD5_KEY), 200)]);

        $snapshot = (new Driver($this->agentCashFlow))->queryPaymentChannels();

        $this->assertSame('ON', $snapshot->merchantStatus);
        $this->assertSame('OFF', $snapshot->payoutStatus);
        $this->assertNull($snapshot->availableBalance, '三方未回傳的餘額欄位為 null 不偽造');
        $this->assertCount(2, $snapshot->channels);
        $this->assertSame(PaymentChannelDirection::Deposit, $snapshot->channels[0]->direction);
        $this->assertSame([], $snapshot->channels[0]->supportBankCodes, '掃碼類空字串拆成空陣列');
        $this->assertSame(PaymentChannelDirection::Payout, $snapshot->channels[1]->direction);
        $this->assertSame(['MAYBANK', 'CIMB'], $snapshot->channels[1]->supportBankCodes);
        $this->assertNull($snapshot->channels[1]->accountBankName, '/setting 不回通道自身的帳戶開戶行');
        $this->assertSame('50.0000', $snapshot->channels[1]->minAmount, '限額須經 Common::numAdd 正規化');
        $this->assertSame('4999.0000', $snapshot->channels[1]->maxAmount);
        $this->assertNotSame([], $snapshot->raw, 'raw 保留遮罩後原始回應');
    }
}
