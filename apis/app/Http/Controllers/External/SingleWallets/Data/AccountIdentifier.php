<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Data;

/**
 * 三方回調用來指認帳號的識別子：是 username 還是 session_token，以及它的值。
 *
 * 由 {@see \App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount::accountIdentifier()}
 * 回傳，{@see \App\Http\Controllers\External\SingleWallets\Concerns\ResolvesAccountAndVendorInfo::resolveAccount()}
 * 依 $column 查 {vendor}_accounts。
 *
 * session_token 是帳號表的選配欄位（migration 的 vendorAccountColumns 要帶 withSessionToken: [REDACTED] 才會建），
 * 只有以 token 指認玩家的遊戲商才有；帳號表沒有這個欄位的 controller 不能回 {@see self::sessionToken()}。
 */
final readonly class AccountIdentifier
{
    public const string COLUMN_USERNAME = 'username';

    public const string COLUMN_SESSION_TOKEN = [REDACTED]';

    private function __construct(
        public string $column,
        public string $value,
    ) {}

    public static function username(string $value): self
    {
        return new self(self::COLUMN_USERNAME, $value);
    }

    public static function sessionToken(string $value): self
    {
        return new self(self::COLUMN_SESSION_TOKEN, $value);
    }

    public function isSessionToken(): bool
    {
        return $this->column === self::COLUMN_SESSION_TOKEN;
    }
}
