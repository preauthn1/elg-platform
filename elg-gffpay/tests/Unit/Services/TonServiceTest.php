<?php

namespace Tests\Unit\Services;

use App\Enums\Db\CurrencyCodeEnum;
use App\Enums\Db\ErrorCodeEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Enums\Db\SubWalletIdEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Exceptions\TonException;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\TonGateway;
use App\Services\TonService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use Olifanton\Ton\AddressState;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/TonServiceTest.php
 */
class TonServiceTest extends TestCase
{
    protected TonService $tonService;

    protected MockInterface|TonGateway $tonGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tonGateway = Mockery::mock(TonGateway::class);
        $this->tonService = new TonService($this->tonGateway);
    }

    /**
     * 情境：驗證幣別成功
     * 當幣別存在於資料庫時，應該成功返回幣別物件
     */
    public function test_verify_currency_success(): void
    {
        $currency = Currency::create([
            'name' => 'TON/TON',
            'code' => CurrencyCodeEnum::TON_TON->value,
        ]);

        $result = $this->tonService->verifyCurrency($currency->code);

        $this->assertInstanceOf(Currency::class, $result);
        $this->assertEquals($currency->id, $result->id);
        $this->assertEquals($currency->code, $result->code);
    }

    /**
     * 情境：驗證幣別失敗
     * 當幣別不存在於資料庫時，應該拋出 CURRENCY_NOT_SUPPORTED 異常
     */
    public function test_verify_currency_not_found(): void
    {
        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::CURRENCY_NOT_SUPPORTED->value);

        $this->tonService->verifyCurrency('NON_EXISTENT_CURRENCY');
    }

    /**
     * 情境：生成簽名
     * 驗證簽名算法正確性：移除 sign 欄位、按鍵排序、拼接字串、加上 secretKey、MD5 並轉大寫
     */
    public function test_sign(): void
    {
        $data = [
            'merchant_code' => 'TEST_MERCHANT',
            'merchant_order_no' => 'ORDER_001',
            'currency_code' => 'TON/TON',
            'amount' => '10.5',
            'sign' => 'OLD_SIGN',
        ];
        $secretKey = 'test_secret_key';

        $sign = $this->tonService->sign($data, $secretKey);

        $this->assertNotEmpty($sign);
        $this->assertEquals(32, strlen($sign)); // MD5 hash length
        $this->assertEquals(strtoupper($sign), $sign); // Should be uppercase

        // 驗證簽名算法：移除 sign，排序，拼接，加上 secretKey，MD5 大寫
        $expectedData = $data;
        unset($expectedData['sign']);
        ksort($expectedData);
        $signString = '';
        foreach ($expectedData as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;
        $expectedSign = strtoupper(md5($signString));

        $this->assertEquals($expectedSign, $sign);
    }

    /**
     * 情境：驗證簽名成功
     * 當簽名正確時，應該不拋出異常
     */
    public function test_verify_sign_success(): void
    {
        $data = [
            'merchant_code' => 'TEST_MERCHANT',
            'merchant_order_no' => 'ORDER_001',
            'currency_code' => 'TON/TON',
            'amount' => '10.5',
        ];
        $secretKey = 'test_secret_key';
        $data['sign'] = $this->tonService->sign($data, $secretKey);

        // 不應該拋出異常
        $this->tonService->verifySign($data, $secretKey);
        $this->assertTrue(true);
    }

    /**
     * 情境：驗證簽名失敗 - 空簽名
     * 當簽名欄位為空時，應該拋出 INVALID_SIGN 異常
     */
    public function test_verify_sign_empty_sign(): void
    {
        $data = [
            'merchant_code' => 'TEST_MERCHANT',
            'merchant_order_no' => 'ORDER_001',
        ];
        $secretKey = 'test_secret_key';

        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::INVALID_SIGN->value);

        $this->tonService->verifySign($data, $secretKey);
    }

    /**
     * 情境：驗證簽名失敗 - 無效簽名
     * 當簽名不匹配時，應該拋出 INVALID_SIGN 異常
     */
    public function test_verify_sign_invalid_sign(): void
    {
        $data = [
            'merchant_code' => 'TEST_MERCHANT',
            'merchant_order_no' => 'ORDER_001',
            'sign' => 'INVALID_SIGN',
        ];
        $secretKey = 'test_secret_key';

        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::INVALID_SIGN->value);

        $this->tonService->verifySign($data, $secretKey);
    }

    /**
     * 情境：選擇可用錢包成功
     * 當有可用的閒置錢包時，應該成功選擇並將狀態更新為 PENDING
     */
    public function test_pick_ton_wallet_success(): void
    {
        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $result = $this->tonService->pickTonWallet();

        $this->assertInstanceOf(Wallet::class, $result);
        $this->assertEquals($wallet->id, $result->id);
        // 驗證狀態已更新為 PENDING
        $result->refresh();
        $this->assertEquals(WalletStatusEnum::PENDING->value, $result->status);
    }

    /**
     * 情境：選擇可用錢包失敗
     * 當沒有可用的錢包時，應該拋出 NO_AVAILABLE_WALLET 異常
     */
    public function test_pick_ton_wallet_no_available_wallet(): void
    {
        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::NO_AVAILABLE_WALLET->value);

        $this->tonService->pickTonWallet(0, 1, 5); // 設置 maxRetry 為 1 以快速失敗
    }

    /**
     * 情境：取得主錢包成功
     * 當主錢包存在且已部署、啟用時，應該成功返回主錢包物件
     */
    public function test_get_main_wallet_success(): void
    {
        $mainWallet = Wallet::create([
            'sub_wallet_id' => SubWalletIdEnum::MAIN_WALLET->value,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '1000.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $result = $this->tonService->getMainWallet();

        $this->assertInstanceOf(Wallet::class, $result);
        $this->assertEquals($mainWallet->id, $result->id);
        $this->assertEquals(SubWalletIdEnum::MAIN_WALLET->value, $result->sub_wallet_id);
    }

    /**
     * 情境：取得主錢包失敗
     * 當主錢包不存在時，應該拋出 NO_AVAILABLE_WALLET 異常
     */
    public function test_get_main_wallet_not_found(): void
    {
        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::NO_AVAILABLE_WALLET->value);

        $this->tonService->getMainWallet();
    }

    /**
     * 情境：生成代收訂單號
     * 驗證代收訂單號格式：以 D 開頭，總長度為 17 位（D + 時間14位 + 計數器2位）
     */
    public function test_gen_order_no_deposit(): void
    {
        Redis::flushdb(); // 清除 Redis 數據

        $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);

        $this->assertStringStartsWith('D', $orderNo);
        $this->assertEquals(17, strlen($orderNo)); // D(1) + YmdHis(14) + 2位數字 = 17
    }

    /**
     * 情境：生成代付訂單號
     * 驗證代付訂單號格式：以 W 開頭，總長度為 17 位（W + 時間14位 + 計數器2位）
     */
    public function test_gen_order_no_withdraw(): void
    {
        Redis::flushdb();

        $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::WITHDRAW);

        $this->assertStringStartsWith('W', $orderNo);
        $this->assertEquals(17, strlen($orderNo)); // W(1) + YmdHis(14) + 2位數字 = 17
    }

    /**
     * 情境：生成歸集訂單號
     * 驗證歸集訂單號格式：以 S 開頭，總長度為 17 位（S + 時間14位 + 計數器2位）
     */
    public function test_gen_order_no_sweep(): void
    {
        Redis::flushdb();

        $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::SWEEP);

        $this->assertStringStartsWith('S', $orderNo);
        $this->assertEquals(17, strlen($orderNo)); // S(1) + YmdHis(14) + 2位數字 = 17
    }

    /**
     * 情境：訂單號計數器遞增
     * 驗證在同一時間窗口內，訂單號的計數器會正確遞增，前綴和時間部分相同
     */
    public function test_gen_order_no_counter_increment(): void
    {
        Redis::flushdb();

        $orderNo1 = $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);
        $orderNo2 = $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);

        // 兩個訂單號應該不同，但前綴和時間部分相同
        $this->assertNotEquals($orderNo1, $orderNo2);
        $this->assertEquals(substr($orderNo1, 0, 14), substr($orderNo2, 0, 14)); // 前綴和時間相同
    }

    /**
     * 情境：生成訂單號系統繁忙
     * 當同一秒內生成超過 99 個訂單號時，應該拋出 SYSTEM_BUSY 異常
     */
    public function test_gen_order_no_system_busy(): void
    {
        Redis::flushdb();

        // 使用固定時間確保在同一個時間窗口內
        $fixedTime = \Carbon\Carbon::parse('2024-01-01 12:00:00');
        \Carbon\Carbon::setTestNow($fixedTime);

        try {
            // 生成 99 個訂單號，應該都成功
            for ($i = 1; $i <= 99; $i++) {
                $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);
                $this->assertStringStartsWith('D', $orderNo);
            }

            // 第 100 個應該會拋出異常
            $this->expectException(TonException::class);
            $this->expectExceptionCode(ErrorCodeEnum::SYSTEM_BUSY->value);

            $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);
        } finally {
            \Carbon\Carbon::setTestNow(); // 恢復時間
        }
    }

    /**
     * 情境：檢查訂單號不存在
     * 當商家訂單號不存在時，應該不拋出異常
     */
    public function test_check_order_no_not_exists(): void
    {
        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'code' => 'TEST_MERCHANT',
            'secret_key' => 'test_key',
            'status' => 1,
        ]);

        // 不應該拋出異常
        $this->tonService->checkOrderNo('NON_EXISTENT_ORDER', $merchant->id);
        $this->assertTrue(true);
    }

    /**
     * 情境：檢查訂單號已存在
     * 當商家訂單號已存在時，應該拋出 ORDER_NUMBER_REPEAT 異常
     */
    public function test_check_order_no_exists(): void
    {
        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'code' => 'TEST_MERCHANT',
            'secret_key' => 'test_key',
            'status' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'TON/TON',
            'code' => CurrencyCodeEnum::TON_TON->value,
        ]);

        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $order = Order::create([
            'type' => OrderTypeEnum::DEPOSIT->value,
            'status' => 1,
            'wallet_id' => $wallet->id,
            'to_address' => $wallet->address,
            'order_no' => 'D2024010112000001',
            'merchant_order_no' => 'EXISTING_ORDER',
            'amount' => '10.5',
            'callback_url' => 'https://example.invalid/callback',
            'merchant_id' => $merchant->id,
            'currency_id' => $currency->id,
        ]);

        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::ORDER_NUMBER_REPEAT->value);

        $this->tonService->checkOrderNo('EXISTING_ORDER', $merchant->id);
    }

    /**
     * 情境：檢查錢包地址有效
     * 當地址格式有效時，應該不拋出異常
     */
    public function test_check_address_valid(): void
    {
        $validAddress = '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW';
        $this->tonGateway->shouldReceive('isValidateAddress')
            ->with($validAddress)
            ->once()
            ->andReturn(true);

        // 不應該拋出異常
        $this->tonService->checkAddress($validAddress);
        $this->assertTrue(true);
    }

    /**
     * 情境：檢查錢包地址無效
     * 當地址格式無效時，應該拋出 INVALID_ADDRESS 異常
     */
    public function test_check_address_invalid(): void
    {
        $invalidAddress = 'invalid_address';
        $this->tonGateway->shouldReceive('isValidateAddress')
            ->with($invalidAddress)
            ->once()
            ->andReturn(false);

        $this->expectException(TonException::class);
        $this->expectExceptionCode(ErrorCodeEnum::INVALID_ADDRESS->value);

        $this->tonService->checkAddress($invalidAddress);
    }

    /**
     * 情境：刷新錢包餘額
     * 從 TON Gateway 取得最新餘額並更新到資料庫
     */
    public function test_refresh_wallet_balance(): void
    {
        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $newBalance = '200.500000000';
        $this->tonGateway->shouldReceive('getAddressBalance')
            ->with($wallet->address)
            ->once()
            ->andReturn($newBalance);

        $result = $this->tonService->refreshWalletBalance($wallet);

        $this->assertEquals($newBalance, $result);
        $wallet->refresh();
        $this->assertEquals($newBalance, $wallet->balance);
    }

    /**
     * 情境：刷新錢包部署狀態 - 已激活
     * 當錢包狀態為 ACTIVE 時，應該更新部署狀態為 DEPLOYED
     */
    public function test_refresh_wallet_deployed_active(): void
    {
        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::NOT_DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $this->tonGateway->shouldReceive('getState')
            ->with($wallet->address)
            ->once()
            ->andReturn(AddressState::ACTIVE);

        $result = $this->tonService->refreshWalletDeployed($wallet);

        $this->assertEquals(AddressState::ACTIVE, $result);
        $wallet->refresh();
        $this->assertEquals(WalletDeploymentStatusEnum::DEPLOYED->value, $wallet->deployment_status);
    }

    /**
     * 情境：刷新錢包部署狀態 - 未初始化
     * 當錢包狀態為 UNINITIALIZED 時，應該保持部署狀態為 NOT_DEPLOYED
     */
    public function test_refresh_wallet_deployed_uninitialized(): void
    {
        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::NOT_DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $this->tonGateway->shouldReceive('getState')
            ->with($wallet->address)
            ->once()
            ->andReturn(AddressState::UNINITIALIZED);

        $result = $this->tonService->refreshWalletDeployed($wallet);

        $this->assertEquals(AddressState::UNINITIALIZED, $result);
        $wallet->refresh();
        $this->assertEquals(WalletDeploymentStatusEnum::NOT_DEPLOYED->value, $wallet->deployment_status);
    }

    /**
     * 情境：取得外部交易紀錄網址 - 主網
     * 當配置為主網時，應該返回 example.invalid 的網址
     */
    public function test_get_external_logs_url_mainnet(): void
    {
        config(['third-party.ton.ton_is_main_net' => true]);

        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $url = $this->tonService->getExternalLogsUrl($wallet);

        $this->assertStringContainsString('example.invalid', $url);
        $this->assertStringContainsString($wallet->address, $url);
    }

    /**
     * 情境：取得外部交易紀錄網址 - 測試網
     * 當配置為測試網時，應該返回 example.invalid 的網址
     */
    public function test_get_external_logs_url_testnet(): void
    {
        config(['third-party.ton.ton_is_main_net' => false]);

        $wallet = Wallet::create([
            'sub_wallet_id' => 1,
            'address' => '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW',
            'balance' => '100.000000000',
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::DEPLOYED->value,
            'is_enabled' => 1,
        ]);

        $url = $this->tonService->getExternalLogsUrl($wallet);

        $this->assertStringContainsString('example.invalid', $url);
        $this->assertStringContainsString($wallet->address, $url);
    }
}
