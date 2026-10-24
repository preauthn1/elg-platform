<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\TonGateway;
use App\Services\TonService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 每分鐘檢查待付款的代付訂單
 * 呼叫 Ton API 進行轉帳
 * 所有訂單都只進行一次呼叫, 失敗不再重試, 避免意外造成重複轉帳
 */
class CallWithdrawOrder extends Command
{
    /**
     * php artisan schedule:call_withdraw_order
     */
    protected $signature = 'schedule:call_withdraw_order';

    public function __construct(
        protected TonGateway $tonGateway,
        protected TonService $tonService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->line('Ton_log, Call Withdraw Order start');

        // 取得待付款的代付訂單
        $orders = Order::query()
            ->where('type', OrderTypeEnum::WITHDRAW->value)
            ->where('status', OrderStatusEnum::PENDING->value)
            ->where('is_transfer_api_called', 0)
            ->get();

        // 先查詢錢包餘額
        $walletIds = $orders->pluck('wallet_id')->unique()->toArray();
        $wallets = Wallet::query()
            ->whereIn('id', $walletIds)
            ->get();

        // 更新錢包餘額
        foreach ($wallets as $wallet) {
            $balance = $this->tonGateway->getAddressBalance($wallet->address);
            $this->line(sprintf('Ton_log, Wallet id: %d, address: %s, balance: %s ', $wallet->id, $wallet->address, $balance));
            $wallet->balance = $balance;
            $wallet->save();
        }

        foreach ($orders as $order) {
            DB::transaction(function () use ($order) {
                $this->line('Ton_log, Withdraw Order id: ' . $order->id);
                // 上鎖避免併發
                $wallet = Wallet::query()
                    ->lockForUpdate()
                    ->where('id', $order->wallet_id)
                    ->where('status', WalletStatusEnum::IDLE->value)
                    ->where('deployment_status', WalletDeploymentStatusEnum::DEPLOYED->value) // 排除尚未部屬的錢包
                    ->where('is_enabled', 1) // 排除停用的錢包
                    ->first();

                // 上鎖避免併發
                $order = Order::query()
                    ->lockForUpdate()
                    ->where('id', $order->id)
                    ->where('type', OrderTypeEnum::WITHDRAW->value)
                    ->where('status', OrderStatusEnum::PENDING->value)
                    ->where('is_transfer_api_called', 0)
                    ->first();

                if (!$order) {
                    return;
                }

                if (!$wallet) {
                    $order->update([
                        'status' => OrderStatusEnum::FAILED->value,
                        'error_message' => 'Wallet not found',
                    ]);
                    $this->line('Ton_log, Withdraw Order id: ' . $order->id . ' wallet not found');

                    return;
                }

                // 檢查錢包餘額
                $estimateFee = '0.05'; // 估計手續費
                $totalAmount = bcadd($order->amount, $estimateFee, 9);
                if (bccomp($wallet->balance, $totalAmount, 9) < 0) {
                    $order->update([
                        'status' => OrderStatusEnum::FAILED->value,
                        'error_message' => 'Wallet balance not enough',
                    ]);
                    $this->line('Ton_log, Withdraw Order id: ' . $order->id . ' wallet balance not enough');

                    return;
                }

                // 檢查呼叫時錢包地址是否為訂單錢包地址
                $gatewayAddress = $this->tonGateway->getSubWalletAddress($wallet->sub_wallet_id);
                if ($gatewayAddress !== $order->from_address) {
                    $order->update([
                        'status' => OrderStatusEnum::FAILED->value,
                        'error_message' => 'from address not match gateway wallet address',
                    ]);
                    $this->line('Ton_log, Withdraw Order id: ' . $order->id . ' wallet address not match');

                    return;
                }

                // 檢查目的地址是否合法
                if (!$this->tonGateway->isValidateAddress($order->to_address)) {
                    $order->update([
                        'status' => OrderStatusEnum::FAILED->value,
                        'error_message' => 'to address is not valid',
                    ]);
                    $this->line('Ton_log, Withdraw Order id: ' . $order->id . ' to address is not valid');
                }

                // 更新錢包餘額
                $wallet->update([
                    'balance' => bcsub($wallet->balance, $totalAmount, 9),
                ]);
                $this->line(sprintf('Ton_log, Withdraw Order id: %d, wallet balance: %s', $order->id, $wallet->balance));

                try {
                    $this->tonGateway->transfer($order->to_address, $order->amount, $wallet->sub_wallet_id, (string) $order->order_no);
                    $this->line('Ton_log, Withdraw Order id: ' . $order->id . ' transfer done');

                    // 更新訂單
                    $order->update([
                        'is_transfer_api_called' => 1,
                        'wallet_balance' => $wallet->balance,
                        'transfer_api_called_at' => Carbon::now(),
                    ]);
                    $this->line(sprintf('Ton_log, Withdraw Order id: %d, update order done', $order->id));
                } catch (\Exception $e) {
                    $order->update([
                        'is_transfer_api_called' => 1,
                        'transfer_api_called_at' => Carbon::now(),
                        'status' => OrderStatusEnum::FAILED->value,
                        'error_message' => $e->getMessage(),
                    ]);
                    $this->line(sprintf('Ton_log, Withdraw Order id: %d, transfer failed: %s', $order->id, $e->getMessage()));

                    return;
                }
            });
        }

        $this->line('Ton_log, Call Withdraw Order done');
    }
}
