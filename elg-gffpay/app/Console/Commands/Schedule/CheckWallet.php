<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Wallet;
use App\Services\TonGateway;
use App\Services\TonService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Olifanton\Ton\AddressState;

/**
 * 每分鐘檢查錢包狀態
 */
class CheckWallet extends Command
{
    /**
     * php artisan schedule:check_wallet
     */
    protected $signature = 'schedule:check_wallet';

    // 冷卻時間 (小時)
    protected $coolingHours = 3;

    // 最後付款時間
    protected $maxPaymentMinute = 60;

    public function __construct(
        protected TonGateway $tonGateway,
        protected TonService $tonService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->line('Ton_log, Check Wallet start');
        $wallets = Wallet::query()
            ->with(['orders' => function ($query) {
                $query->where('created_at', '>=', Carbon::now()->subHours($this->coolingHours));
            }])
            ->whereIn('status', [WalletStatusEnum::COOLING->value, WalletStatusEnum::PENDING->value])
            ->get();

        foreach ($wallets as $wallet) {
            // 如果冷卻時間內沒有訂單, 則將錢包狀態設為閒置
            if (count($wallet->orders) == 0) {
                $wallet->status = WalletStatusEnum::IDLE->value;
                $wallet->save();
                $this->info('Ton_log, Wallet ' . $wallet->id . ' change to idle');
            } else {
                $order = $wallet->orders->last();

                // 若最新一筆訂單已經超過最後付款時間, 則將錢包狀態設為冷卻中
                if ($order->status == OrderStatusEnum::PENDING->value) {
                    $pendingMinutes = (int) Carbon::now()->diffInMinutes($order->created_at, true);

                    if ($pendingMinutes > $this->maxPaymentMinute
                        && $wallet->status == WalletStatusEnum::PENDING->value
                    ) {
                        $wallet->status = WalletStatusEnum::COOLING->value;
                        $wallet->save();
                        $this->info('Ton_log, Wallet ' . $wallet->id . ' change to cooling');
                    }
                }

                // 若最新一筆訂單狀態為成功, 且上鏈時間已經超過5分鐘, 則去查詢錢包餘額, 因為上鏈時不會立刻更新餘額
                if (
                    $order->status == OrderStatusEnum::SUCCESS->value
                    && Carbon::now()->greaterThan(Carbon::parse($order->confirmed_at)->addMinutes(5))
                    && $order->wallet_balance === null
                ) {
                    try {
                        $walletBalance = $this->tonGateway->getAddressBalance($wallet->address);
                        $wallet->balance = $walletBalance;
                        $wallet->save();
                        $order->wallet_balance = $walletBalance;
                        $order->save();
                        $this->info('Ton_log, Wallet ' . $wallet->id . ' update balance: ' . $wallet->balance);
                    } catch (\Throwable $e) {
                        $this->error('Ton_log, Wallet ' . $wallet->id . ' get address balance error: ' . $e->getMessage());
                    }
                }
            }
        }

        // 檢查部屬狀態
        $wallets = Wallet::query()
            ->where('deployment_status', WalletDeploymentStatusEnum::DEPLOYING->value)
            ->where('updated_at', '<', Carbon::now()->subMinutes(1))
            ->get();

        foreach ($wallets as $wallet) {
            try {
                $state = $this->tonGateway->getState($wallet->address);
                $wallet->deployment_status = $state == AddressState::ACTIVE ? WalletDeploymentStatusEnum::DEPLOYED->value : WalletDeploymentStatusEnum::NOT_DEPLOYED->value;
                $wallet->save();

                if ($wallet->deployment_status == WalletDeploymentStatusEnum::DEPLOYED->value) {
                    $this->tonService->refreshWalletBalance($wallet);
                }
            } catch (\Throwable $e) {
                $this->error('Ton_log, Wallet ' . $wallet->id . ' get state error: ' . $e->getMessage());
            }
        }

        $this->info('Ton_log, Check wallet done');
    }
}
