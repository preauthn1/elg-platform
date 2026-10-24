<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Db\ErrorCodeEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Enums\Db\SubWalletIdEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Exceptions\TonException;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Olifanton\Ton\AddressState;

class TonService
{
    public function __construct(
        protected TonGateway $tonGateway,
    ) {}

    /**
     * 驗證幣別
     *
     * @param  string  $currency_code  幣別代碼
     * @return Currency 幣別
     *
     * @throws TonException 幣別不存在
     */
    public function verifyCurrency(string $currency_code): Currency
    {
        $currency = Currency::query()
            ->where('code', $currency_code)
            ->first();

        if (!$currency) {
            throw new TonException(ErrorCodeEnum::CURRENCY_NOT_SUPPORTED);
        }

        return $currency;
    }

    /**
     * 驗簽
     *
     * @param  array  $input  輸入參數
     * @param  string  $secretKey  祕密金鑰
     *
     * @throws TonException 驗證失敗
     */
    public function verifySign(array $input, string $secretKey): void
    {
        $sign = $input['sign'] ?? '';
        if (empty($sign)) {
            throw new TonException(ErrorCodeEnum::INVALID_SIGN);
        }

        $caculateSign = $this->sign($input, $secretKey);
        if ($caculateSign !== $sign) {
            throw new TonException(ErrorCodeEnum::INVALID_SIGN);
        }
    }

