<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\Order;
use App\Services\Payment\Data\QueryOrderResponseData;

/**
 * 代收查單能力（可選實作）：以我方訂單向三方查單筆代收訂單狀態。
 *
 * 與 {@see SupportsPayoutQuery} 刻意分開——那是綁定單次代付嘗試（WithdrawalPayoutRecord）
 * 的安全決策模型（誤判查無＝重複出款窗口），本介面是一般排查用的訂單狀態查詢，
 * 兩者語意不可互換、不可合併。
 *
 * 實作守則：查詢屬唯讀，禁止在實作內改動訂單；查詢失敗（連線／信封驗證）直接拋
 * ApiException，不回半套資料讓呼叫端誤判。
 */
interface SupportsDepositQuery
{
    public function queryOrder(Order $order): QueryOrderResponseData;
}
