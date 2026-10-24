<?php

namespace App\Http\Controllers;

use App\Enums\Db\OrderTypeEnum;
use App\Models\Order;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;

#[Group('TON 加密貨幣')]
class FrontendController extends Controller
{
    /**
     * 代收收銀台頁面
     */
    public function cashier(string $orderNo)
    {
        $order = Order::query()
            ->where('order_no', $orderNo)
            ->where('type', OrderTypeEnum::DEPOSIT->value)
            ->first();

        if (!$order) {
            return view('ton.payment_error', ['error' => 'Order not found']);
        }

        return view('ton.payment', compact('order'));
    }

    /**
     * 預覽收銀台頁面（不產生訂單）
     */
    public function preview()
    {
        // 創建模擬訂單對象用於預覽
        $order = new Order([
            'order_no' => 'PREVIEW-' . time(),
            'amount' => '10.5',
            'to_address' => 'EQD__________________________________________0vo', // 示例 TON 地址
            'status' => 1, // 等待付款
            'return_url' => 'https://example.invalid/return',
        ]);
        $order->created_at = Carbon::now()->toSystemTime();

        return view('ton.payment', compact('order'));
    }
}
