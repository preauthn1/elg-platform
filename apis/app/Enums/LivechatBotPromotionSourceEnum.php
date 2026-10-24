<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Livechat Bot 活動資料來源。
 *
 * V1：events / event_details / event_processes / prize_members
 * V2：event_v2 / event_v2_details / event_v2_user_joins
 */
enum LivechatBotPromotionSourceEnum: string
{
    case V1 = 'v1';

    case V2 = 'v2';
}
