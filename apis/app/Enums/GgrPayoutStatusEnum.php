<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 推廣代理 GGR 結算結果的發放狀態（GgrSettlement::payout_status）。
 *
 * 批次三只查詢顯示；狀態推進（發放入帳）屬後續發放批次，
 * 屆時要擴充狀態值再往本 enum 加，不要退回 magic number。
 */
enum GgrPayoutStatusEnum: int
{
    /**
     * 結算完成，尚未發放（初始狀態）
     */
    case UNPAID = 0;
    /**
     * 已發放
     */
    case PAID = 1;
}
