<?php

declare(strict_types=1);

namespace App\Vendors\PlayTechSW;

/**
 *  ptsw_bet_record.status
 */
enum BetRecordStatusEnum: int
{
    /** 進行中 */
    case IN_PROGRESS = 1;
    /** 退款 */
    case REFUND = 2;
    /** 已結算 */
    case SETTLE = 3;
}
