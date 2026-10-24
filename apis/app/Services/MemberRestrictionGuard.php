<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberRestrictionTargetEnum;
use App\Models\Member;
use App\Models\MemberRestrictionRule;

/**
 * 風控會員限制 enforcement（風控管理 > 會員限制）。
 *
 * 消費點：
 *   - AuthMember middleware（每次前台授權請求，停用=禁止登入 → 強制登出）
 *   - LoginController::validateMember（登入當下給明確錯誤）
 *   - WithdrawalService::checkMemberRestriction（提款鎖定=禁止申請提現，僅擋真出金路徑）
 *
 * 語意：
 *   - 只看規則自身的 status（總開關），**刻意不看 member_groups.status**——
 *     風控封鎖不因群組被停用而失效（與 MemberLimitResolver::resolveGroup 要求群組啟用的限額語意不同）。
 *   - 會員可能同時命中「會員型」與「群組型」兩筆規則，旗標取 OR（任一筆要求封鎖即封鎖）。
 *   - 查詢走 unique(target_type, target_id) point lookup；request 生命週期內 memo，
 *     同一請求多次消費（middleware + controller）不重複查 DB。
 */
class MemberRestrictionGuard
{
    /** @var array<int, array{is_disabled: bool, withdrawal_locked: bool}> 單一 request 內的旗標快取 */
    private array $flagCache = [];

    /** 是否禁止登入（是否停用=是） */
    public function isLoginBlocked(Member $member): bool
    {
        return $this->flags($member)['is_disabled'];
    }

    /** 是否禁止申請提現（提款鎖定=是） */
    public function isWithdrawalLocked(Member $member): bool
    {
        return $this->flags($member)['withdrawal_locked'];
    }

    /**
     * 取會員生效中的限制旗標（會員型＋群組型規則 OR 聚合）。
     *
     * @return array{is_disabled: bool, withdrawal_locked: bool}
     */
    private function flags(Member $member): array
    {
        if (array_key_exists($member->id, $this->flagCache)) {
            return $this->flagCache[$member->id];
        }

        $rules = MemberRestrictionRule::query()
            ->where('status', 1)
            ->where(function ($query) use ($member) {
                $query->where(function ($q) use ($member) {
                    $q->where('target_type', MemberRestrictionTargetEnum::MEMBER->morphAlias())
                        ->where('target_id', $member->id);
                });
                if ($member->member_group_id) {
                    $query->orWhere(function ($q) use ($member) {
                        $q->where('target_type', MemberRestrictionTargetEnum::MEMBER_GROUP->morphAlias())
                            ->where('target_id', $member->member_group_id);
                    });
                }
            })
            ->get(['is_disabled', 'withdrawal_locked']);

        return $this->flagCache[$member->id] = [
            'is_disabled' => $rules->contains(fn ($rule) => (int) $rule->is_disabled === 1),
            'withdrawal_locked' => $rules->contains(fn ($rule) => (int) $rule->withdrawal_locked === 1),
        ];
    }
}
