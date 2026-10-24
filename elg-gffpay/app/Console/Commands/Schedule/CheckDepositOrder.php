<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Db\OrderLogTypeEnum;
use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\TonGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 每分鐘檢查待付款的代收訂單是否已付款
 * 將狀態設為超時前會再檢查一次
 */
class CheckDepositOrder extends Command
{
    /**
     * php artisan schedule:check_deposit_order
     */
    protected $signature = 'schedule:check_deposit_order';

    // 訂單建立後分鐘數在這陣列內的會查詢鏈上交易紀錄
    protected $queryMinuteArray = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 13, 16, 19, 22, 25, 28, 31, 34, 37, 40, 45, 50, 55, 60];

    // 最後付款時間
    protected $maxPaymentMinute = 60;

    // 查詢訂單範圍
    protected $maxQueryMinute = 70;

    public function __construct(
        protected TonGateway $tonGateway,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->line('Ton_log, Check Deposit Order start');
        $lastCreatedAt = Carbon::now()->subMinutes($this->maxQueryMinute)->toDateTimeString();
        $orders = Order::query()
            ->where('type', OrderTypeEnum::DEPOSIT->value)
            ->where('status', OrderStatusEnum::PENDING->value)
            ->where('created_at', '>=', $lastCreatedAt)
            ->get();

        $queryMinuteMap = array_flip($this->queryMinuteArray);
        foreach ($orders as $order) {
            $this->line('Ton_log, Deposit Order id: ' . $order->id);
            $transactions = $this->tonGateway->getTransactions($order->to_address);
            // 完全沒有紀錄可能是三方出問題了, 先跳過
            if (count($transactions) == 0) {
                continue;
            }

            DB::transaction(function () use ($order, $transactions, $queryMinuteMap) {
                $isSuccess = false;

                // 建立後多少分鐘
                $pendingMinutes = (int) Carbon::now()->diffInMinutes($order->created_at, true);

                // 若分鐘數不在這陣列內, 且小於最後一次的查詢時間, 則跳過這筆訂單
                if (!isset($queryMinuteMap[$pendingMinutes]) && $pendingMinutes <= $this->maxPaymentMinute) {
                    return;
                }

                if ($order->query_count < 127) {
                    $order->query_count++;
                }
                $order->last_query_at = Carbon::now();
                $order->save();
                // file_put_contents(__DIR__ .'/'. $order->id . '.json', json_encode($transactions));
                foreach ($transactions as $transaction) {
                    $utime = Carbon::createFromTimestamp($transaction['utime']); // 上鏈時間

                    // 若最新一筆交易紀錄的上鏈時間小於訂單建立時間,
                    // 代表建立訂單後沒有新的交易紀錄
                    // 直接跳過這筆訂單
                    if ($utime->lessThan($order->created_at)) {
                        break;
                    }

                    // 判斷交易成功
                    $sendFee = $transaction['fee'] ?? null; // 這是出款方的手續費
                    $lt = $transaction['transaction_id']['lt'] ?? null; // 這是交易紀錄的邏輯時間
                    $tx_hash = $transaction['transaction_id']['hash'] ?? null; // 這是交易紀錄的 block hash
                    $source = $transaction['in_msg']['source'] ?? null; // 出款方的地址 是可退回的地址
                    $destination = $transaction['in_msg']['destination'] ?? null; // 收款方的地址 是可退回的地址
                    $value = $transaction['in_msg']['value'] ?? null; // 這是出款方輸入的轉帳金額 單位是nano ton
                    $isTonTransaction = $this->tonGateway->isTonTransaction($transaction);

                    if ($value === null || empty($source) || empty($destination) || $lt === null || $tx_hash === null || !$isTonTransaction) {
                        continue;
                    }
                    // 比對金額與地址
                    // nano ton to ton
                    $tonValue = $this->tonGateway->fromNano((string) $value);

                    // bounceable address to unbounceable address
                    $transactionDestination = $this->tonGateway->toUnbounceableAddress($destination);
                    $orderDestination = $this->tonGateway->toUnbounceableAddress($order->to_address);
                    if (
                        bccomp($tonValue, $order->amount, 9) != 0
                        || $transactionDestination != $orderDestination
                    ) {
                        continue;
                    }

                    // 比對邏輯時間與 block hash
                    // 如果這筆交易紀錄已經有對應的訂單則跳過
                    // 放在比對金額與地址之後, 減少DB查詢次數
                    $checkLt = Order::query()->where('lt', $lt)->where('tx_hash', $tx_hash)->exists();
                    if ($checkLt) {
                        continue;
                    }

                    $isSuccess = true;
                    $sendFee = $this->tonGateway->fromNano((string) $sendFee);
                    $order->update([
                        'confirmed_at' => $utime,
                        'fee' => $sendFee,
                        'from_address' => $source,
                        'lt' => $lt,
                        'status' => OrderStatusEnum::SUCCESS->value,
                    ]);
                    $order->wallet->update([
                        'status' => WalletStatusEnum::COOLING->value,
                    ]);

                    OrderLog::query()->create([
                        'order_id' => $order->id,
                        'type' => OrderLogTypeEnum::TRANSACTION->value,
                        'response_data' => json_encode($transaction),
                        'http_code' => 200,
                    ]);
                }
            });
        }

        $this->line('Ton_log, Check Order done');
    }
}

