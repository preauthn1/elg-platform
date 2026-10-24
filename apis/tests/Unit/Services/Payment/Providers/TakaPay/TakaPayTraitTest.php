<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TakaPay;

use App\Services\Payment\Providers\TakaPay\TakaPayTrait;
use PHPUnit\Framework\TestCase;

/**
 * TakaPay Trait 簽名邏輯測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php
 */
class TakaPayTraitTest extends TestCase
{
    /**
     * 創建使用 TakaPayTrait 的測試類別
     */
    private function createTraitInstance(): object
    {
        return new class
        {
            use TakaPayTrait;

            public function test_generate_security_token(array $fields, string $secret): string
            {
                return $this->generateSecurityToken($fields, $secret);
            }

            public function test_parse_payment_method_and_code(string $rawCode): array
            {
                return $this->parsePaymentMethodAndCode($rawCode);
            }

            public function test_success_response(): array
            {
                return $this->successResponse();
            }

            public function test_fail_response(): array
            {
                return $this->failResponse();
            }

            protected function getDriverName(): string
            {
                return 'TakaPay';
            }

            protected function setInfo(array $info): void
            {
                $this->info = $info;
            }
        };
    }

    /**
     * 測試生成 security token
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_generate_security_token_basic
     */
    public function test_generate_security_token_basic()
    {
        $trait = $this->createTraitInstance();
        $fields = [
            'TKC001', // merchantCode
            'testuser', // username
            '0.0.0.0', // ipAddress
            'USD', // currencyCode
            '100.00', // amount
            'ORDER001', // reference
            '2024-01-23 10:00:00', // transactionTime
            'https://example.invalid/return', // returnUrl
            'https://example.invalid/callback', // notifyUrl
            '1', // paymentMethod
            'BKASH', // paymentCode
        ];
        $secret = [REDACTED]';

        $token = [REDACTED], $secret);

        $this->assertIsString($token);
        $this->assertEquals(32, strlen($token)); // MD5 長度
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    /**
     * 測試 security token 生成一致性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_generate_security_token_consistency
     */
    public function test_generate_security_token_consistency()
    {
        $trait = $this->createTraitInstance();
        $fields = ['TKC001', 'testuser', '0.0.0.0', 'USD', '100.00', 'ORDER001'];
        $secret = [REDACTED]';

        $token1 = $trait->test_generate_security_token($fields, $secret);
        $token2 = $trait->test_generate_security_token($fields, $secret);

        $this->assertEquals($token1, $token2);
    }

    /**
     * 測試不同的金額會生成不同的令 token
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_generate_security_token_different_amounts
     */
    public function test_generate_security_token_different_amounts()
    {
        $trait = $this->createTraitInstance();
        $secret = [REDACTED]';

        $fields1 = ['TKC001', 'testuser', '0.0.0.0', 'USD', '100.00', 'ORDER001'];
        $fields2 = ['TKC001', 'testuser', '0.0.0.0', 'USD', '200.00', 'ORDER001'];

        $token1 = $trait->test_generate_security_token($fields1, $secret);
        $token2 = $trait->test_generate_security_token($fields2, $secret);

        $this->assertNotEquals($token1, $token2);
    }

