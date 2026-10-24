<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;
use App\Services\Payment\Providers\ZPay\ZPayCredentials;
use App\Services\Payment\Providers\ZPay\ZPayTrait;
use PHPUnit\Framework\TestCase;

/**
 * ZPay Trait 單元測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/ZPay/ZPayTraitTest.php
 */
class ZPayTraitTest extends TestCase
{
    /**
     * 建立使用 ZPayTrait 的測試物件
     */
    private function createTraitInstance(array $info = []): object
    {
        return new class($info)
        {
            use ZPayTrait;

            protected array $info = [];

            public function __construct(array $info)
            {
                $this->setInfo($info);
            }

            public function test_generate_item_desc_sign(string $orderNo, string $amount, string $secret): string
            {
                return $this->generateItemDescSign($orderNo, $amount, $secret);
            }

            public function test_verify_callback_sign(
                string $referenceId,
                string $dateTime,
                float $amount,
                string $secret,
                string $receivedItemDesc
            ): bool {
                return $this->verifyCallbackSign($referenceId, $dateTime, $amount, $secret, $receivedItemDesc);
            }

            public function test_verify_callback_item_desc(
                string $storedItemDesc,
                string $secret,
                string $receivedItemDesc
            ): bool {
                return $this->verifyCallbackItemDesc($storedItemDesc, $secret, $receivedItemDesc);
            }

            public function test_get_merchant_credentials(): ZPayCredentials
            {
                return $this->getMerchantCredentials();
            }

            protected function getDriverName(): string
            {
                return 'ZPay';
            }

            protected function setInfo(array $info): void
            {
                $this->info = $info;
            }
        };
    }

    /**
     * 測試 ItemDesc 簽名產生一致性
     */
    public function test_generate_item_desc_sign_consistency(): void
    {
        $trait = $this->createTraitInstance();
        $sign1 = $trait->test_generate_item_desc_sign('REF123', '100.00', 'secret');
        $sign2 = $trait->test_generate_item_desc_sign('REF123', '100.00', 'secret');

        $this->assertEquals($sign1, $sign2);
    }

    /**
     * 測試 ItemDesc 簽名值符合預期
     */
    public function test_generate_item_desc_sign_expected_value(): void
    {
        $trait = $this->createTraitInstance();
        $amount = '100.00';
        $expected = md5('REF123' . $amount . 'secret');

        $sign = $trait->test_generate_item_desc_sign('REF123', $amount, 'secret');

        $this->assertEquals($expected, $sign);
    }

    /**
     * 測試回調簽名驗證成功
     */
    public function test_verify_callback_sign_success(): void
    {
        $trait = $this->createTraitInstance();
        $amount = 100.0;
        $itemDesc = md5('REF456' . '20240123123000' . $amount . 'secret');

        $result = $trait->test_verify_callback_sign('REF456', '20240123123000', $amount, 'secret', $itemDesc);

        $this->assertTrue($result);
    }

    /**
     * 測試回調 ItemDesc 驗證成功
     */
    public function test_verify_callback_item_desc_success(): void
    {
        $trait = $this->createTraitInstance();
        $storedItemDesc = $trait->test_generate_item_desc_sign('REF999', '88.80', 'secret');
        $receivedItemDesc = md5($storedItemDesc . 'secret');

        $result = $trait->test_verify_callback_item_desc($storedItemDesc, 'secret', $receivedItemDesc);

        $this->assertTrue($result);
    }

    /**
     * 測試回調簽名驗證失敗
     */
    public function test_verify_callback_sign_failure(): void
    {
        $trait = $this->createTraitInstance();
        $result = $trait->test_verify_callback_sign('REF789', '20240123153000', 200.0, 'secret', 'INVALID');

        $this->assertFalse($result);
    }

    /**
     * 測試取得商戶憑證成功
     */
    public function test_get_merchant_credentials_success(): void
    {
        $trait = $this->createTraitInstance([
            'TOKEN' => 'token',
            'SECRET_KEY' => 'secret',
        ]);

        $credentials = $trait->test_get_merchant_credentials();

        $this->assertInstanceOf(ZPayCredentials::class, $credentials);
    }

    /**
     * 測試取得商戶憑證失敗
     */
    public function test_get_merchant_credentials_missing(): void
    {
        $trait = $this->createTraitInstance([
            'TOKEN' => '',
            'SECRET_KEY' => '',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('ZPay credentials are missing');

        $trait->test_get_merchant_credentials();
    }
}
