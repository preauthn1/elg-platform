<?php

declare(strict_types=1);

namespace App\Vendors\Play8oy\Enums;

use App\Enums\Vendor\VendorTransferResultEnum;

/**
 * iGaming（Play8oy／SunCity）transaction/create 與 transaction/check 的回應碼（值沿用三方字面）
 *
 * 分類原則：**不確定就不自動退款；只有三方明確拒絕才 FAILED。**
 * 轉帳回 FAILED 時 MemberTransferService 會立即把錢退回玩家；若三方其實已入帳，就成了雙重入帳。
 * 因此「結果不明」的碼（未知錯誤、例外錯誤、三方內部呼叫 GTAPI 失敗、未列在文件的碼）一律 TIMEOUT，
 * 交給既有的轉帳補查與人工確認流程。
 *
 * -90610（我方交易號已存在）不算失敗：同一交易號重送才會撞到，代表前一次請求可能已成功入帳，
 * 回 FAILED 會把已入帳的錢再退一次。
 */
enum TransactionResponseCodeEnum: string
{
    case SUCCESS = '0';
    /** transaction/check：交易失敗 */
    case FAILURE = '-90001';
    case UNKNOWN_ERROR = '-90002';
    case UNEXPECTED_ERROR = '-90003';
    case PLAYER_NOT_EXIST = '-90208';
    case PLAYER_FROZEN = '-90212';
    case GTAPI_DEPOSIT_BALANCE_FAILURE = '-90500';
    case GTAPI_PLAYER_DATA_FAILURE = '-90501';
    case INVALID_BALANCE_FORMAT = '-90600';
    case ZERO_AMOUNT = '-90601';
    case DEPOSIT_ABOVE_MAXIMUM = '-90602';
    case DEPOSIT_BELOW_MINIMUM = '-90603';
    case WITHDRAWAL_ABOVE_MAXIMUM = '-90604';
    case WITHDRAWAL_BELOW_MINIMUM = '-90605';
    case PLAYER_BALANCE_INSUFFICIENT = '-90606';
    case MANAGER_BALANCE_INSUFFICIENT = '-90607';
    /** transaction/check：尚未有結果（pending） */
    case UNRESOLVED = '-90608';
    /** transaction/check：交易不存在 */
    case TRANSACTION_NOT_EXIST = '-90609';
    case EXTERNAL_TRANSACTION_ID_EXISTS = '-90610';
    case TRANSACTION_ID_BLANK = '-90615';
    case TRANSACTION_ID_TOO_LONG = '-90616';
    case WITHDRAWAL_WHILE_IN_GAME = '-90617';

    /**
     * transaction/create 回應碼是否為三方明確拒絕（確定沒有入帳／扣款，可安全退款）
     */
    public function isDefiniteTransferRejection(): bool
    {
        return match ($this) {
            self::PLAYER_NOT_EXIST,
            self::PLAYER_FROZEN,
            self::INVALID_BALANCE_FORMAT,
            self::ZERO_AMOUNT,
            self::DEPOSIT_ABOVE_MAXIMUM,
            self::DEPOSIT_BELOW_MINIMUM,
            self::WITHDRAWAL_ABOVE_MAXIMUM,
            self::WITHDRAWAL_BELOW_MINIMUM,
            self::PLAYER_BALANCE_INSUFFICIENT,
            self::MANAGER_BALANCE_INSUFFICIENT,
            self::TRANSACTION_ID_BLANK,
            self::TRANSACTION_ID_TOO_LONG,
            self::WITHDRAWAL_WHILE_IN_GAME => true,
            default => false,
        };
    }

    /**
     * transaction/create 回應碼 → 轉帳結果；未知碼與結果不明一律 TIMEOUT
     */
    public static function transferResult(string $code): VendorTransferResultEnum
    {
        $responseCode = self::tryFrom($code);

        return match (true) {
            $responseCode === self::SUCCESS => VendorTransferResultEnum::SUCCESS,
            $responseCode?->isDefiniteTransferRejection() === true => VendorTransferResultEnum::FAILED,
            default => VendorTransferResultEnum::TIMEOUT,
        };
    }

    /**
     * transaction/check 回應碼 → 轉帳結果；只有交易失敗與交易不存在為 FAILED，未知碼一律 TIMEOUT
     */
    public static function checkResult(string $code): VendorTransferResultEnum
    {
        return match (self::tryFrom($code)) {
            self::SUCCESS => VendorTransferResultEnum::SUCCESS,
            self::FAILURE,
            self::TRANSACTION_NOT_EXIST => VendorTransferResultEnum::FAILED,
            default => VendorTransferResultEnum::TIMEOUT,
        };
    }
}
