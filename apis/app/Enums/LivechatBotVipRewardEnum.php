<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Livechat Bot 取得 VIP 設定時用到的 vip_rewards.id 對應。
 *
 * 數值來源已從 stored procedures 確認：
 * - SP_payout_vip_reward.sql：BirthdayRewardId=2、CommemorateRewardId=3、WeekRewardId=5、MonthRewardId=6
 * - SP_payout_vip_other_reward.sql：RebateRewardId=7、RescueRewardId=8
 * - SP_calc_member_vip_deposit_amount.sql：UpgradeRewardId=4
 *
 * LEVEL=1 為 VIP 等級獎勵（保留用於完整性，目前 Get VIP Setting 端點未使用）。
 */
enum LivechatBotVipRewardEnum: int
{
    case LEVEL = 1;

    case BIRTHDAY = 2;

    case COMMEMORATE = 3;

    case UPGRADE = 4;

    case WEEKLY_GIFT = 5;

    case MONTHLY_GIFT = 6;

    case DAILY_REBATE = 7;

    case WEEKLY_RESCUE = 8;
}
