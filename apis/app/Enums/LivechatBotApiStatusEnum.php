<?php

declare(strict_types=1);

namespace App\Enums;

enum LivechatBotApiStatusEnum: string
{
    case PENDING = 'PENDING';

    case APPROVED = 'APPROVED';

    case REJECTED = 'REJECTED';

    public static function fromDepositStatus(OrderStatusEnum $status): self
    {
        return match ($status) {
            OrderStatusEnum::CREATED,
            OrderStatusEnum::SUCCESS,
            OrderStatusEnum::WAITING_MANUAL_DEPOSIT => self::PENDING,
            OrderStatusEnum::DEPOSIT_FINISHED => self::APPROVED,
            OrderStatusEnum::FAILED => self::REJECTED,
        };
    }

    public static function fromWithdrawalStatus(WithdrawalOrderStatusEnum $status): self
    {
        return match ($status) {
            WithdrawalOrderStatusEnum::ORDER_CREATED,
            WithdrawalOrderStatusEnum::PENDING_REVIEW,
            WithdrawalOrderStatusEnum::APPROVED,
            WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED,
            WithdrawalOrderStatusEnum::PAYMENT_PROCESSING => self::PENDING,
            WithdrawalOrderStatusEnum::TRANSFERRED,
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS => self::APPROVED,
            WithdrawalOrderStatusEnum::FAILED,
            WithdrawalOrderStatusEnum::REFUND,
            WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED => self::REJECTED,
        };
    }
}
