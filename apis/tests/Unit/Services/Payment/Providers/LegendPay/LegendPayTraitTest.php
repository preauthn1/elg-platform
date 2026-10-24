<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\LegendPay;

use App\Services\Payment\Providers\LegendPay\LegendPayTrait;
use Tests\TestCase;

/**
 * LegendPay 簽名測試
 *
 * 重點鎖定「值含空格」的行為：LegendPay 以解碼後的原始值簽名（含空格），
 * 期望字串一律手寫、不得用 http_build_query 重建，避免測試鏡像實作缺陷。
 * 實證來源：TPower 代付回調 message="IFSC err"，三方 sign 與空格形式一致。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/LegendPay/LegendPayTraitTest.php
 */
class LegendPayTraitTest extends TestCase
{
    private const API_SECRET = [REDACTED]';

    private function createTestClass(): object
    {
        return new class
        {
            use LegendPayTrait;

            public function callGenerateSign(array $params, array $info): string
            {
                return $this->generateSign($params, $info);
            }

            public function callCheckSign(array $params, array $info): bool
            {
                return $this->checkSign($params, $info);
            }
        };
    }

    private function info(): array
    {
        return ['API_SECRET' => self::API_SECRET];
    }

    /**
     * 含空格的值（如 personName）必須以原始空格參與簽名，不得變成 +
     */
    public function test_generate_sign_keeps_space_in_values(): void
    {
        $params = [
            'amount' => '7000.00',
            'orderId' => 'LEG2026080909041229617',
            'personName' => 'Jarnail singh',
            'ts' => '1786266252296',
        ];

        // 手寫期望字串（ksort 後）：空格必須保留為空格
        $expected = strtoupper(md5(
            'amount=7000.00&orderId=LEG2026080909041229617&personName=Jarnail singh&ts=1786266252296'
            . '&key=' . self::API_SECRET
        ));

        $this->assertSame($expected, $this->createTestClass()->callGenerateSign($params, $this->info()));
    }

    /**
     * 回調驗簽：三方以含空格的原始值計算 sign（如 message="IFSC err"）時必須通過
     */
    public function test_check_sign_accepts_provider_sign_with_spaced_value(): void
    {
        // 依三方回調實際計算方式手工組簽名（param=null 被過濾、sign 不參與、ksort）
        $providerSign = strtoupper(md5(
            'amount=107.00&commission=9.21&message=IFSC err&orderId=LEG2025120210481503843&status=2&utr=No_UTR_Result'
            . '&key=' . self::API_SECRET
        ));

        $callback = [
            'status' => '2',
            'orderId' => 'LEG2025120210481503843',
            'message' => 'IFSC err',
            'utr' => 'No_UTR_Result',
            'amount' => '107.00',
            'param' => null,
            'commission' => '9.21',
            'sign' => $providerSign,
        ];

        $this->assertTrue($this->createTestClass()->callCheckSign($callback, $this->info()));
    }

    /**
     * 過濾規則：null 與空字串移除、0 與 '0' 保留、URL 值原樣還原
     */
    public function test_generate_sign_filters_empty_and_keeps_zero_and_url(): void
    {
        $params = [
            'mode' => '0',
            'param' => '',
            'memo' => null,
            'callbackUrl' => 'https://example.invalid/external/api/withdrawal/notify/266',
            'amount' => '100.00',
        ];

        $expected = strtoupper(md5(
            'amount=100.00&callbackUrl=https://example.invalid/external/api/withdrawal/notify/266&mode=0'
            . '&key=' . self::API_SECRET
        ));

        $this->assertSame($expected, $this->createTestClass()->callGenerateSign($params, $this->info()));
    }
}
