<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VaderPay;

use App\Services\Payment\Providers\VaderPay\VaderPayTrait;
use PHPUnit\Framework\TestCase;

/**
 * VaderPay Trait 測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VaderPay/VaderPayTraitTest.php
 */
class VaderPayTraitTest extends TestCase
{
    private function createTraitInstance(): object
    {
        return new class
        {
            use VaderPayTrait;

            public function test_generate_deposit_hash(
                string $merchantCode,
                string $itemId,
                string $currency,
                string $amount,
                string $apiKey
            ): string {
                return $this->generateDepositHash($merchantCode, $itemId, $currency, $amount, $apiKey);
            }

            public function test_generate_payout_hash(
                string $merchantCode,
                string $refId,
                string $playerUsername,
                string $playerIp,
                string $currencyCode,
                string $amount,
                string $bankCode,
                string $beneficiaryAccount,
                string $beneficiaryName,
                string $apiKey
            ): string {
                return $this->generatePayoutHash(
                    $merchantCode,
                    $refId,
                    $playerUsername,
                    $playerIp,
                    $currencyCode,
                    $amount,
                    $bankCode,
                    $beneficiaryAccount,
                    $beneficiaryName,
                    $apiKey
                );
            }

            public function test_generate_payin_callback_signature(
                string $invoiceNo,
                string $receiverBank,
                string $receiverAccount,
                string $currency,
                string $amount,
                string $paymentDate,
                string $apiKey
            ): string {
                return $this->generatePayinCallbackSignature(
                    $invoiceNo,
                    $receiverBank,
                    $receiverAccount,
                    $currency,
                    $amount,
                    $paymentDate,
                    $apiKey
                );
            }

            public function test_generate_payout_callback_signature(
                string $invoiceNo,
                string $status,
                string $currency,
                string $amount,
                string $paymentDate,
                string $apiKey
            ): string {
                return $this->generatePayoutCallbackSignature(
                    $invoiceNo,
                    $status,
                    $currency,
                    $amount,
                    $paymentDate,
                    $apiKey
                );
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
                return 'VaderPay';
            }
        };
    }

    public function test_generate_deposit_hash(): void
    {
        $trait = $this->createTraitInstance();
        $hash = $trait->test_generate_deposit_hash('merchant01', 'INV-001', 'MYR', '99.00', 'secret');

        $this->assertEquals(
            hash_hmac('sha256', 'merchant01INV-001MYR99.00', 'secret'),
            $hash
        );
    }

    public function test_generate_payout_hash(): void
    {
        $trait = $this->createTraitInstance();
        $hash = $trait->test_generate_payout_hash(
            'merchant01',
            'WD-001',
            'player1',
            '0.0.0.0',
            'MYR',
            '100.00',
            'CIMB',
            '123456789',
            'Test User',
            'secret'
        );

        $expected = hash_hmac(
            'sha256',
            'merchant01WD-001player1127.0.0.1MYR100.00CIMB123456789Test User',
            'secret'
        );

        $this->assertEquals($expected, $hash);
    }

    public function test_generate_payin_callback_signature(): void
    {
        $trait = $this->createTraitInstance();
        $hash = $trait->test_generate_payin_callback_signature(
            'INV-001',
            'CIMB',
            '123456789',
            'MYR',
            '100.00',
            '2026-02-06 12:00:00',
            'secret'
        );

        $expected = hash_hmac(
            'sha256',
            'INV-001CIMB123456789MYR100.002026-02-06 12:00:00',
            'secret'
        );

        $this->assertEquals($expected, $hash);
    }

    public function test_generate_payout_callback_signature(): void
    {
        $trait = $this->createTraitInstance();
        $hash = $trait->test_generate_payout_callback_signature(
            'INV-001',
            '1',
            'MYR',
            '100.00',
            '2026-02-06 12:00:00',
            'secret'
        );

        $expected = hash_hmac(
            'sha256',
            'INV-0011MYR100.002026-02-06 12:00:00',
            'secret'
        );

        $this->assertEquals($expected, $hash);
    }

    public function test_success_response(): void
    {
        $trait = $this->createTraitInstance();
        $response = $trait->test_success_response();

        $this->assertEquals(0, $response['error_code']);
        $this->assertEquals('Operation Success', $response['message']);
    }

    public function test_fail_response(): void
    {
        $trait = $this->createTraitInstance();
        $response = $trait->test_fail_response();

        $this->assertEquals(-1, $response['error_code']);
        $this->assertEquals('Operation Failed', $response['message']);
    }
}
