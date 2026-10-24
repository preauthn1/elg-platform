<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTaxTypeEnum;
use App\Enums\WithdrawalFeeTierTypeEnum;
use App\Enums\WithdrawalTaxSourceEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Bank;
use App\Models\Member;

/**
 * 會員提款手續費解析 target/tickets#4353。
 *
 * - 會員所屬群組（啟用中）有設平台手續費階梯 → 走群組階梯，取「from <= 當日第幾筆」的最後一階；
 *   未達第一階 → 手續費 0。階梯百分比存百分比數值（3＝3%、2.5＝2.5%，非 0.03）。
 * - 否則（無群組／群組停用／未設階梯）→ 沿用銀行手續費：tax_type 0=固定、1=百分比（banks.tax 存小數，0.02＝2%）。
 *
 * 群組路徑必須守門：手續費 >= 提現金額即拋 1958（避免會員實拿 <= 0）。銀行路徑刻意不守門，維持現行行為。
 * 金額一律走 Common::num*（scale 5，對齊 withdrawal_orders.tax decimal(11,5)）。
 *
 * @knowledge rules/withdrawal-member-fee
 */
class WithdrawalFeeResolver
{
    private const int SCALE = 5;

    public function __construct(
        private MemberLimitResolver $memberLimitResolver,
    ) {}

    /**
     * @param  string  $amount  提現金額（會員輸入單位）
     * @param  int  $seq  本筆為當日第幾筆提款（GMT+8，從 1 起算）
     */
    public function resolve(Member $member, Bank $bank, string $amount, int $seq): WithdrawalFee
    {
        $tiers = $this->memberLimitResolver->resolveGroup($member)?->withdrawFeeTiers() ?? [];

        if ($tiers === []) {
            return new WithdrawalFee($this->bankTax($bank, $amount), WithdrawalTaxSourceEnum::BANK);
        }

        $tax = $this->tierTax($tiers, $amount, $seq);

        if (Common::numCompare($tax, $amount, self::SCALE) >= 0) {
            throw new ApiException(__('error_message.1958'));
        }

        return new WithdrawalFee($tax, WithdrawalTaxSourceEnum::MEMBER_GROUP);
    }

    /**
     * 銀行手續費：tax_type 見 BankTaxTypeEnum（百分比時 banks.tax 存小數，0.02＝2%）。
     */
    private function bankTax(Bank $bank, string $amount): string
    {
        $bankTax = Common::numAdd('0', (string) $bank->tax, self::SCALE);

        return BankTaxTypeEnum::tryFrom((int) $bank->tax_type) === BankTaxTypeEnum::FIXED
            ? $bankTax
            : Common::numMul($bankTax, $amount, self::SCALE);
    }

    /**
     * 群組階梯手續費：$tiers 已依 from 升冪（MemberGroup::withdrawFeeTiers）。
     *
     * @param  array<int, array{from: int, type: int, value: string}>  $tiers
     */
    private function tierTax(array $tiers, string $amount, int $seq): string
    {
        $matched = null;
        foreach ($tiers as $tier) {
            if ($tier['from'] > $seq) {
                break;
            }
            $matched = $tier;
        }

        if ($matched === null) {
            return Common::numAdd('0', '0', self::SCALE);
        }

        return match (WithdrawalFeeTierTypeEnum::from($matched['type'])) {
            WithdrawalFeeTierTypeEnum::PERCENT => Common::numDiv(Common::numMul($amount, $matched['value'], 10), 100, self::SCALE),
            WithdrawalFeeTierTypeEnum::FIXED => Common::numAdd($matched['value'], '0', self::SCALE),
        };
    }
}
