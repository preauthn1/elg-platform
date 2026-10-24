<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TngPin;

use App\Services\Payment\Providers\TngPin\TngPinTrait;
use Tests\TestCase;

/**
 * TngPin 共用 Trait 測試（簽名驗證 / PIN 遮罩）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TngPin/TngPinTraitTest.php
 */
class TngPinTraitTest extends TestCase
{
    private object $sut;

    private array $info;

    protected function setUp(): void
    {
        parent::setUp();

        $this->info = [
            'ApiKey' => 'test_api_key',
            'Merchant' => 'TestMerchant',
        ];

        // 以匿名類別套用 trait 並公開 protected 方法供測試
        $this->sut = new class
        {
            use TngPinTrait;

            public function callCheckSign(array $params, array $info): bool
            {
                return $this->checkSign($params, $info);
            }

            public function callMaskPin(string $pin): string
            {
                return $this->maskPin($pin);
            }

            public function callMaskSensitive(array $data): array
            {
                return $this->maskSensitive($data);
            }
        };
    }

    /**
     * 正確簽名（官方公式 md5(ApiKey + transaction_id + merchant_ref)）應通過。
     */
    public function test_check_sign_passes_with_valid_signature(): void
    {
        $transactionId = 'E2D2C4B0B2A90D18A993';
        $merchantRef = 'ORD20260101001';
        $signature = md5($this->info['ApiKey'] . $transactionId . $merchantRef);

        $params = [
            'transaction_id' => $transactionId,
            'merchant_ref' => $merchantRef,
            'signature' => $signature,
        ];

        $this->assertTrue($this->sut->callCheckSign($params, $this->info));
    }

    /**
     * 被竄改的簽名應驗證失敗。
     */
    public function test_check_sign_fails_with_tampered_signature(): void
    {
        $params = [
            'transaction_id' => 'E2D2C4B0B2A90D18A993',
            'merchant_ref' => 'ORD20260101001',
            'signature' => 'deadbeefdeadbeefdeadbeefdeadbeef',
        ];

        $this->assertFalse($this->sut->callCheckSign($params, $this->info));
    }

    /**
     * 回歸鎖定：簽名第三段必須取「回傳的 merchant_ref」，而非寫死的 info['Merchant']。
     * 當 merchant_ref 與 info['Merchant'] 不同（模擬工單 #3865 將 merchant_ref 改為訂單號後的情境）：
     *   - 以 merchant_ref 計算的簽名 → 應通過
     *   - 以 info['Merchant'] 計算的舊簽名 → 應失敗
     * 若有人把實作改回寫死 info['Merchant']，本測試會紅。
     */
    public function test_check_sign_uses_returned_merchant_ref_not_static_merchant(): void
    {
        $transactionId = 'E2D2C4B0B2A90D18A993';
        $merchantRef = 'ORD20260101001'; // 與 info['Merchant'] = 'TestMerchant' 不同

        $signatureFromMerchantRef = md5($this->info['ApiKey'] . $transactionId . $merchantRef);
        $signatureFromStaticMerchant = md5($this->info['ApiKey'] . $transactionId . $this->info['Merchant']);

        $this->assertTrue(
            $this->sut->callCheckSign([
                'transaction_id' => $transactionId,
                'merchant_ref' => $merchantRef,
                'signature' => $signatureFromMerchantRef,
            ], $this->info),
            '簽名第三段應採用回傳的 merchant_ref'
        );

        $this->assertFalse(
            $this->sut->callCheckSign([
                'transaction_id' => $transactionId,
                'merchant_ref' => $merchantRef,
                'signature' => $signatureFromStaticMerchant,
            ], $this->info),
            '不可再以寫死的 info[Merchant] 驗簽（#3865 後會全面失敗）'
        );
    }

    /**
     * 缺 merchant_ref 時以空字串計算，不可拋錯。
     */
    public function test_check_sign_handles_missing_merchant_ref(): void
    {
        $transactionId = 'E2D2C4B0B2A90D18A993';
        $signature = md5($this->info['ApiKey'] . $transactionId . '');

        $params = [
            'transaction_id' => $transactionId,
            'signature' => $signature,
        ];

        $this->assertTrue($this->sut->callCheckSign($params, $this->info));
    }

    /**
     * PIN 遮罩：長度 > 5 保留末 5 碼，其餘以 * 取代。
     */
    public function test_mask_pin_keeps_last_five_digits(): void
    {
        $this->assertEquals('***********23456', $this->sut->callMaskPin('1234567890123456'));
    }

    /**
     * PIN 遮罩邊界：長度 <= 5 全部遮罩。
     */
    public function test_mask_pin_fully_masks_short_pin(): void
    {
        $this->assertEquals('*****', $this->sut->callMaskPin('12345'));
        $this->assertEquals('***', $this->sut->callMaskPin('123'));
    }

    /**
     * 敏感欄位遮罩：payment_key/payment_pass 全遮罩、pin 保留末 5 碼、非敏感欄位不變。
     * 對齊舊系統 maskSensitive，防止憑證明文寫入 remark/log。
     */
    public function test_mask_sensitive_masks_credentials_and_pin(): void
    {
        $masked = $this->sut->callMaskSensitive([
            'payment_key' => 'secret_key',
            'payment_pass' => 'secret_pass',
            'pin' => '1234567890123456',
            'telco' => 'umobile',
        ]);

        $this->assertEquals('***', $masked['payment_key'], 'API 憑證應全遮罩');
        $this->assertEquals('***', $masked['payment_pass'], 'API 憑證應全遮罩');
        $this->assertEquals('***********23456', $masked['pin'], 'PIN 保留末 5 碼');
        $this->assertEquals('umobile', $masked['telco'], '非敏感欄位不應變動');
    }
}