// 代收 $transaction 範例
/*
{
  "@type": "raw.transaction",
  "address": {
    "@type": "accountAddress",
    "account_address": "EQBrXe0EJvzpiSGT_Mar6ScS6P9D-eGS_KSW_RYAjHdkdFHu"
  },
  "utime": 1758250991,
  "data": "te6cckECBwEAAZkAA7V2td7QQm/OmJIZP8xqvpJxLo/0P54ZL8pJb9FgCMd2R0AAAjpKwaGEEPhIBeaAF4uLPhS2eRHNX5TnZJXa6Njztu+8MACmKXGAAAI2zHSqzBaMzH7wABRhCfIoAQIDAQGgBACCcuENGQZFpV5nKAGHlAoSLOQdp1TAH7oQjVOKpVELXJzBoC/8TgziR+gH8igCXPm7mVBZYioa8MJFdE2vmV79QgYCGwzAkMhJDuaygBhgwY4RBQYAuUgBlfi3Iz5XYaegSd+6VJBKy9YD8lZo7Wua08KmnezGdGkAGtd7QQm/OmJIZP8xqvpJxLo/0P54ZL8pJb9FgCMd2R0Q7msoAAYII1oAAEdJV7oehNGZj84AAAAAQACeQHvsPQkAAAAAAAAAAAAdAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABbwAAAAAAAAAAAAAAAAS1FLaRJ5QuM990nhh8UYSKv4bVGu4tw/IIW8MYUE5+OBIcq9DM=",
  "transaction_id": {
    "@type": "internal.transactionId",
    "lt": "39190169000001",
    "hash": "d0NZhGi3xgJSetXiRZLK9hyVz7A8I5+MwaTB8nUtdp8="
  },
  "fee": "544657",
  "storage_fee": "148257",
  "other_fee": "396400",
  "in_msg": {
    "@type": "raw.message",
    "hash": "0JTNsgOtgGfe2BiRdVslwwhsb4C5Vf+ma4nekWB76J8=",
    "source": "EQDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NEzZ",
    "destination": "EQBrXe0EJvzpiSGT_Mar6ScS6P9D-eGS_KSW_RYAjHdkdFHu",
    "value": "1000000000",
    "extra_currencies": [],
    "fwd_fee": "266669",
    "ihr_fee": "0",
    "created_lt": "39190165000002",
    "body_hash": "P+k4lxWGmOTUc7dEFNdJNxaw/DpwMQk0hz8AGdqsyrQ=",
    "msg_data": {
      "@type": "msg.dataText",
      "text": ""
    },
    "message": ""
  },
  "out_msgs": []
}
*/
