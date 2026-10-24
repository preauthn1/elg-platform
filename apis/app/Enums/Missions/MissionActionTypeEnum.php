<?php

declare(strict_types=1);

namespace App\Enums\Missions;

use App\Services\Frontend\Missions\Libs\BetSummary;
use App\Services\Frontend\Missions\Libs\BindBankCardSummary;
use App\Services\Frontend\Missions\Libs\BindEmailSummary;
use App\Services\Frontend\Missions\Libs\BindMobileSummary;
use App\Services\Frontend\Missions\Libs\DepositSummary;
use App\Services\Frontend\Missions\Libs\FillBirthdaySummary;
use App\Services\Frontend\Missions\Libs\LibInterface;
use App\Services\Frontend\Missions\Libs\RealnameValidationSummary;

enum MissionActionTypeEnum: string
{
    case DEPOSIT = 'deposit';

    case BET = 'bet';

    case BIND_MOBILE = 'bind_mobile';

    case BIND_EMAIL = 'bind_email';

    case BIND_BANK_CARD = 'bind_bank_card';

    case FILL_BIRTHDAY = 'fill_birthday';

    case REALNAME_VALIDATION = 'realname_validation';
    // const GAME = 'game';

    public function translate()
    {
        return match ($this) {
            self::DEPOSIT => __('missions.action_type.' . self::DEPOSIT->value),
            self::BET => __('missions.action_type.' . self::BET->value),
        };
    }

    public function getSummaryLib(): LibInterface
    {
        return match ($this) {
            self::DEPOSIT => new DepositSummary,
            self::BET => new BetSummary,
            self::BIND_MOBILE => new BindMobileSummary,
            self::BIND_EMAIL => new BindEmailSummary,
            self::BIND_BANK_CARD => new BindBankCardSummary,
            self::FILL_BIRTHDAY => new FillBirthdaySummary,
            self::REALNAME_VALIDATION => new RealnameValidationSummary,
            default => throw new \Exception("Invalid action type: {$this->value}")
        };
    }

    public function isOneTimeMission(): bool
    {
        return in_array($this, [
            self::BIND_MOBILE,
            self::BIND_EMAIL,
            self::BIND_BANK_CARD,
            self::FILL_BIRTHDAY,
            self::REALNAME_VALIDATION,
        ]);
    }
}
