<?php

declare(strict_types=1);

namespace App\Services\Payment\Enums;

/**
 * {@see \App\Models\OrderRequestLog} 的往返類型。
 *
 * 命名規則：`<業務>_<動作>`，出站與入站不另立前綴——方向看得出來（create／query 是我方發起，
 * callback 是三方發起），多一個維度只會讓值域變大。
 *
 * 🔴 **前綴只在寫入時用來推導 `orderable_type`（{@see self::orderableType()}），查詢一律比
 * `orderable_type` 本身**，不要在查詢點寫 `like 'deposit_%'`：推導集中在這裡只有一處會錯，
 * 散到每個查詢點則漏一個就跨型別撈到別人的紀錄（兩張訂單表的 id 空間重疊）。
 */
enum OrderRequestActionEnum: string
{
    /** 出站：我方向三方建立代收訂單 */
    case DEPOSIT_CREATE = 'deposit_create';

    /** 出站：我方向三方查代收單 */
    case DEPOSIT_QUERY = 'deposit_query';

    /** 入站：三方打我方的代收回調 */
    case DEPOSIT_CALLBACK = 'deposit_callback';

    /** 出站：我方向三方送代付請求 */
    case WITHDRAWAL_CREATE = 'withdrawal_create';

    /** 出站：我方向三方查代付單 */
    case WITHDRAWAL_QUERY = 'withdrawal_query';

    /** 入站：三方打我方的代付回調 */
    case WITHDRAWAL_CALLBACK = 'withdrawal_callback';

    /**
     * 這個動作對應的 `orderable_type`（morph alias，見 AppServiceProvider 的 morphMap）。
     *
     * 🔴 **對不到訂單時也要填**：來源 IP 被擋的回調在查訂單之前就 return，`orderable_id`
     * 只能是 NULL，但型別是已知的——不填的話那筆紀錄會被「先比型別」的查詢整個濾掉，
     * 而被擋的回調正是最需要被看到的那種。
     */
    public function orderableType(): string
    {
        return match ($this) {
            self::DEPOSIT_CREATE, self::DEPOSIT_QUERY, self::DEPOSIT_CALLBACK => 'deposit_order',
            self::WITHDRAWAL_CREATE, self::WITHDRAWAL_QUERY, self::WITHDRAWAL_CALLBACK => 'payout_record',
        };
    }
}
