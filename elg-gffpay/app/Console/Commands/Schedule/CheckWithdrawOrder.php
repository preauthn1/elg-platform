<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Db\OrderLogTypeEnum;
use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\TonGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 每分鐘檢查待付款且已經呼叫轉帳 api 的代付訂單
 */
class CheckWithdrawOrder extends Command
{
    /**
     * php artisan schedule:check_withdraw_order
     */
    protected $signature = 'schedule:check_withdraw_order';

    // 最後一次的查詢時間, 超過這個時間則將訂單設為失敗
    protected $lastQueryMinute = 60;

    public function __construct(
        protected TonGateway $tonGateway,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->line('Ton_log, Check Withdraw Order start');
        $orders = Order::query()
            ->where('type', OrderTypeEnum::WITHDRAW->value)
            ->where('status', OrderStatusEnum::PENDING->value)
            ->where('is_transfer_api_called', 1)
            ->get();

        // 取得交易紀錄
        $transactionMap = $this->getTransactions($orders);

        foreach ($orders as $order) {
            $transactions = $transactionMap[$order->from_address]['transactions'];
            // 完全沒有紀錄可能是三方出問題了, 先跳過
            if (count($transactions) == 0) {
                continue;
            }

            DB::transaction(function () use ($order, $transactions) {
                $this->line('Ton_log, Withdraw Order id: ' . $order->id);
                $isSuccess = false;

                // 轉帳後多少分鐘
                $pendingMinutes = Carbon::now()->diffInMinutes($order->transfer_api_called_at, true);

                $order->query_count++;
                $order->last_query_at = Carbon::now();
                $order->save();
                foreach ($transactions as $transaction) {
                    $utime = Carbon::createFromTimestamp($transaction['utime']); // 上鏈時間

                    // 若最新一筆交易紀錄的上鏈時間小於轉帳時間,
                    // 代表轉帳後沒有新的交易紀錄
                    // 直接跳過這筆訂單
                    if ($utime->lessThan($order->transfer_api_called_at)) {
                        break;
                    }

                    // 判斷交易是否成功
                    $sendFee = $transaction['fee'] ?? null; // 這是出款方的手續費
                    $lt = $transaction['transaction_id']['lt'] ?? null; // 這是交易紀錄的邏輯時間
                    $tx_hash = $transaction['transaction_id']['hash'] ?? null; // 這是交易紀錄的 block hash
                    $source = $transaction['out_msgs'][0]['source'] ?? null; // 出款方的地址 是可退回的地址
                    $destination = $transaction['out_msgs'][0]['destination'] ?? null; // 收款方的地址 是可退回的地址
                    $value = $transaction['out_msgs'][0]['value'] ?? null; // 這是出款方輸入的轉帳金額 單位是nano ton
                    $message = $transaction['out_msgs'][0]['message'] ?? null; // 訂單編號
                    if ($value === null || empty($source) || empty($destination) || $lt === null || $tx_hash === null || $message === null) {
                        continue;
                    }

                    // 比對訂單編號
                    if ($message != (string) $order->order_no) {
                        continue;
                    }

                    // 比對金額與地址
                    // nano ton to ton
                    $tonValue = $this->tonGateway->fromNano((string) $value);

                    // bounceable address to unbounceable address
                    $transactionSource = $this->tonGateway->toUnbounceableAddress($source);
                    $orderSource = $this->tonGateway->toUnbounceableAddress($order->from_address);
                    $transactionDestination = $this->tonGateway->toUnbounceableAddress($destination);
                    $orderDestination = $this->tonGateway->toUnbounceableAddress($order->to_address);

                    if (
                        bccomp($tonValue, $order->amount, 9) != 0
                        || $transactionSource != $orderSource
                        || $transactionDestination != $orderDestination
                    ) {
                        continue;
                    }

                    // 比對邏輯時間與 block hash
                    // 如果這筆交易紀錄已經有對應的訂單則跳過
                    // 放在比對金額與地址之後, 因為這要查詢DB, 減少DB查詢次數
                    $checkLt = Order::query()->where('lt', $lt)->where('tx_hash', $tx_hash)->exists();
                    if ($checkLt) {
                        continue;
                    }

                    // 交易成功
                    $isSuccess = true;
                    $sendFee = $this->tonGateway->fromNano((string) $sendFee);
                    $order->update([
                        'confirmed_at' => $utime,
                        'fee' => $sendFee,
                        'tx_hash' => $tx_hash,
                        'lt' => $lt,
                        'status' => OrderStatusEnum::SUCCESS->value,
                    ]);

                    OrderLog::query()->create([
                        'order_id' => $order->id,
                        'type' => OrderLogTypeEnum::TRANSACTION->value,
                        'response_data' => json_encode($transaction),
                        'http_code' => 200,
                    ]);
                }

                if ($pendingMinutes > $this->lastQueryMinute && !$isSuccess) {
                    // 查詢時間超過表定最晚付款時間, 則將訂單設為失敗
                    // 過期的訂單不要設為失敗, 改由人工判定
                    // $order->update([
                    //     'status' => OrderStatusEnum::FAILED->value,
                    //     'error_message' => 'The payment time has expired, transaction not found',
                    // ]);
                }
            });
        }

        $this->line('Ton_log, Check Order done');
    }

