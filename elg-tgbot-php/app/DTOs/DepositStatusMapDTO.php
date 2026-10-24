<?php

namespace App\DTOs;

use App\Enums\DepositStatusEnum;
use App\Enums\ReplyMessageStatusEnum;

class DepositStatusMapDTO
{
    public function __construct(
        public readonly DepositStatusEnum $before,
        public readonly DepositStatusEnum $after,
        public readonly ?ReplyMessageStatusEnum $replyMessageStatus = null
    ) {}

    public static function start(): self
    {
        return new self(
            before            : DepositStatusEnum::START,
            after             : DepositStatusEnum::WAIT_ORDER_TYPE,
        );
    }

    public static function waitOrderType(): self
    {
        return new self(
            before            : DepositStatusEnum::WAIT_ORDER_TYPE,
            after             : DepositStatusEnum::WAIT_DEPOSIT_AMOUNT,
            replyMessageStatus: ReplyMessageStatusEnum::DEPOSIT_WAIT_DEPOSIT_AMOUNT
        );
    }

    public static function waitAmount(): self
    {
        return new self(
            before            : DepositStatusEnum::WAIT_DEPOSIT_AMOUNT,
            after             : DepositStatusEnum::DONE,
        );
    }
}
