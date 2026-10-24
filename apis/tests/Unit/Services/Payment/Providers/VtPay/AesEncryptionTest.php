<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VtPay;

use App\Services\Payment\Providers\VtPay\VtPayCryptoService;
use Tests\TestCase;

/**
 * VtPay AES 加解密測試
 *
 * 驗證 VtPayCryptoService 是否符合官方範例：PHP_example_encrypt_decrypt.php
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VtPay/AesEncryptionTest.php
 */
class AesEncryptionTest extends TestCase
{
    private VtPayCryptoService $cryptoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cryptoService = new VtPayCryptoService;
    }

    /**
     * 測試加解密循環
     */
    public function test_encrypt_decrypt_cycle(): void
    {
        $key = '2KmMsAzSLqe9Q4P+h0hyWw==';  // 官方範例密鑰
        $data = [
            'sh_order_no' => 'TEST123456',
            'order_amount' => 100.50,
            'order_type' => 'FPX',
        ];

        // 加密
        $encrypted = $this->cryptoService->encrypt($data, $key);
        $this->assertNotEmpty($encrypted);

        // 解密
        $decrypted = $this->cryptoService->decrypt($encrypted, $key);

        // 驗證
        $this->assertEquals($data, $decrypted);
    }

    /**
     * 測試純 JSON 響應（未加密）
     */
    public function test_plain_json_response(): void
    {
        $key = '2KmMsAzSLqe9Q4P+h0hyWw==';
        $data = [
            'error_code' => 200,
            'data' => [
                'order_id' => 'D-123456',
            ],
        ];

        $jsonResponse = json_encode($data);
        $result = $this->cryptoService->decrypt($jsonResponse, $key);

        $this->assertEquals($data, $result);
    }

    /**
     * 測試符合官方範例的格式
     */
    public function test_official_format(): void
    {
        $sh_key = '2KmMsAzSLqe9Q4P+h0hyWw==';
        $key = base64_decode($sh_key);
        $data = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        // 使用官方範例的方式加密
        $iv = random_bytes(16);
        $value = openssl_encrypt(json_encode($data, JSON_UNESCAPED_SLASHES), 'AES-128-CBC', $key, 0, $iv);
        $encrypted = base64_encode(json_encode(['iv' => base64_encode($iv), 'value' => $value], JSON_UNESCAPED_SLASHES));

        // 使用我們的 Service 解密
        $decrypted = $this->cryptoService->decrypt($encrypted, $sh_key);

        $this->assertEquals($data, $decrypted);
    }

    /**
     * 測試錯誤處理
     */
    public function test_invalid_key_throws_exception(): void
    {
        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionMessage('Invalid AES key');

        $this->cryptoService->encrypt([], 'invalid_key');
    }
}