    /**
     * 取得訂單的交易紀錄
     * 判斷是否需要翻頁查詢
     */
    private function getTransactions($orders): array
    {
        $transactionMap = [];
        foreach ($orders as $order) {
            $address = $order->from_address;
            if (isset($transactionMap[$address])) {
                if (Carbon::parse($order->transfer_api_called_at)->lessThan($transactionMap[$address]['min_transfer_api_called_at'])) {
                    $transactionMap[$address]['min_transfer_api_called_at'] = $order->transfer_api_called_at;
                }
            } else {
                $transactionMap[$address] = [
                    'min_transfer_api_called_at' => $order->transfer_api_called_at,
                    'transactions' => [],
                ];
            }
        }

        foreach ($transactionMap as $address => $row) {
            $transactions = $this->tonGateway->getTransactions($address);

            while (count($transactions) > 0) {
                $transactionMap[$address]['transactions'] = array_merge($transactionMap[$address]['transactions'], $transactions);
                $end = end($transactions);

                // 若是最後一筆交易紀錄的上鏈時間大於轉帳時間, 則繼續查詢
                if ($end['utime'] > Carbon::parse($row['min_transfer_api_called_at'])->timestamp) {
                    $transactions = $this->tonGateway->getTransactions($address, 10, $end['transaction_id']['lt'], $end['transaction_id']['hash']);
                } else {
                    break;
                }
            }
        }

        return $transactionMap;
    }
}

// 代付 $transaction 範例
/*
{
  "@type": "raw.transaction",
  "address": {
    "@type": "accountAddress",
    "account_address": "EQDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NEzZ"
  },
  "utime": 1758530902,
  "data": "te6cckECCgEAAmwAA7V8r8W5GfK7DT0CTv3SpIJWXrAfkrNHa1zWnhU072Yzo0AAAjv3xpR0HMdDjk3OjnY3ddGsYf/UQUnTiayT5orkTyxpl3l1fP1QAAI6YEMxWBaNENVgADRkRBUIAQIDAgHgBAUAgnLRPu3pSJbO/Z2X2yI7WG774AGyQCS+YThcEvFWT0db/eAE+UoFVFDteurNyj6/qJ3LIAtYqW4RYc95TFW/UD8sAhMMwEIRRhihhgRACAkB4YgBlfi3Iz5XYaegSd+6VJBKy9YD8lZo7Wua08KmnezGdGgA3pGHxc5Yw2Y9QFHeZbNxT2NVfQI5Vc8dG2bwCnwzQwpqiTta2iMoGnTyk0ehPhiU8HiMB7dD4528nk17YTSAIU1NGLtGiGyIAAAASAAMBgEB3wcAq0gBlfi3Iz5XYaegSd+6VJBKy9YD8lZo7Wua08KmnezGdGkAN3jn/W451RUvS0bP0SAkb21tVZ3a+RR4vlwqX5RI94vQF9eEAAAAAAAAAAAAAAAAAABAALFIAZX4tyM+V2GnoEnfulSQSsvWA/JWaO1rmtPCpp3sxnRpADd45/1uOdUVL0tGz9EgJG9tbVWd2vkUeL5cKl+USPeL0BfXhAAGCCNaAABHfvjSjoTRohqsQACdQZ2DE4gAAAAAAAAAABEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIABvyYMNQEwII0wAAAAAAAIAAAAAAAPMgwFSePKvXVGkfIzk6zWGweVW47wSDlsyV5WmZv/QgkBQFgxFpfc7",
  "transaction_id": {
    "@type": "internal.transactionId",
    "lt": "39305333000001",
    "hash": "DmMuECvyOkEmZMz3KC9b7Ga85a8DKhBjR1QQAw7ujkc="
  },
  "fee": "2503253",
  "storage_fee": "67653",
  "other_fee": "2435600",
  "in_msg": {
    "@type": "raw.message",
    "hash": "fTTYm5tu0sCbgPq7By4Y+mObKcuUBLybXtHAWAV/dCk=",
    "source": "",
    "destination": "EQDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NEzZ",
    "value": "0",
    "extra_currencies": [],
    "fwd_fee": "0",
    "ihr_fee": "0",
    "created_lt": "0",
    "body_hash": "G77bgcfj1hWm7whuE0png7+Nshkpm8I7Z9kHhMFQaHM=",
    "msg_data": {
      "@type": "msg.dataRaw",
      "body": "te6cckEBAgEAqQABnBvSMPi5yxhsx6gKO8y2binsaq+gRyq546Ns3gFPhmhhTVEna1tEZQNOnlJo9CfDEp4PEYD26Hxzt5PJr2wmkAQpqaMXaNENkQAAAAkAAQEAq0gBlfi3Iz5XYaegSd+6VJBKy9YD8lZo7Wua08KmnezGdGkAN3jn/W451RUvS0bP0SAkb21tVZ3a+RR4vlwqX5RI94vQF9eEAAAAAAAAAAAAAAAAAABAFi9aDg==",
      "init_state": ""
    },
    "message": "G9Iw+LnLGGzHqAo7zLZuKexqr6BHKrnjo2zeAU+GaGFNUSdrW0RlA06eUmj0J8MSng8RgPbofHO3\nk8mvbCaQBCmpoxdo0Q2RAAAACQAB\n"
  },
  "out_msgs": [
    {
      "@type": "raw.message",
      "hash": "B2up0BGv7quAFxkPi0c96YOqRBSU0H+mSwgmgkVKHdg=",
      "source": "EQDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NEzZ",
      "destination": "EQDd45_1uOdUVL0tGz9EgJG9tbVWd2vkUeL5cKl-USPeL-sO",
      "value": "100000000",
      "extra_currencies": [],
      "fwd_fee": "266669",
      "ihr_fee": "0",
      "created_lt": "39305333000002",
      "body_hash": "lqKW0iTyhcZ77pPDD4owkVfw2qNdxbh+QQt4YwoJz8c=",
      "msg_data": {
        "@type": "msg.dataRaw",
        "body": "te6cckEBAQEAAgAAAEysuc0=",
        "init_state": ""
      },
      "message": ""
    }
  ]
}
*/
