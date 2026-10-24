<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Models\GgrPlan;
use App\Models\Member;
use App\Models\MemberBankAccount;

/**
 * 4121 會員資格篩選：把「帳戶驗證限制」（kyc_*，唯一性）不合格的直屬下線
 * 會員剔除，回傳可參與結算的 member id。
 *
 * 「領取前驗證條件」（verify_*）已於 2026-09-07 依使用者定案整組移除
 * （DB 欄位、表單畫面與本處篩選一併拿掉），本類別只保留唯一限制。
 *
 * 接在 SettlementRunner 取得直屬下線之後、三個 collector 之前，因此
 * GGR 歸集、獎金歸集、#4124 用戶人數判定三處共用同一份名單，符合工單
 * 「三處統一的會員範圍」。
 *
 * 判定對象是**會員**不是代理（2026-09-03 定案）：migration 註記的資料源為
 * member_bank_accounts／members.register_ip，且 is_validate 只有 members 有；
 * agents.email_validate 的語意是「是否需要認證」而非「已認證」，不可挪用。
 *
 * 規則（2026-09-03 使用者裁決，工單 #4121 未載明）：
 * - 唯一限制的比對範圍＝**該代理的直屬下線之間**，不跨代理線、不掃全站
 * - 一位會員在幾個「已開啟」的唯一項目上與線內他人撞值，就記幾次違規；
 *   違規數 >= kyc_violate_count 即排除（每項最多記 1 次，多筆銀行帳號不重複計）
 * - **次數 0 ＝ 不限制**（比照 #4123「0＝不限制」慣例）：該區完全不篩，
 *   不可解讀為「違規 0 項以上就排除」，否則預設值會把全部會員濾光
 * - 空值不算撞值：NULL／空字串／register_ip 0 皆視為未填，彼此不構成重複
 */
class MemberEligibilityFilter
{
    /** 唯一限制項目：plan 欄位 => 取值來源鍵 */
    private const array UNIQUE_FIELDS = [
        'kyc_unique_bank_account' => 'bank_account',
        'kyc_unique_contact_no' => 'mobile',
        'kyc_unique_email' => 'email',
        'kyc_unique_ip' => 'register_ip',
        'kyc_unique_name' => 'name',
    ];

    /**
     * @param  array<int, int>  $memberIds  直屬下線會員 id
     * @return array{member_ids: array<int, int>, kyc_excluded: int}
     */
    public function filter(GgrPlan $plan, array $memberIds): array
    {
        $violateCount = (int) $plan->kyc_violate_count;
        $uniqueOn = $this->enabledFields($plan, self::UNIQUE_FIELDS);

        // 0＝不限制；沒開任何項目時同樣不篩
        if ($memberIds === [] || $violateCount === 0 || $uniqueOn === []) {
            return ['member_ids' => $memberIds, 'kyc_excluded' => 0];
        }

        $violations = $this->countViolations(
            $uniqueOn,
            $memberIds,
            $this->memberProfiles($memberIds),
            $this->bankAccounts($memberIds),
        );

        $kept = [];
        $kycExcluded = 0;

        foreach ($memberIds as $memberId) {
            if (($violations[$memberId] ?? 0) >= $violateCount) {
                $kycExcluded++;

                continue;
            }

            $kept[] = $memberId;
        }

        return ['member_ids' => $kept, 'kyc_excluded' => $kycExcluded];
    }

    /**
     * 已開啟（=1）的項目來源鍵。
     *
     * @param  array<string, string>  $fields
     * @return array<int, string>
     */
    private function enabledFields(GgrPlan $plan, array $fields): array
    {
        $enabled = [];
        foreach ($fields as $column => $key) {
            if ((int) $plan->{$column} === 1) {
                $enabled[] = $key;
            }
        }

        return $enabled;
    }

    /**
     * 每位會員在各「已開啟」唯一項目上是否與線內他人撞值，撞幾項就是幾次違規。
     *
     * @param  array<int, string>  $uniqueOn
     * @param  array<int, int>  $memberIds
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<int, string>>  $bankAccounts
     * @return array<int, int> member id => 違規項數
     */
    private function countViolations(array $uniqueOn, array $memberIds, array $profiles, array $bankAccounts): array
    {
        $violations = [];

        foreach ($uniqueOn as $key) {
            // 值 => 持有該值的 member id 集合（同一會員多筆銀行帳號只計一次）
            $owners = [];

            foreach ($memberIds as $memberId) {
                foreach ($this->valuesFor($key, $profiles[$memberId] ?? [], $bankAccounts[$memberId] ?? []) as $value) {
                    $owners[$value][$memberId] = true;
                }
            }

            // 先收該項目的撞值者，跑完整項才計數：同一會員在同一項目有多個值
            // 撞到（例如兩張銀行帳號都與他人重複）仍只算 1 項違規
            $offenders = [];
            foreach ($owners as $holders) {
                if (count($holders) < 2) {
                    continue;
                }

                foreach (array_keys($holders) as $memberId) {
                    $offenders[$memberId] = true;
                }
            }

            foreach (array_keys($offenders) as $memberId) {
                $violations[$memberId] = ($violations[$memberId] ?? 0) + 1;
            }
        }

        return $violations;
    }

    /**
     * 單一唯一項目的比對值；未填（NULL／空字串／IP 0）回空陣列＝不參與撞值。
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, string>  $accounts
     * @return array<int, string>
     */
    private function valuesFor(string $key, array $profile, array $accounts): array
    {
        if ($key === 'bank_account') {
            return $accounts;
        }

        if ($key === 'name') {
            $name = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));

            return $name === '' ? [] : [$name];
        }

        if ($key === 'register_ip') {
            $ip = (int) ($profile['register_ip'] ?? 0);

            return $ip === 0 ? [] : [(string) $ip];
        }

        $value = trim((string) ($profile[$key] ?? ''));

        return $value === '' ? [] : [$value];
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array<int, array<string, mixed>>
     */
    private function memberProfiles(array $memberIds): array
    {
        $profiles = [];

        Member::query()
            ->whereIn('id', $memberIds)
            ->select([
                'id', 'first_name', 'last_name', 'mobile', 'email', 'register_ip',
            ])
            ->get()
            ->each(function ($row) use (&$profiles): void {
                $profiles[(int) $row->id] = [
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'mobile' => $row->mobile,
                    'email' => $row->email,
                    'register_ip' => $row->register_ip,
                ];
            });

        return $profiles;
    }

    /**
     * 啟用中的銀行帳號（一位會員可能多筆）。
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, array<int, string>>
     */
    private function bankAccounts(array $memberIds): array
    {
        $accounts = [];

        MemberBankAccount::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', 1)
            ->select(['member_id', 'account_no'])
            ->get()
            ->each(function ($row) use (&$accounts): void {
                $accountNo = trim((string) $row->account_no);
                if ($accountNo === '') {
                    return;
                }

                $accounts[(int) $row->member_id][] = $accountNo;
            });

        return $accounts;
    }
}
