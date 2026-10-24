<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Db\OrderLogTypeEnum;
use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\TonGateway;
use App\Services\TonService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 每分鐘執行訂單callback
 */
class CallbackOrder extends Command
{
    /**
     * php artisan schedule:callback_order
     */
    protected $signature = 'schedule:callback_order';

    // callback 最大天數
    protected $callbackDays = 7;

    public function __construct(
        protected TonGateway $tonGateway,
        protected TonService $tonService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->line('Ton_log, Callback Order start');

        $orders = Order::query()
            ->with('merchant')
            ->where('created_at', '>=', Carbon::now()->subDays($this->callbackDays))
            ->where('is_callback_success', 0)
            ->whereIn('type', [OrderTypeEnum::DEPOSIT->value, OrderTypeEnum::WITHDRAW->value])
            ->whereIn('status', [OrderStatusEnum::SUCCESS->value, OrderStatusEnum::FAILED->value])
            ->get();

        // 依照已callback次數決定是否callback
        // 0次的話直接callback
        // 1~10次每5分鐘callback一次
        // 11~20次每小時callback一次
        // 21~30次每3小時callback一次
        // 31次以上每天callback一次
        // 直到最大天數後不再callback
        foreach ($orders as $order) {
            /** @var Order $order */
            $this->line('Ton_log, Callback Order id: ' . $order->id);
            $needCallback = false;
            $lastCallbackAt = Carbon::parse($order->last_callback_at);
            if ($order->callback_count == 0) {
                $needCallback = true;
            } elseif ($order->callback_count <= 10) {
                if ($lastCallbackAt->addMinutes(5)->lessThan(Carbon::now())) {
                    $needCallback = true;
                }
            } elseif ($order->callback_count <= 20) {
                if ($lastCallbackAt->addMinutes(60)->lessThan(Carbon::now())) {
                    $needCallback = true;
                }
            } elseif ($order->callback_count <= 30) {
                if ($lastCallbackAt->addMinutes(180)->lessThan(Carbon::now())) {
                    $needCallback = true;
                }
            } else {
                if ($lastCallbackAt->addMinutes(1440)->lessThan(Carbon::now())) {
                    $needCallback = true;
                }
            }
            if (!$needCallback) {
                continue;
            }

            $requestData = [
                'merchant_code' => $order->merchant->code,
                'currency_code' => $order->currency->code,
                'order_no' => $order->order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'amount' => $order->amount,
                'status' => $order->status,
            ];

            $secretKey = $order->merchant->secret_key;
            $requestData['sign'] = $this->tonService->sign($requestData, $secretKey);

            $response = null;
            $exception = null;
            $httpCode = 0;
            $responseRaw = null;
            $responseData = null;
            try {
                $response = Http::logAction('Ton_CallbackOrder')->timeout(10)->post($order->callback_url, $requestData);
                $httpCode = $response->status();
                $responseRaw = $response->body();
                $responseData = $response->json();
            } catch (Throwable $e) {
                $exception = $e->getMessage();
            }

            // 紀錄請求內容
            OrderLog::create([
                'order_id' => $order->id,
                'type' => OrderLogTypeEnum::CALLBACK->value,
                'request_url' => $order->callback_url,
                'request_data' => json_encode($requestData),
                'response_data' => $responseRaw,
                'http_code' => $httpCode,
                'exception' => $exception,
            ]);

            // 判斷是否成功
            if (
                $httpCode == 200
                && is_array($responseData)
                && isset($responseData['code'])
                && $responseData['code'] == 200
            ) {
                $order->is_callback_success = 1;
            }

            // 更新訂單
            $order->callback_count++;
            $order->last_callback_at = Carbon::now();
            $order->save();
        }
        $this->line('Ton_log, Callback Order done');
    }
}