    /**
     * 生成簽名
     *
     * @param  array  $input  輸入參數
     * @param  string  $secretKey  祕密金鑰
     * @return string 簽名
     */
    public function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);

        $parts = [];
        foreach ($input as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $signString = implode('&', $parts) . $secretKey;
        $sign = strtoupper(md5($signString));

        return $sign;
    }

    /**
     * 選擇一個可用的 wallet
     *
     * @param  int  $maxRetry  最大重試次數
     * @param  int  $sampleSize  隨機抽樣數量
     *
     * @throws TonException
     */
    public function pickTonWallet(int $maxRetry = 5, int $sampleSize = 5): Wallet
    {
        $retry = 0;

        while ($retry < $maxRetry) {
            // 1. 依照 id 排序抽樣 LIMIT $sampleSize
            $wallets = Wallet::query()
                ->where('status', WalletStatusEnum::IDLE->value)
                ->where('sub_wallet_id', '!=', SubWalletIdEnum::MAIN_WALLET->value)
                ->where('deployment_status', WalletDeploymentStatusEnum::DEPLOYED->value)
                ->where('is_enabled', 1)
                ->limit($sampleSize)
                ->orderBy('id')
                ->get();

            if ($wallets->isEmpty()) {
                throw new TonException(ErrorCodeEnum::NO_AVAILABLE_WALLET);
            }

            // 2. 遍歷抽樣結果，嘗試原子更新
            foreach ($wallets as $wallet) {
                $affected = Wallet::query()
                    ->where('id', $wallet->id)
                    ->where('status', WalletStatusEnum::IDLE->value)
                    ->update(['status' => WalletStatusEnum::PENDING->value]);

                if ($affected > 0) {
                    // 成功取得 wallet
                    return $wallet;
                }
            }

            // 3. 如果都被搶走了，短暫延遲後重試
            $retry++;
            if ($retry < $maxRetry) {
                usleep(rand(1000, 5000)); // 隨機短暫 sleep 避免重試碰撞
            }
        }

        throw new TonException(ErrorCodeEnum::NO_AVAILABLE_WALLET);
    }

    /**
     * 取得主錢包
     *
     * @throws TonException 沒有可用的錢包
     */
    public function getMainWallet(): Wallet
    {
        return DB::transaction(function () {
            $wallet = Wallet::query()
                ->where('sub_wallet_id', SubWalletIdEnum::MAIN_WALLET->value) // 這 id 是 ton 官方預設 id
                ->where('deployment_status', WalletDeploymentStatusEnum::DEPLOYED->value) // 排除尚未部屬的錢包
                ->where('is_enabled', 1) // 排除停用的錢包
                ->first();

            if (!$wallet) {
                throw new TonException(ErrorCodeEnum::NO_AVAILABLE_WALLET);
            }

            return $wallet;
        });
    }

    /**
     * 生成訂單編號
     *
     * 格式: YmdHis + 計數器的後2位數字
     * 1秒內最多生成99個訂單編號
     *
     * @return string 訂單編號
     *
     * @throws TonException 1秒內超過99個訂單編號
     */
    public function genOrderNo(OrderTypeEnum $type): string
    {
        $timeStr = now()->format('YmdHis');
        $counter = Redis::incr('order_no_counter:' . $timeStr);

        if ($counter == 1) {
            Redis::expire('order_no_counter:' . $timeStr, 60);
        }

        if ($counter > 99) {
            throw new TonException(ErrorCodeEnum::SYSTEM_BUSY);
        }

        $prefix = match ($type) {
            OrderTypeEnum::DEPOSIT => 'D',
            OrderTypeEnum::WITHDRAW => 'W',
            OrderTypeEnum::SWEEP => 'S',
        };

        return $prefix . $timeStr . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
    }

    /**
     * 檢查訂單號
     *
     * @param  string  $merchant_order_no  商家訂單號
     *
     * @throws TonException 訂單號已存在
     */
    public function checkOrderNo(string $merchant_order_no, int $merchant_id): void
    {
        $exists = Order::where('merchant_order_no', $merchant_order_no)
            ->where('merchant_id', $merchant_id)
            ->exists();

        if ($exists) {
            throw new TonException(ErrorCodeEnum::ORDER_NUMBER_REPEAT);
        }
    }

    /**
     * 檢查錢包地址
     *
     * @param  string  $address  錢包地址
     *
     * @throws TonException 錢包地址無效
     */
    public function checkAddress(string $address): void
    {
        if (!$this->tonGateway->isValidateAddress($address)) {
            throw new TonException(ErrorCodeEnum::INVALID_ADDRESS);
        }
    }

    /**
     * 請求 ton gateway 更新錢包餘額
     */
    public function refreshWalletBalance(Wallet $wallet): string
    {
        $balance = $this->tonGateway->getAddressBalance($wallet->address);
        $wallet->update(['balance' => $balance]);

        return $balance;
    }

    /**
     * 請求 ton gateway 更新錢包部屬狀態
     */
    public function refreshWalletDeployed(Wallet $wallet): AddressState
    {
        $state = $this->tonGateway->getState($wallet->address);
        $deploymentStatus = $state == AddressState::ACTIVE
            ? WalletDeploymentStatusEnum::DEPLOYED->value
            : WalletDeploymentStatusEnum::NOT_DEPLOYED->value;
        $wallet->update(['deployment_status' => $deploymentStatus]);

        return $state;
    }

    /**
     * 請求 ton gateway 部屬錢包
     */
    public function deployWallet(Wallet $wallet): void
    {
        if (
            $wallet->deployment_status == WalletDeploymentStatusEnum::DEPLOYED->value
            || $wallet->deployment_status == WalletDeploymentStatusEnum::DEPLOYING->value
        ) {
            throw new TonException(ErrorCodeEnum::WALLET_DEPLOYMENT_STATUS_ERROR);
        }

        $state = $this->refreshWalletDeployed($wallet);
        if ($state == AddressState::ACTIVE) {
            return;
        }

        if ($state !== AddressState::UNINITIALIZED) {
            throw new TonException(ErrorCodeEnum::WALLET_DEPLOYMENT_STATUS_ERROR);
        }

        DB::transaction(function () use ($wallet) {
            // 先檢查主錢包餘額
            $mainWallet = $this->getMainWallet();
            if (bccomp($mainWallet->balance, '0.1', 9) < 0) {
                throw new TonException(ErrorCodeEnum::BALANCE_NOT_ENOUGH);
            }

            // 部屬費用 0.05
            $this->tonGateway->deployWallet($wallet->sub_wallet_id);

            // 更新錢包狀態
            $wallet->update([
                'deployment_status' => WalletDeploymentStatusEnum::DEPLOYING->value,
            ]);

            // 更新主錢包餘額
            $mainWallet->update([
                'balance' => DB::raw('balance - 0.05'),
            ]);
        });
    }

    /**
     * 取得外部交易紀錄網址
     */
    public function getExternalLogsUrl(Wallet $wallet): string
    {
        if (config('third-party.ton.ton_is_main_net')) {
            return 'https://example.invalid/address/' . $wallet->address;
        } else {
            return 'https://example.invalid/address/' . $wallet->address;
        }
    }

    /**
     * 歸集錢包
     */
    public function sweepWallet(Wallet $wallet): string
    {
        $amount = $this->tonGateway->transferBackToMain($wallet->sub_wallet_id);
        if (bccomp($amount, '0', 9) == 0) {
            return '0';
        }

        DB::transaction(function () use ($wallet, $amount) {
            $mainWallet = $this->getMainWallet();
            $mainWallet->update([
                'balance' => DB::raw('balance + ' . $amount),
            ]);
            $wallet->update(['balance' => '0']);
        });

        return $amount;
    }
}
