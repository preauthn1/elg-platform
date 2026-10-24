<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AkPay;

use App\Services\Payment\Providers\AkPay\AkPayTrait;
use PHPUnit\Framework\TestCase;

/**
 * AkPay Trait 單元測試
 *
 * 測試簽名產生 (generateSecretKey) 與驗證 (checkSecretKey) 邏輯
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AkPay/AkPayTraitTest.php
 */
class AkPayTraitTest extends TestCase
{
    /**
     * 建立使用 AkPayTrait 的測試物件
     */
    private function createTraitInstance(): object
    {
        return new class
        {
            use AkPayTrait;

            protected array $info = [];

            public function test_generate_secret_key(array $params, string $token): string
            {
                return $this->generateSecretKey($params, $token);
            }

            public function test_check_secret_key(array $params, string $token): bool
            {
                return $this->checkSecretKey($params, $token);
            }

            protected function getDriverName(): string
            {
                return 'AkPay';
            }
        };
    }

    /**
     * 測試 generateSecretKey 一致性：相同輸入必定產生相同簽名
     */
    public function test_generate_secret_key_consistency(): void
    {
        $trait = $this->createTraitInstance();

        $params = [
            'source_id' => 'AKTEST_ORDER_123',
            'tx_id' => 10001,
            'amount' => '100.00',
            'currency' => 'MYR',
        ];

        $sign1 = $trait->test_generate_secret_key($params, 'test_token');
        $sign2 = $trait->test_generate_secret_key($params, 'test_token');

        $this->assertSame($sign1, $sign2);
    }

    /**
     * 測試 generateSecretKey 符合預期值：md5(urldecode(http_build_query([...])))
     */
    public function test_generate_secret_key_expected_value(): void
    {
        $trait = $this->createTraitInstance();

        $params = [
            'source_id' => 'AKTEST_ORDER_123',
            'tx_id' => 10001,
            'amount' => '100.00',
            'currency' => 'MYR',
        ];

        $expected = md5(urldecode(http_build_query([
            'source_id' => 'AKTEST_ORDER_123',
            'tx_id' => 10001,
            'amount' => '100.00',
            'currency' => 'MYR',
            'token' => 'test_token',
        ])));

        $actual = $trait->test_generate_secret_key($params, 'test_token');

        $this->assertSame($expected, $actual);
    }

    /**
     * 測試 checkSecretKey 驗證成功
     */
    public function test_check_secret_key_success(): void
    {
        $trait = $this->createTraitInstance();

        $params = [
            'source_id' => 'AKTEST_ORDER_456',
            'tx_id' => 20001,
            'amount' => '200.00',
            'currency' => 'MYR',
        ];

        // 用相同邏輯預先產生合法的 secret_key
        $params['secret_key'] = $trait->test_generate_secret_key($params, 'test_token');

        $result = $trait->test_check_secret_key($params, 'test_token');

        $this->assertTrue($result);
    }

    /**
     * 測試 checkSecretKey 驗證失敗（secret_key 不符）
     */
    public function test_check_secret_key_failure_with_invalid_key(): void
    {
        $trait = $this->createTraitInstance();

        $params = [
            'source_id' => 'AKTEST_ORDER_789',
            'tx_id' => 30001,
            'amount' => '300.00',
            'currency' => 'MYR',
            'secret_key' => 'INVALID_SECRET_KEY',
        ];

        $result = $trait->test_check_secret_key($params, 'test_token');

        $this->assertFalse($result);
    }

    /**
     * 測試 checkSecretKey 驗證失敗（token 不同）
     */
    public function test_check_secret_key_failure_with_wrong_token(): void
    {
        $trait = $this->createTraitInstance();

        $params = [
            'source_id' => 'AKTEST_ORDER_999',
            'tx_id' => 40001,
            'amount' => '400.00',
            'currency' => 'MYR',
        ];

        // 使用正確 token 產生 secret_key
        $params['secret_key'] = $trait->test_generate_secret_key($params, 'correct_token');

        // 用錯誤 token 驗證
        $result = $trait->test_check_secret_key($params, 'wrong_token');

        $this->assertFalse($result);
    }
}
