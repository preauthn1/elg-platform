<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

/**
 * BigPayz Payin error_code / message (deposit start, transinfo, etc.).
 * Merged from API doc variants so each code has one label.
 *
 * 0   = Operation Success
 * 1   = Invalid parameter(s)
 * 102 = Invalid hash
 * 103 = Invalid IP
 * 104 = Amount less than or equal to zero
 * 105 = Decimal point must be zero
 * 108 = Invalid Merchant provider
 * 201 = Duplicate Reference Id
 * 205 = Minimum deposit limit is x.xx
 * 206 = Bank is unavailable at the moment
 * 207 = Failed to Access Bank
 * 209 = Unavailable at the moment
 * 210 = Merchant's Bank Account Not Available
 * 211 = Maximum deposit limit is x.xx
 * 214 = Bank Is Under Maintenance
 * 215 = We're busy in another transaction. Please try it again later
 * 216 = Your account is blacklisted. Please contact administrator.
 * 301 = Failed to Save Transaction
 */
enum BigPayzPayinErrorCodeEnum: int
{
    case SUCCESS = 0;
    case INVALID_PARAMETERS = 1;
    case INVALID_HASH = 102;
    case INVALID_IP = 103;
    case AMOUNT_LESS_OR_ZERO = 104;
    case DECIMAL_POINT_MUST_BE_ZERO = 105;
    case INVALID_MERCHANT_PROVIDER = 108;
    case DUPLICATE_REFERENCE_ID = 201;
    case MINIMUM_DEPOSIT_LIMIT = 205;
    case BANK_UNAVAILABLE = 206;
    case FAILED_TO_ACCESS_BANK = 207;
    case UNAVAILABLE_AT_MOMENT = 209;
    case MERCHANT_BANK_ACCOUNT_NOT_AVAILABLE = 210;
    case MAXIMUM_DEPOSIT_LIMIT = 211;
    case BANK_UNDER_MAINTENANCE = 214;
    case BUSY_ANOTHER_TRANSACTION = 215;
    case ACCOUNT_BLACKLISTED = 216;
    case FAILED_TO_SAVE_TRANSACTION = 301;

    public static function fromValue(int $code): self
    {
        return self::tryFrom($code) ?? self::FAILED_TO_SAVE_TRANSACTION;
    }

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Operation Success',
            self::INVALID_PARAMETERS => 'Invalid parameter(s)',
            self::INVALID_HASH => 'Invalid hash',
            self::INVALID_IP => 'Invalid IP',
            self::AMOUNT_LESS_OR_ZERO => 'Amount less than or equal to zero',
            self::DECIMAL_POINT_MUST_BE_ZERO => 'Decimal point must be zero',
            self::INVALID_MERCHANT_PROVIDER => 'Invalid Merchant provider',
            self::DUPLICATE_REFERENCE_ID => 'Duplicate Reference Id',
            self::MINIMUM_DEPOSIT_LIMIT => 'Minimum deposit limit is x.xx',
            self::BANK_UNAVAILABLE => 'Bank is unavailable at the moment',
            self::FAILED_TO_ACCESS_BANK => 'Failed to Access Bank',
            self::UNAVAILABLE_AT_MOMENT => 'Unavailable at the moment',
            self::MERCHANT_BANK_ACCOUNT_NOT_AVAILABLE => "Merchant's Bank Account Not Available",
            self::MAXIMUM_DEPOSIT_LIMIT => 'Maximum deposit limit is x.xx',
            self::BANK_UNDER_MAINTENANCE => 'Bank Is Under Maintenance',
            self::BUSY_ANOTHER_TRANSACTION => "We're busy in another transaction. Please try it again later",
            self::ACCOUNT_BLACKLISTED => 'Your account is blacklisted. Please contact administrator. ',
            self::FAILED_TO_SAVE_TRANSACTION => 'Failed to Save Transaction', // @phpstan-ignore match.alwaysTrue
            default => ''
        };
    }
}
