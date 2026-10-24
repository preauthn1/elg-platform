<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Common;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @knowledge rules/member-group-fee-tiers
 * @knowledge rules/withdrawal-member-fee
 */
class MemberGroup extends Model
{
    /** 平台手續費階梯 value 的小數位數（對齊 withdrawal_orders.tax decimal(11,5)） */
    private const int FEE_TIER_SCALE = 5;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'withdraw_fee_tiers' => 'array',
    ];

    public function bankAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BankAccount::class, 'member_group_bank_accounts', 'member_group_id', 'bank_account_id');
    }

    /**
     * 屬於本群組的會員（後台成員數統計用）。
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'member_group_id');
    }

    /**
     * 擁有者代理。
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * 自助存款可用帳號白名單（bank_accounts.id 清單）。
     *
     * @return int[]
     */
    public function bankAccountIds(): array
    {
        return $this->bankAccounts()->pluck('bank_accounts.id')->all();
    }

    /**
     * 平台手續費階梯（依 from 升冪）；未設定（null／空陣列）回 []。
     *
     * 每階 from＝當日第幾筆起適用（GMT+8）、type＝WithdrawalFeeTierTypeEnum、
     * value＝固定金額或百分比數值（3＝3%、2.5＝2.5%，非 0.03）。
     *
     * @return array<int, array{from: int, type: int, value: string}>
     */
    public function withdrawFeeTiers(): array
    {
        $tiers = $this->withdraw_fee_tiers;

        return is_array($tiers) ? self::normalizeWithdrawFeeTiers($tiers) : [];
    }

    /**
     * 階梯正規化：依 from 升冪排序，from/type 轉 int、value 正規化成 5 位小數字串（讀取與寫入共用，確保兩端形狀一致）。
     *
     * value 必須經 Common::numAdd 正規化、禁止只做 (string)：JSON float 0.00001 經 (string) 會變 "1.0E-5"，
     * bcmath 讀不了會拋 ValueError（float 直接交給 Common 以 number_format 轉字串）。scale 5 對齊 withdrawal_orders.tax decimal(11,5)。
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<int, array{from: int, type: int, value: string}>
     */
    public static function normalizeWithdrawFeeTiers(array $tiers): array
    {
        $normalized = array_map(static fn (array $tier): array => [
            'from' => (int) $tier['from'],
            'type' => (int) $tier['type'],
            'value' => Common::numAdd('0', is_float($tier['value']) ? $tier['value'] : (string) $tier['value'], self::FEE_TIER_SCALE),
        ], array_values($tiers));

        usort($normalized, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $normalized;
    }

    /**
     * 是否啟用平台手續費階梯（有任一階）。
     */
    public function hasWithdrawFeeTiers(): bool
    {
        return $this->withdrawFeeTiers() !== [];
    }
}
