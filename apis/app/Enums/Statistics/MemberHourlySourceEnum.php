<?php

declare(strict_types=1);

namespace App\Enums\Statistics;

use App\Enums\CashFlowEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Jobs\UpdateMemberHourSummary;

/**
 * member_hourly_statistics 的充提來源六類（target/tickets#4333）。
 *
 * 每類各一組 `<value>_count`／`<value>_amount` 明細欄；三類充值相加＝deposit_count／deposit_amount，
 * 三類提現相加＝withdrawal_count／withdrawal_amount。判定依據全部追到寫入該狀態的程式：
 *
 *   THIRD_PARTY_DEPOSIT   三方充值：orders status=3 且金流商不是 AutoDeposit（三方回調上分）
 *   AUTO_DEPOSIT          自助上分：orders status=3 且 order_types.cash_flow_id=AutoDeposit（前台上傳憑證、後台審核上分）
 *   ADMIN_DEPOSIT         後台開分：transaction_logs type=4、order_id=0（客服系統與代理後台，不分 platform）
 *   THIRD_PARTY_WITHDRAWAL  三方提現：withdrawal_orders status=24（三方代付成功）
 *   AUTO_WITHDRAWAL         自助洗分：withdrawal_orders status=4（後台人工轉帳已轉帳）
 *   ADMIN_WITHDRAWAL        後台洗分：transaction_logs type=5、order_id=0（不分 platform）
 */
enum MemberHourlySourceEnum: string
{
    case THIRD_PARTY_DEPOSIT = 'third_party_deposit';
    case AUTO_DEPOSIT = 'auto_deposit';
    case ADMIN_DEPOSIT = 'admin_deposit';
    case THIRD_PARTY_WITHDRAWAL = 'third_party_withdrawal';
    case AUTO_WITHDRAWAL = 'auto_withdrawal';
    case ADMIN_WITHDRAWAL = 'admin_withdrawal';

    public function isDeposit(): bool
    {
        return match ($this) {
            self::THIRD_PARTY_DEPOSIT, self::AUTO_DEPOSIT, self::ADMIN_DEPOSIT => true,
            self::THIRD_PARTY_WITHDRAWAL, self::AUTO_WITHDRAWAL, self::ADMIN_WITHDRAWAL => false,
        };
    }

    /** 對應 UpdateMemberHourSummary 的總額型別 */
    public function summaryType(): string
    {
        return $this->isDeposit() ? UpdateMemberHourSummary::TYPE_DEPOSIT : UpdateMemberHourSummary::TYPE_WITHDRAWAL;
    }

    public function countColumn(): string
    {
        return $this->value . '_count';
    }

    public function amountColumn(): string
    {
        return $this->value . '_amount';
    }

    /** @return list<self> */
    public static function depositCases(): array
    {
        return [self::THIRD_PARTY_DEPOSIT, self::AUTO_DEPOSIT, self::ADMIN_DEPOSIT];
    }

    /** @return list<self> */
    public static function withdrawalCases(): array
    {
        return [self::THIRD_PARTY_WITHDRAWAL, self::AUTO_WITHDRAWAL, self::ADMIN_WITHDRAWAL];
    }

    /** 代收訂單依金流商分類：AutoDeposit（cash_flows.id 5，六站同 id）＝自助充值，其餘＝一般充值 */
    public static function forDepositOrder(?int $cashFlowId): self
    {
        return $cashFlowId === CashFlowEnum::DEPOSIT_AUTO_DEPOSIT ? self::AUTO_DEPOSIT : self::THIRD_PARTY_DEPOSIT;
    }

    /** 代付訂單依成功狀態分類；非成功狀態回 null */
    public static function forWithdrawalOrderStatus(int $status): ?self
    {
        return match ($status) {
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value => self::THIRD_PARTY_WITHDRAWAL,
            WithdrawalOrderStatusEnum::TRANSFERRED->value => self::AUTO_WITHDRAWAL,
            default => null,
        };
    }
}
