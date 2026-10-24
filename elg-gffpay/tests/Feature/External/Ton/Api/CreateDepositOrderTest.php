<?php

namespace Tests\Feature\External\Ton\Api;

use App\Enums\Db\CurrencyCodeEnum;
use App\Enums\Db\ErrorCodeEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\Wallet;
use Tests\TestCase;

class CreateDepositOrderTest extends TestCase
{
    protected Merchant $merchant;

    protected Currency $currency;

    protected Wallet $wallet;

    protected string $secretKey = 'test_secret_key_123';

    protected function setUp(): void
    {
        parent::setUp();

        // 創建幣別
        $this->currency = Currency::create([
            'name' => 'TON/TON',
            'code' => CurrencyCodeEnum::TON_TON->value,
        ]);

        // 創建商家
        $this->merchant = Merchant::create([
            'name' => 'Test Merchant',
            'code' => 'TEST_MERCHANT_001',
            'secret_key' => $this->secretKey,
            'status' => 1,
        ]);

        // 創建商家餘額
        MerchantBalance::create([
            'merchant_id' => $this->merchant->id,
            'currency_id' => $this->currency->id,
            'balance' => '1000.000000000',
            'status' => 1,
        ]);

        // 創建錢包（用於代收）
        $this->wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => 'EQD0vdSA_NedR9uvbgN9EikRX-suesDxGeFg69XQMavfLqIo',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);
    }

    protected function generateSign(array $data, string $secretKey): string
    {
        unset($data['sign']);
        ksort($data);
        $signString = '';
        foreach ($data as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;

        return strtoupper(md5($signString));
    }

    // php artisan test tests/Feature/External/Ton/Api/CreateDepositOrderTest.php
    public function test_create_deposit_order_success(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
            'return_url' => 'https://example.invalid/return',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'order_no',
                    'merchant_order_no',
                    'amount',
                    'payment_url',
                ],
            ])
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
            ]);
    }

    public function test_create_deposit_order_without_return_url(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
            ]);
    }

    public function test_create_deposit_order_invalid_sign(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
            'sign' => 'invalid_sign',
        ];

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::INVALID_SIGN->value,
            ]);
    }

    public function test_create_deposit_order_merchant_not_found(): void
    {
        $data = [
            'merchant_code' => 'NON_EXISTENT_MERCHANT',
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::MERCHANT_NOT_FOUND->value,
            ]);
    }

    public function test_create_deposit_order_currency_not_supported(): void
    {
        // 創建另一個幣別，但商家不支持
        $otherCurrency = Currency::create([
            'name' => 'TON/USDT',
            'code' => CurrencyCodeEnum::TON_USDT->value,
        ]);

        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $otherCurrency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::CURRENCY_NOT_SUPPORTED->value,
            ]);
    }

    public function test_create_deposit_order_duplicate_order_no(): void
    {
        $merchantOrderNo = 'TEST_ORDER_DUPLICATE_' . time();
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => $merchantOrderNo,
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        // 第一次創建
        $response1 = $this->json('POST', 'api/deposit-order', $data);
        $response1->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
            ]);

        // 第二次創建相同訂單號
        $response2 = $this->json('POST', 'api/deposit-order', $data);
        $response2->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::ORDER_NUMBER_REPEAT->value,
            ]);
    }

    public function test_create_deposit_order_validation_error(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            // 缺少 merchant_order_no
            'currency_code' => $this->currency->code,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('POST', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::PARAMETER_FORMAT_ERROR->value,
            ]);
    }
}
