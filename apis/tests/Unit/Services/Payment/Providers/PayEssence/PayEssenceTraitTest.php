<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayEssence;

use App\Services\Payment\Providers\PayEssence\PayEssenceTrait;
use Tests\TestCase;

/**
 * PayEssence Trait 測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php
 */
class PayEssenceTraitTest extends TestCase
{
    /**
     * 創建測試用的匿名類
     */
    private function createTestClass(): object
    {
        return new class
        {
            use PayEssenceTrait;

            protected array $info = [];

            public function setInfo(array $info): void
            {
                $this->info = $info;
            }

            public function test_generate_deposit_signature(
                string $merchantCode,
                string $secretKey,
                string $transNum,
                string $currency,
                string $amount
            ): string {
                return $this->generateDepositSignature($merchantCode, $secretKey, $transNum, $currency, $amount);
            }

            public function test_generate_withdrawal_signature(
                string $merchantCode,
                string $secretKey,
                string $transNum,
                string $toBankId,
                string $toBankAccountNum,
                string $currencyTypeId,
                string $amount
            ): string {
                return $this->generateWithdrawalSignature(
                    $merchantCode,
                    $secretKey,
                    $transNum,
                    $toBankId,
                    $toBankAccountNum,
                    $currencyTypeId,
                    $amount
                );
            }

            public function test_verify_deposit_check_string(
                string $merchantCode,
                string $secretKey,
                string $transNum,
                string $checkString
            ): bool {
                return $this->verifyDepositCheckString($merchantCode, $secretKey, $transNum, $checkString);
            }

            public function test_verify_deposit_check_string2(
                string $merchantCode,
                string $secretKey,
                string $transNum,
                string $checkString2
            ): bool {
                return $this->verifyDepositCheckString2($merchantCode, $secretKey, $transNum, $checkString2);
            }

            public function test_verify_withdrawal_check_string2(
                string $merchantCode,
                string $secretKey,
                string $transNum,
                string $payoutId,
                string $toBankId,
                string $toBankAccountNum,
                string $currencyTypeId,
                string $amount,
                string $checkString2
            ): bool {
                return $this->verifyWithdrawalCheckString2(
                    $merchantCode,
                    $secretKey,
                    $transNum,
                    $payoutId,
                    $toBankId,
                    $toBankAccountNum,
                    $currencyTypeId,
                    $amount,
                    $checkString2
                );
            }
        };
    }

    /**
     * 測試生成代收簽名
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_generate_deposit_signature
     */
    public function test_generate_deposit_signature()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';
        $currency = 'MYR';
        $amount = '100.00';

        // Act
        $signature = $testClass->test_generate_deposit_signature(
            $merchantCode,
            $secretKey,
            $transNum,
            $currency,
            $amount
        );

