<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VtPay;

use App\Services\Payment\Providers\VtPay\VtPayTrait;
use Tests\TestCase;

/**
 * VtPay Trait 加解密邏輯測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VtPay/VtPayTraitTest.php
 */
class VtPayTraitTest extends TestCase
{
    /**
     * 創建使用 VtPayTrait 的測試類別
     */
    private function createTraitInstance(): object
    {
        return new class
        {
            use VtPayTrait;

            protected array $info = [];

            public function setTestInfo(array $info): void
            {
                $this->info = $info;
            }

            public function test_decrypt_callback_data(string $encryptedData): array
            {
                return $this->decrypt($encryptedData);
            }

            public function test_encrypt_callback_response(array $data): string
            {
                return $this->encryptCallbackResponse($data);
            }

            protected function getDriverName(): string
            {
                return 'VtPay';
            }
        };
    }

    /**
     * 測試 JSON 數據加解密
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/VtPayTraitTest.php --filter test_json_data_encrypt_decrypt
     */
    public function test_json_data_encrypt_decrypt()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $trait->setTestInfo(['API_SECRET' => 'dRytW3eQ9CPj2r3/0eOx/Q==']);

        $originalData = [
            'sh_order_no' => 'TEST123456',
            'order_status' => 3,
            'order_amount' => 100.00,
            'paid_amount' => 100.00,
        ];

        // Act - 加密回調響應
        $encrypted = $trait->test_encrypt_callback_response($originalData);

        // Assert - 加密後應該是字串
        $this->assertIsString($encrypted);
        $this->assertNotEmpty($encrypted);

        // Act - 解密回調數據
        $decrypted = $trait->test_decrypt_callback_data($encrypted);

        // Assert - 解密後應該等於原始數據
        $this->assertEquals($originalData, $decrypted);
    }

    /**
     * 測試空數據加密
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/VtPayTraitTest.php --filter test_empty_data_encryption
     */
    public function test_empty_data_encryption()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $trait->setTestInfo(['API_SECRET' => 'dRytW3eQ9CPj2r3/0eOx/Q==']);

        $emptyData = [];

        // Act - 加密空數據
        $encrypted = $trait->test_encrypt_callback_response($emptyData);

        // Assert - 應該能正常加密
        $this->assertIsString($encrypted);

        // Act - 解密
        $decrypted = $trait->test_decrypt_callback_data($encrypted);

        // Assert - 解密後應該是空數組
        $this->assertEquals($emptyData, $decrypted);
    }

    /**
     * 測試中文數據加解密
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/VtPayTraitTest.php --filter test_chinese_data_encryption
     */
    public function test_chinese_data_encryption()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $trait->setTestInfo(['API_SECRET' => 'dRytW3eQ9CPj2r3/0eOx/Q==']);

        $chineseData = [
            'message' => '充值成功',
            'bank_name' => '中國銀行',
            'status' => '處理中',
        ];

        // Act - 加密
        $encrypted = $trait->test_encrypt_callback_response($chineseData);

        // Assert
        $this->assertIsString($encrypted);

        // Act - 解密
        $decrypted = $trait->test_decrypt_callback_data($encrypted);

        // Assert - 中文應該能正確解密
        $this->assertEquals($chineseData, $decrypted);
        $this->assertEquals('充值成功', $decrypted['message']);
        $this->assertEquals('中國銀行', $decrypted['bank_name']);
    }

    /**
     * 測試特殊字符數據加解密
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/VtPayTraitTest.php --filter test_special_characters_encryption
     */
    public function test_special_characters_encryption()
    {
        // Arrange
        $trait = $this->createTraitInstance();
        $trait->setTestInfo(['API_SECRET' => 'dRytW3eQ9CPj2r3/0eOx/Q==']);

        $specialData = [
            'symbols' => '!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~',
            'url' => 'https://example.invalid/callback?param=value&token=[REDACTED]',
            'html' => '<div class="test">Content</div>',
        ];

        // Act - 加密
        $encrypted = $trait->test_encrypt_callback_response($specialData);

        // Assert
        $this->assertIsString($encrypted);

        // Act - 解密
        $decrypted = $trait->test_decrypt_callback_data($encrypted);

        // Assert - 特殊字符應該能正確保留
        $this->assertEquals($specialData, $decrypted);
    }
}
