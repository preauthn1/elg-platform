<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\PayoutQueryResult;

/**
 * 代付查單能力（target/tickets#4137 擴充點，可選實作）。
 *
 * 部分三方金流提供「以我方交易號查詢代付單」的 API。provider 的 Driver 實作本介面後，
 * 停留於代付請求中(21)的訂單（timeout／pod 中斷）會在人工介入前由排程
 * withdrawal:alert-stuck-submitted 先行自動查證：
 *  - FOUND_ACCEPTED **且附三方單號** → 自動補回三方單號並轉 22（回到等回調軌道）；
 *    未附單號不自動定案（缺單號的 22 會脫離掃描且雙條件對單商對不上回調），照舊告警
 *  - NOT_FOUND／UNKNOWN → 不自動改單，照舊告警交人工定案
 *    （查無不自動轉 29：查詢接口誤判「查無」＝自動開重複出款窗口，風險不對稱）
 *
 * 實作守則：查詢屬唯讀操作，禁止在實作內改動訂單／record；
 * 無法確定時一律回 UNKNOWN，禁止把「查詢失敗」推斷成 NOT_FOUND。
 */
interface SupportsPayoutQuery
{
    public function queryPayout(WithdrawalPayoutRecord $record): PayoutQueryResult;
}