        // Assert
        $expected = hash('sha256', $merchantCode . $secretKey . $transNum . $currency . $amount);
        $this->assertEquals($expected, $signature);
        $this->assertEquals(64, strlen($signature)); // SHA256 產生 64 個字元
    }

    /**
     * 測試生成代付簽名
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_generate_withdrawal_signature
     */
    public function test_generate_withdrawal_signature()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        // Act
        $signature = $testClass->test_generate_withdrawal_signature(
            $merchantCode,
            $secretKey,
            $transNum,
            $toBankId,
            $toBankAccountNum,
            $currencyTypeId,
            $amount
        );

        // Assert
        $expected = hash('sha256', $merchantCode . $secretKey . $transNum . $toBankId . $toBankAccountNum . $currencyTypeId . $amount);
        $this->assertEquals($expected, $signature);
        $this->assertEquals(64, strlen($signature));
    }

    /**
     * 測試驗證代收 CheckString（成功）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_deposit_checkstring_success
     */
    public function test_verify_deposit_checkstring_success()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $validCheckString = hash('sha256', $merchantCode . $secretKey . $transNum);

        // Act
        $result = $testClass->test_verify_deposit_check_string(
            $merchantCode,
            $secretKey,
            $transNum,
            $validCheckString
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 測試驗證代收 CheckString（失敗）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_deposit_checkstring_failure
     */
    public function test_verify_deposit_checkstring_failure()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $invalidCheckString = 'invalid_checkstring_hash';

        // Act
        $result = $testClass->test_verify_deposit_check_string(
            $merchantCode,
            $secretKey,
            $transNum,
            $invalidCheckString
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試驗證代收 CheckString2（成功）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_deposit_checkstring2_success
     */
    public function test_verify_deposit_checkstring2_success()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $reversedMerchantCode = strrev($merchantCode);
        $reversedTransNum = strrev($transNum);
        $validCheckString2 = hash('sha256', $reversedMerchantCode . $reversedTransNum . $secretKey);

        // Act
        $result = $testClass->test_verify_deposit_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $validCheckString2
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 測試驗證代收 CheckString2（失敗）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_deposit_checkstring2_failure
     */
    public function test_verify_deposit_checkstring2_failure()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $invalidCheckString2 = 'invalid_checkstring2_hash';

        // Act
        $result = $testClass->test_verify_deposit_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $invalidCheckString2
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試驗證代付 CheckString2（成功）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_withdrawal_checkstring2_success
     */
    public function test_verify_withdrawal_checkstring2_success()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';
        $payoutId = 'PO2024012300001';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $concatString = $merchantCode . $secretKey . $transNum . $payoutId . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;
        $validCheckString2 = hash('sha256', $concatString);

        // Act
        $result = $testClass->test_verify_withdrawal_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $payoutId,
            $toBankId,
            $toBankAccountNum,
            $currencyTypeId,
            $amount,
            $validCheckString2
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 測試驗證代付 CheckString2（失敗）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_verify_withdrawal_checkstring2_failure
     */
    public function test_verify_withdrawal_checkstring2_failure()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';
        $payoutId = 'PO2024012300001';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $invalidCheckString2 = 'invalid_withdrawal_checkstring2_hash';

        // Act
        $result = $testClass->test_verify_withdrawal_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $payoutId,
            $toBankId,
            $toBankAccountNum,
            $currencyTypeId,
            $amount,
            $invalidCheckString2
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * 測試 CheckString2 驗證時序攻擊安全性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_checkstring2_timing_attack_safety
     */
    public function test_checkstring2_timing_attack_safety()
    {
        // Arrange
        $testClass = $this->createTestClass();
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $reversedMerchantCode = strrev($merchantCode);
        $reversedTransNum = strrev($transNum);
        $validCheckString2 = hash('sha256', $reversedMerchantCode . $reversedTransNum . $secretKey);

        $similarCheckString2 = substr($validCheckString2, 0, -1) . 'f';

        $this->assertFalse($testClass->test_verify_deposit_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $similarCheckString2
        ));

        $this->assertTrue($testClass->test_verify_deposit_check_string2(
            $merchantCode,
            $secretKey,
            $transNum,
            $validCheckString2
        ));
    }

    /**
     * 測試不同輸入產生不同的簽名
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_different_inputs_produce_different_signatures
     */
    public function test_different_inputs_produce_different_signatures()
    {
        // Arrange
        $testClass = $this->createTestClass();

        // Act
        $signature1 = $testClass->test_generate_deposit_signature(
            'MERCHANT1',
            'secret1',
            'TRANS001',
            'MYR',
            '100.00'
        );

        $signature2 = $testClass->test_generate_deposit_signature(
            'MERCHANT2',
            'secret1',
            'TRANS001',
            'MYR',
            '100.00'
        );

        $signature3 = $testClass->test_generate_deposit_signature(
            'MERCHANT1',
            'secret1',
            'TRANS001',
            'MYR',
            '200.00'
        );

        // Assert
        $this->assertNotEquals($signature1, $signature2);
        $this->assertNotEquals($signature1, $signature3);
        $this->assertNotEquals($signature2, $signature3);
    }

    /**
     * 測試相同輸入產生相同的簽名（確保可重現性）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/PayEssenceTraitTest.php --filter test_same_inputs_produce_same_signatures
     */
    public function test_same_inputs_produce_same_signatures()
    {
        // Arrange
        $testClass = $this->createTestClass();

        // Act
        $signature1 = $testClass->test_generate_deposit_signature(
            'TEST_MERCHANT',
            'test_secret_key',
            'PE123456789',
            'MYR',
            '100.00'
        );

        $signature2 = $testClass->test_generate_deposit_signature(
            'TEST_MERCHANT',
            'test_secret_key',
            'PE123456789',
            'MYR',
            '100.00'
        );

        // Assert
        $this->assertEquals($signature1, $signature2);
    }
}
