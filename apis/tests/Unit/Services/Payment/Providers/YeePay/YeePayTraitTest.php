<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\YeePay;

use App\Services\Payment\Providers\YeePay\YeePayTrait;
use Tests\TestCase;

/**
 * YeePay Trait 簽名邏輯測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php
 */
class YeePayTraitTest extends TestCase
{
    /**
     * 創建使用 YeePayTrait 的測試類別
     */
    private function createTraitInstance(): object
    {
        return new class
        {
            use YeePayTrait;

            public function test_generate_sign(array $params, string $secret): string
            {
                return $this->generateSign($params, $secret);
            }

            public function test_check_sign(array $params, string $secret): bool
            {
                return $this->checkSign($params, $secret);
            }

            protected function getDriverName(): string
            {
                return 'YeePay';
            }
        };
    }

    /**
     * 測試簽名生成基本功能
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_generate_sign_basic
     */
    public function test_generate_sign_basic()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
        ];
        $secret = [REDACTED]';

        // Act
        $sign = $trait->test_generate_sign($params, $secret);

        // Assert
        $this->assertIsString($sign);
        $this->assertEquals(32, strlen($sign)); // MD5 長度
        $this->assertEquals(strtoupper($sign), $sign); // 大寫
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $sign);
    }

    /**
     * 測試簽名排除空值
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_generate_sign_excludes_empty_values
     */
    public function test_generate_sign_excludes_empty_values()
    {
        // Arrange
        $trait = $this->createTraitInstance();

        // 包含空值的參數
        $paramsWithEmpty = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
            'extend' => '', // 空字串應被排除
            'optional' => null, // null 應被排除
        ];

        // 不含空值的參數
        $paramsWithoutEmpty = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
        ];

        $secret = [REDACTED]';

        // Act
        $sign1 = $trait->test_generate_sign($paramsWithEmpty, $secret);
        $sign2 = $trait->test_generate_sign($paramsWithoutEmpty, $secret);

        // Assert - 兩者簽名應相同（空值被排除）
        $this->assertEquals($sign1, $sign2);
    }

    /**
     * 測試簽名自動排除 sign 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_generate_sign_excludes_sign_field
     */
    public function test_generate_sign_excludes_sign_field()
    {
        // Arrange
        $trait = $this->createTraitInstance();

        $paramsWithSign = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
            'sign' => 'OLD_SIGN_TO_BE_EXCLUDED',
        ];

        $paramsWithoutSign = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
        ];

        $secret = [REDACTED]';

        // Act
        $sign1 = $trait->test_generate_sign($paramsWithSign, $secret);
        $sign2 = $trait->test_generate_sign($paramsWithoutSign, $secret);

        // Assert - 簽名應相同（sign 欄位被排除）
        $this->assertEquals($sign1, $sign2);
    }

    /**
     * 測試簽名驗證成功
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_check_sign_success
     */
    public function test_check_sign_success()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
        ];
        $secret = [REDACTED]';

        // 先生成正確的簽名
        $validSign = $trait->test_generate_sign($params, $secret);
        $params['sign'] = $validSign;

        // Act
        $result = $trait->test_check_sign($params, $secret);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_check_sign_failure
     */
    public function test_check_sign_failure()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
            'sign' => 'INVALID_SIGNATURE',
        ];
        $secret = [REDACTED]';

        // Act
        $result = $trait->test_check_sign($params, $secret);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試參數順序不影響簽名
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_parameter_order_independence
     */
    public function test_parameter_order_independence()
    {
        // Arrange
        $trait = $this->createTraitInstance();

        // 不同順序的參數
        $params1 = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'order' => 'ORDER001',
            'coin' => '100.00',
        ];

        $params2 = [
            'coin' => '100.00',
            'order' => 'ORDER001',
            'merchant_num' => 'M12345',
            'uid' => 'test123',
        ];

        $secret = [REDACTED]';

        // Act
        $sign1 = $trait->test_generate_sign($params1, $secret);
        $sign2 = $trait->test_generate_sign($params2, $secret);

        // Assert - 簽名應相同（自動排序）
        $this->assertEquals($sign1, $sign2);
    }

    /**
     * 測試簽名對大小寫敏感
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_case_sensitive
     */
    public function test_case_sensitive()
    {
        // Arrange
        $trait = $this->createTraitInstance();

        $params1 = [
            'uid' => 'Test123', // T 大寫
            'merchant_num' => 'M12345',
        ];

        $params2 = [
            'uid' => 'test123', // t 小寫
            'merchant_num' => 'M12345',
        ];

        $secret = [REDACTED]';

        // Act
        $sign1 = $trait->test_generate_sign($params1, $secret);
        $sign2 = $trait->test_generate_sign($params2, $secret);

        // Assert - 簽名應不同（大小寫敏感）
        $this->assertNotEquals($sign1, $sign2);
    }

    /**
     * 測試特殊字符處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_special_characters
     */
    public function test_special_characters()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [
            'uid' => 'test@123',
            'merchant_num' => 'M#12345',
            'order' => 'ORDER&001',
            'name' => 'John Doe', // 含空格
        ];
        $secret = [REDACTED]';

        // Act
        $sign = $trait->test_generate_sign($params, $secret);

        // Assert - 應能正確處理特殊字符
        $this->assertIsString($sign);
        $this->assertEquals(32, strlen($sign));
        $this->assertEquals(strtoupper($sign), $sign);
    }

    /**
     * 測試中文字符處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_chinese_characters
     */
    public function test_chinese_characters()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'userinfo' => '測試用戶',
            'bank_name' => '中國銀行',
        ];
        $secret = [REDACTED]';

        // Act
        $sign = $trait->test_generate_sign($params, $secret);

        // Assert - 應能正確處理中文
        $this->assertIsString($sign);
        $this->assertEquals(32, strlen($sign));
        $this->assertEquals(strtoupper($sign), $sign);
    }

    /**
     * 測試數字 0 的處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_zero_value_handling
     */
    public function test_zero_value_handling()
    {
        // Arrange
        $trait = $this->createTraitInstance();

        // 包含數字 0 和字串 '0' 的參數
        $paramsWithZero = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
            'fee' => 0, // 數字 0 應被排除
            'discount' => '0', // 字串 '0' 應被排除
        ];

        // 不含 0 的參數
        $paramsWithoutZero = [
            'uid' => 'test123',
            'merchant_num' => 'M12345',
        ];

        $secret = [REDACTED]';

        // Act
        $sign1 = $trait->test_generate_sign($paramsWithZero, $secret);
        $sign2 = $trait->test_generate_sign($paramsWithoutZero, $secret);

        // Assert - 簽名應相同（0 被排除）
        $this->assertEquals($sign1, $sign2);
    }

    /**
     * 測試空陣列處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/YeePayTraitTest.php --filter test_empty_array
     */
    public function test_empty_array()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $params = [];
        $secret = [REDACTED]';

        // Act
        $sign = $trait->test_generate_sign($params, $secret);

        // Assert - 空陣列經過 http_build_query 後為空字串，拼接為 &key=secret
        $expectedSign = strtoupper(md5('&key=' . $secret));
        $this->assertEquals($expectedSign, $sign);
    }
}
