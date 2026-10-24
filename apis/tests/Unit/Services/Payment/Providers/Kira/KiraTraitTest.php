<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Kira;

use App\Services\Payment\Providers\Kira\KiraTrait;
use Tests\TestCase;

/**
 * Kira Trait 測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php
 */
class KiraTraitTest extends TestCase
{
    /**
     * 創建測試用的匿名類
     */
    private function createTestClass(): object
    {
        return new class
        {
            use KiraTrait;

            protected array $info = [];

            public function setInfo(array $info): void
            {
                $this->info = $info;
            }

            public function test_generate_nonce_str(int $length = 16): string
            {
                return $this->generateNonceStr($length);
            }

            public function test_sort_array_keys_recursive(array $array): array
            {
                return $this->sortArrayKeysRecursive($array);
            }

            public function test_format_public_key(string $key): string
            {
                return $this->formatPublicKey($key);
            }

            public function test_format_private_key(string $key): string
            {
                return $this->formatPrivateKey($key);
            }

            public function test_mask_sensitive_data(array $data): array
            {
                return $this->maskSensitiveData($data);
            }

            public function test_aes_encrypt(string $data, string $key): string
            {
                return $this->aesEncrypt($data, $key);
            }

            public function test_aes_decrypt(string $encryptedHex, string $key): string
            {
                return $this->aesDecrypt($encryptedHex, $key);
            }

            public function getDriverName(): string
            {
                return 'Kira';
            }
        };
    }

    /**
     * 測試生成隨機字串
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_generate_nonce_str
     */
    public function test_generate_nonce_str()
    {
        // Arrange
        $testClass = $this->createTestClass();

        // Act
        $nonceStr = $testClass->test_generate_nonce_str(16);

        // Assert
        $this->assertEquals(16, strlen($nonceStr));
        $this->assertTrue(ctype_xdigit($nonceStr)); // 確認是十六進制字符
    }

    /**
     * 測試生成隨機字串（自訂長度）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_generate_nonce_str_custom_length
     */
    public function test_generate_nonce_str_custom_length()
    {
        // Arrange
        $testClass = $this->createTestClass();

        // Act
        $nonceStr = $testClass->test_generate_nonce_str(32);

        // Assert
        $this->assertEquals(32, strlen($nonceStr));
        $this->assertTrue(ctype_xdigit($nonceStr));
    }

    /**
     * 測試遞迴排序陣列 key（簡單陣列）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_sort_array_keys_simple
     */
    public function test_sort_array_keys_simple()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $unsortedArray = [
            'z' => 'value_z',
            'a' => 'value_a',
            'm' => 'value_m',
        ];

        // Act
        $sortedArray = $testClass->test_sort_array_keys_recursive($unsortedArray);

        // Assert
        $keys = array_keys($sortedArray);
        $this->assertEquals(['a', 'm', 'z'], $keys);
    }

    /**
     * 測試遞迴排序陣列 key（巢狀陣列）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_sort_array_keys_nested
     */
    public function test_sort_array_keys_nested()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $unsortedArray = [
            'z' => 'value_z',
            'nested' => [
                'c' => 'nested_c',
                'a' => 'nested_a',
            ],
            'a' => 'value_a',
        ];

        // Act
        $sortedArray = $testClass->test_sort_array_keys_recursive($unsortedArray);

        // Assert
        $topLevelKeys = array_keys($sortedArray);
        $this->assertEquals(['a', 'nested', 'z'], $topLevelKeys);

        $nestedKeys = array_keys($sortedArray['nested']);
        $this->assertEquals(['a', 'c'], $nestedKeys);
    }

    /**
     * 測試格式化公鑰（已有 PEM 標頭）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_format_public_key_with_header
     */
    public function test_format_public_key_with_header()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $publicKey = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2Z3qX2BTLS39R3wvUL3p
