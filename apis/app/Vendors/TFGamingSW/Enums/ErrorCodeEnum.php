<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Enums;

enum ErrorCodeEnum: int
{
    // Success
    case SUCCESS = 0;

    // Undefined
    case UNDEFINED = 1;

    // Withdrawal is not allowed within 3sec after member's last transaction
    case WITHDRAWAL_NOT_ALLOWED = 2;

    // Unknown or invalid member id/code
    case USER_NOT_FOUND = 3;

    // Invalid amount for withdrawal/deposit
    case INVALID_AMOUNT = 4;

    // Trying to withdraw more than current balance
    case INSUFFICIENT_BALANCE = 5;

    // Invalid operator id
    case INVALID_OPERATOR = 6;

    // Value requested is more or less than the allowed range
    case INVALID_RANGE = 7;

    // Post-data currency and Partner's currency is not matched
    case CURRENCY_MISMATCH = 8;

    // Invalid authentication credential
    case INVALID_AUTH = 9;

    // Account is frozen
    case ACCOUNT_FROZEN = 10;

    // Missing required fields
    case MISSING_FIELDS = 11;

    // Authentication credentials were not provided
    case AUTH_NOT_PROVIDED = 12;

    // Invalid table state
    case INVALID_TABLE_STATE = 13;

    // Wallet error
    case WALLET_ERROR = 14;

    // Invalid member state
    case INVALID_MEMBER_STATE = 15;
}