    /**
     * 測試解析支付方法和代碼 - 基本情況
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_and_code_basic
     */
    public function test_parse_payment_method_and_code_basic()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '1.bkash';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('paymentMethod', $result);
        $this->assertArrayHasKey('paymentCode', $result);
        $this->assertEquals('1', $result['paymentMethod']);
        $this->assertEquals('bkash', $result['paymentCode']);
    }

    /**
     * 測試解析支付方法 - 多個點符號
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_and_code_multiple_dots
     */
    public function test_parse_payment_method_and_code_multiple_dots()
    {
        $trait = $this->createTraitInstance();
        $rawCode = 'bank.transfer.fast';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('bank', $result['paymentMethod']);
        $this->assertEquals('transfer.fast', $result['paymentCode']);
    }

    /**
     * 測試解析支付方法 - 空字符串
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_empty_string
     */
    public function test_parse_payment_method_empty_string()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '';

        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionMessage('Invalid payment code format');
        $trait->test_parse_payment_method_and_code($rawCode);
    }

    /**
     * 測試解析支付方法 - 缺少點符號
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_missing_dot
     */
    public function test_parse_payment_method_missing_dot()
    {
        $trait = $this->createTraitInstance();
        $rawCode = 'bkash';

        // 缺少點符號應拋出異常
        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionMessage('Invalid payment code format');
        $trait->test_parse_payment_method_and_code($rawCode);
    }

    /**
     * 測試解析支付方法 - 特殊字符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_special_chars
     */
    public function test_parse_payment_method_special_chars()
    {
        $trait = $this->createTraitInstance();
        $rawCode = 'bank-transfer.upi-123';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('bank-transfer', $result['paymentMethod']);
        $this->assertEquals('upi-123', $result['paymentCode']);
    }

    /**
     * 測試解析支付方法 - 大寫字符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_payment_method_uppercase
     */
    public function test_parse_payment_method_uppercase()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '1.ROCKET';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('1', $result['paymentMethod']);
        $this->assertEquals('ROCKET', $result['paymentCode']);
    }

    /**
     * 測試成功回應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_success_response
     */
    public function test_success_response()
    {
        $trait = $this->createTraitInstance();

        $response = $trait->test_success_response();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('00000', $response['status']);
    }

    /**
     * 測試失敗回應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_fail_response
     */
    public function test_fail_response()
    {
        $trait = $this->createTraitInstance();

        $response = $trait->test_fail_response();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('12000', $response['status']);
    }

    /**
     * 測試實際銀行代碼解析 - Bkash
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_real_world_bkash
     */
    public function test_parse_real_world_bkash()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '1.bkash';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('1', $result['paymentMethod']);
        $this->assertEquals('bkash', $result['paymentCode']);
    }

    /**
     * 測試實際銀行代碼解析 - Rocket
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_real_world_rocket
     */
    public function test_parse_real_world_rocket()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '1.rocket';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('1', $result['paymentMethod']);
        $this->assertEquals('rocket', $result['paymentCode']);
    }

    /**
     * 測試實際銀行代碼解析 - Nagad
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_parse_real_world_nagad
     */
    public function test_parse_real_world_nagad()
    {
        $trait = $this->createTraitInstance();
        $rawCode = '1.nagad';

        $result = $trait->test_parse_payment_method_and_code($rawCode);

        $this->assertEquals('1', $result['paymentMethod']);
        $this->assertEquals('nagad', $result['paymentCode']);
    }

    /**
     * 測試簽名生成在不同金額下的變化
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_signature_changes_with_different_currencies
     */
    public function test_signature_changes_with_different_currencies()
    {
        $trait = $this->createTraitInstance();
        $secret = [REDACTED]';

        // BDT 簽名
        $fieldsBDT = ['TKC001', 'user1', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];
        $signBDT = $trait->test_generate_security_token($fieldsBDT, $secret);

        // MYR 簽名
        $fieldsMYR = ['TKC001', 'user1', '0.0.0.0', 'MYR', '1000.00', 'ORD001'];
        $signMYR = $trait->test_generate_security_token($fieldsMYR, $secret);

        // 不同幣別應產生不同簽名
        $this->assertNotEquals($signBDT, $signMYR);
    }

    /**
     * 測試簽名生成在不同用戶下的變化
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_signature_changes_with_different_users
     */
    public function test_signature_changes_with_different_users()
    {
        $trait = $this->createTraitInstance();
        $secret = [REDACTED]';

        $fieldsUser1 = ['TKC001', 'user1', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];
        $signUser1 = $trait->test_generate_security_token($fieldsUser1, $secret);

        $fieldsUser2 = ['TKC001', 'user2', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];
        $signUser2 = $trait->test_generate_security_token($fieldsUser2, $secret);

        // 不同用戶應產生不同簽名
        $this->assertNotEquals($signUser1, $signUser2);
    }

    /**
     * 測試簽名生成在不同 IP 下的變化
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_signature_changes_with_different_ips
     */
    public function test_signature_changes_with_different_ips()
    {
        $trait = $this->createTraitInstance();
        $secret = [REDACTED]';

        $fieldsIP1 = ['TKC001', 'user1', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];
        $signIP1 = $trait->test_generate_security_token($fieldsIP1, $secret);

        $fieldsIP2 = ['TKC001', 'user1', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];
        $signIP2 = $trait->test_generate_security_token($fieldsIP2, $secret);

        // 不同 IP 應產生不同簽名
        $this->assertNotEquals($signIP1, $signIP2);
    }

    /**
     * 測試簽名生成在不同密鑰下的變化
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/TakaPayTraitTest.php --filter test_signature_changes_with_different_secrets
     */
    public function test_signature_changes_with_different_secrets()
    {
        $trait = $this->createTraitInstance();
        $fields = ['TKC001', 'user1', '0.0.0.0', 'BDT', '1000.00', 'ORD001'];

        $signSecret1 = $trait->test_generate_security_token($fields, 'secret_key_1');
        $signSecret2 = $trait->test_generate_security_token($fields, 'secret_key_2');

        // 不同密鑰應產生不同簽名
        $this->assertNotEquals($signSecret1, $signSecret2);
    }
}
