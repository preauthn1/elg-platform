<?php

namespace Tests\Feature\External\Ton\Api;

use App\Enums\Db\CurrencyCodeEnum;
use App\Enums\Db\ErrorCodeEnum;
use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\Order;
use App\Models\Wallet;
use Tests\TestCase;

class QueryDepositOrderTest extends TestCase
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

        // 創建錢包
        $this->wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => 'EQD0vdSA_NedR9uvbgN9EikRX-suesDxGeFg69XQMavfLqIo',
            'balance' => '100.000000000',
            'status' => 1,
            'deployment_status' => 2,
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

    // php artisan test tests/Feature/External/Ton/Api/QueryDepositOrderTest.php
    public function test_query_deposit_order_success(): void
    {
        // 先創建一個訂單
        $merchantOrderNo = 'TEST_ORDER_' . time();
        $order = Order::create([
            'type' => OrderTypeEnum::DEPOSIT->value,
            'status' => OrderStatusEnum::PENDING->value,
            'wallet_id' => $this->wallet->id,
            'to_address' => $this->wallet->address,
            'order_no' => 'D' . date('YmdHis') . '01',
            'merchant_order_no' => $merchantOrderNo,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
            'merchant_id' => $this->merchant->id,
            'currency_id' => $this->currency->id,
        ]);

        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => $merchantOrderNo,
            'currency_code' => $this->currency->code,
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'order_no',
                    'merchant_order_no',
                    'amount',
                    'status',
                ],
            ])
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'data' => [
                    'merchant_order_no' => $merchantOrderNo,
                ],
            ]);
    }

    public function test_query_deposit_order_not_found(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'NON_EXISTENT_ORDER',
            'currency_code' => $this->currency->code,
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::ORDER_NOT_FOUND->value,
            ]);
    }

    public function test_query_deposit_order_invalid_sign(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
            'sign' => 'invalid_sign',
        ];

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::INVALID_SIGN->value,
            ]);
    }

    public function test_query_deposit_order_merchant_not_found(): void
    {
        $data = [
            'merchant_code' => 'NON_EXISTENT_MERCHANT',
            'merchant_order_no' => 'TEST_ORDER_' . time(),
            'currency_code' => $this->currency->code,
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::MERCHANT_NOT_FOUND->value,
            ]);
    }

    public function test_query_deposit_order_currency_not_supported(): void
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
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::CURRENCY_NOT_SUPPORTED->value,
            ]);
    }

    public function test_query_deposit_order_other_merchant_order(): void
    {
        // 創建另一個商家
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'code' => 'OTHER_MERCHANT_001',
            'secret_key' => 'other_secret_key',
            'status' => 1,
        ]);

        MerchantBalance::create([
            'merchant_id' => $otherMerchant->id,
            'currency_id' => $this->currency->id,
            'balance' => '1000.000000000',
            'status' => 1,
        ]);

        // 創建另一個商家的訂單
        $merchantOrderNo = 'OTHER_ORDER_' . time();
        Order::create([
            'type' => OrderTypeEnum::DEPOSIT->value,
            'status' => OrderStatusEnum::PENDING->value,
            'wallet_id' => $this->wallet->id,
            'to_address' => $this->wallet->address,
            'order_no' => 'D' . date('YmdHis') . '02',
            'merchant_order_no' => $merchantOrderNo,
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
            'merchant_id' => $otherMerchant->id,
            'currency_id' => $this->currency->id,
        ]);

        // 用第一個商家查詢另一個商家的訂單
        $data = [
            'merchant_code' => $this->merchant->code,
            'merchant_order_no' => $merchantOrderNo,
            'currency_code' => $this->currency->code,
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::ORDER_NOT_FOUND->value,
            ]);
    }

    public function test_query_deposit_order_validation_error(): void
    {
        $data = [
            'merchant_code' => $this->merchant->code,
            // 缺少 merchant_order_no
            'currency_code' => $this->currency->code,
        ];
        $data['sign'] = $this->generateSign($data, $this->secretKey);

        $response = $this->json('GET', 'api/deposit-order', $data);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::PARAMETER_FORMAT_ERROR->value,
            ]);
    }
}