-----END PUBLIC KEY-----';

        // Act
        $formattedKey = $testClass->test_format_public_key($publicKey);

        // Assert
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $formattedKey);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $formattedKey);
        $this->assertEquals($publicKey, $formattedKey);
    }

    /**
     * 測試格式化私鑰（已有 PEM 標頭）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_format_private_key_with_header
     */
    public function test_format_private_key_with_header()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $privateKey = '[PRIVATE_KEY_BLOCK_REDACTED]';

        // Act
        $formattedKey = $testClass->test_format_private_key($privateKey);

        // Assert
        $this->assertStringContainsString('-----BEGIN', $formattedKey);
        $this->assertStringContainsString('-----END', $formattedKey);
        $this->assertEquals($privateKey, $formattedKey);
    }

    /**
     * 測試隱遮敏感數據（簡單資料）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_mask_sensitive_data_simple
     */
    public function test_mask_sensitive_data_simple()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $data = [
            'order' => [
                'id' => 'ORD_123',
                'amount' => '100.00',
            ],
            'CLIENT_ID' => 'secret_client_id',
            'CLIENT_SECRET' => 'secret_client_secret',
        ];

        // Act
        $masked = $testClass->test_mask_sensitive_data($data);

        // Assert
        $this->assertEquals('ORD_123', $masked['order']['id']);
        $this->assertEquals('100.00', $masked['order']['amount']);
        $this->assertEquals('******', $masked['CLIENT_ID']);
        $this->assertEquals('******', $masked['CLIENT_SECRET']);
    }

    /**
     * 測試隱遮敏感數據（巢狀資料）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_mask_sensitive_data_nested
     */
    public function test_mask_sensitive_data_nested()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $data = [
            'order' => [
                'id' => 'ORD_123',
                'CLIENT_PRIVATE_KEY' => 'secret_private_key',
            ],
            'CLIENT_SYMMETRIC_KEY' => 'secret_symmetric_key',
            'token' => 'secret_token',
        ];

        // Act
        $masked = $testClass->test_mask_sensitive_data($data);

        // Assert
        $this->assertEquals('ORD_123', $masked['order']['id']);
        $this->assertEquals('******', $masked['order']['CLIENT_PRIVATE_KEY']);
        $this->assertEquals('******', $masked['CLIENT_SYMMETRIC_KEY']);
        $this->assertEquals('******', $masked['token']);
    }

    /**
     * 測試 AES 加密和解密
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_aes_encrypt_decrypt
     */
    public function test_aes_encrypt_decrypt()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $originalData = 'This is test data for encryption';
        $key = '0123456789abcdef0123456789abcdef'; // 32 bytes for AES-256

        // Act
        $encrypted = $testClass->test_aes_encrypt($originalData, $key);
        $decrypted = $testClass->test_aes_decrypt($encrypted, $key);

        // Assert
        $this->assertEquals($originalData, $decrypted);
        $this->assertNotEquals($originalData, $encrypted); // 加密後應與原始資料不同
    }

    /**
     * 測試 AES 加密和解密（JSON 數據）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_aes_encrypt_decrypt_json
     */
    public function test_aes_encrypt_decrypt_json()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $originalData = json_encode([
            'order' => [
                'id' => 'ORD_123',
                'amount' => '100.00',
            ],
            'status' => 'SUCCESS',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $key = '0123456789abcdef0123456789abcdef';

        // Act
        $encrypted = $testClass->test_aes_encrypt($originalData, $key);
        $decrypted = $testClass->test_aes_decrypt($encrypted, $key);

        // Assert
        $this->assertEquals($originalData, $decrypted);
        $decodedData = json_decode($decrypted, true);
        $this->assertEquals('ORD_123', $decodedData['order']['id']);
        $this->assertEquals('100.00', $decodedData['order']['amount']);
        $this->assertEquals('SUCCESS', $decodedData['status']);
    }

    /**
     * 測試 AES 解密失敗（無效的十六進制數據）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_aes_decrypt_invalid_hex
     */
    public function test_aes_decrypt_invalid_hex()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $invalidHex = 'invalid_hex_string!@#$';
        $key = '0123456789abcdef0123456789abcdef';

        // Assert
        $this->expectException(\Exception::class);

        // Act
        $testClass->test_aes_decrypt($invalidHex, $key);
    }

    /**
     * 測試 AES 解密失敗（無效的加密數據）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/KiraTraitTest.php --filter test_aes_decrypt_invalid_data
     */
    public function test_aes_decrypt_invalid_data()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $invalidEncryptedData = '0102030405060708090a0b0c0d0e0f10'; // 無效的加密數據
        $key = '0123456789abcdef0123456789abcdef';

        // Assert
        $this->expectException(\Exception::class);

        // Act
        $testClass->test_aes_decrypt($invalidEncryptedData, $key);
    }
}
