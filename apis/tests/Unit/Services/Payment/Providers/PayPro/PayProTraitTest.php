<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayPro;

use App\Services\Payment\Providers\PayPro\PayProTrait;
use Tests\TestCase;

/**
 * PayPro Trait 測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php
 */
class PayProTraitTest extends TestCase
{
    /**
     * 創建測試用的匿名類
     */
    private function createTestClass(): object
    {
        return new class
        {
            use PayProTrait;

            protected array $info = [];

            public function setInfo(array $info): void
            {
                $this->info = $info;
            }

            public function test_generate_sign(array $params): string
            {
                return $this->generateSign($params);
            }

            public function test_verify_sign(array $params): bool
            {
                return $this->verifySign($params);
            }

            public function test_get_timestamp(): string
            {
                return $this->getTimestamp();
            }

            public function test_mask_sensitive_params(array $params): array
            {
                return $this->maskSensitiveParams($params);
            }
        };
    }

    /**
     * 測試簽名生成
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_generate_sign
     */
    public function test_generate_sign()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
        ];

        // Act
        $sign = $testClass->test_generate_sign($params);

        // Assert
        $this->assertNotEmpty($sign);
        $this->assertEquals(32, strlen($sign)); // MD5 簽名長度為 32
        $this->assertEquals(strtolower($sign), $sign); // 簽名應為小寫
    }

    /**
     * 測試簽名生成排除 sign 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_generate_sign_excludes_sign_field
     */
    public function test_generate_sign_excludes_sign_field()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params1 = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
        ];

        $params2 = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
            'sign' => 'existing_sign',
        ];

        // Act
        $sign1 = $testClass->test_generate_sign($params1);
        $sign2 = $testClass->test_generate_sign($params2);

        // Assert
        $this->assertEquals($sign1, $sign2); // 兩個簽名應該相同，因為 sign 欄位會被排除
    }

    /**
     * 測試簽名生成按字母順序排序
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_generate_sign_sorted
     */
    public function test_generate_sign_sorted()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params1 = [
            'zebra' => 'z',
            'amount' => '100',
            'apple' => 'a',
        ];

        $params2 = [
            'apple' => 'a',
            'amount' => '100',
            'zebra' => 'z',
        ];

        // Act
        $sign1 = $testClass->test_generate_sign($params1);
        $sign2 = $testClass->test_generate_sign($params2);

        // Assert
        $this->assertEquals($sign1, $sign2); // 參數順序不同但簽名應該相同
    }

    /**
     * 測試簽名生成過濾空值
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_generate_sign_filters_empty
     */
    public function test_generate_sign_filters_empty()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params1 = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'emptyField' => null,
        ];

        $params2 = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'emptyField' => '',
        ];

        $params3 = [
            'amount' => 100000,
            'timestamp' => '1234567890',
        ];

        // Act
        $sign1 = $testClass->test_generate_sign($params1);
        $sign2 = $testClass->test_generate_sign($params2);
        $sign3 = $testClass->test_generate_sign($params3);

        // Assert
        $this->assertEquals($sign1, $sign2); // null 和空字串都應該被過濾
        $this->assertEquals($sign2, $sign3); // 過濾後應該與沒有空值的相同
    }

    /**
     * 測試簽名驗證成功
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_verify_sign_success
     */
    public function test_verify_sign_success()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
        ];

        $sign = $testClass->test_generate_sign($params);
        $params['sign'] = $sign;

        // Act
        $result = $testClass->test_verify_sign($params);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_verify_sign_failure
     */
    public function test_verify_sign_failure()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
            'sign' => 'invalid_sign',
        ];

        // Act
        $result = $testClass->test_verify_sign($params);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試簽名驗證缺少簽名欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_verify_sign_missing_sign
     */
    public function test_verify_sign_missing_sign()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'MERCHANT_KEY' => 'test_merchant_key',
        ]);

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
        ];

        // Act
        $result = $testClass->test_verify_sign($params);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試獲取時間戳
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_get_timestamp
     */
    public function test_get_timestamp()
    {
        // Arrange
        $testClass = $this->createTestClass();

        // Act
        $timestamp1 = $testClass->test_get_timestamp();
        usleep(1000); // 等待 1 毫秒
        $timestamp2 = $testClass->test_get_timestamp();

        // Assert
        $this->assertNotEmpty($timestamp1);
        $this->assertIsString($timestamp1);
        $this->assertIsNumeric($timestamp1);
        $this->assertGreaterThanOrEqual($timestamp1, $timestamp2); // 時間戳應該遞增或相等
    }

    /**
     * 測試遮罩敏感參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_mask_sensitive_params
     */
    public function test_mask_sensitive_params()
    {
        // Arrange
        $testClass = $this->createTestClass();

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
            'tradeNo' => 'TEST123',
            'sign' => 'secret_signature',
        ];

        // Act
        $masked = $testClass->test_mask_sensitive_params($params);

        // Assert
        $this->assertEquals('***', $masked['sign']);
        $this->assertEquals(100000, $masked['amount']);
        $this->assertEquals('1234567890', $masked['timestamp']);
        $this->assertEquals('TEST123', $masked['tradeNo']);
    }

    /**
     * 測試缺少 MERCHANT_KEY 配置
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/PayProTraitTest.php --filter test_missing_merchant_key
     */
    public function test_missing_merchant_key()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $testClass->setInfo([
            'API_URL' => 'https://example.invalid',
            'MERCHANT_NO' => 'test_merchant_no',
        ]);

        $params = [
            'amount' => 100000,
            'timestamp' => '1234567890',
        ];

        // Act
        $sign = $testClass->test_generate_sign($params);

        // Assert
        // 即使沒有 MERCHANT_KEY，簽名生成也不會拋出異常，只是簽名會不同
        $this->assertNotEmpty($sign);
    }
}
