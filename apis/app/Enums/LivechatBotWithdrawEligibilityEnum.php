<?php

declare(strict_types=1);

namespace App\Enums;

enum LivechatBotWithdrawEligibilityEnum: string
{
    case ELIGIBLE = 'Eligible for withdrawal';

    case ACTIVE_PROMOTION = 'Not able to withdraw, the user has ongoing active promotion';

    case TURNOVER_NOT_MET = 'Not able to withdraw, turnover requirement not met';

    case INSUFFICIENT_BALANCE = 'Not able to withdraw, insufficient main wallet balance';
}
