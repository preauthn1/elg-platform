<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Models\Bank;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 偵測 banks 表中 active(status=1) 的重複列。
 *
 * 重複定義：同一自然鍵 (agent_group_id, type, currency_id, code) 有多於一筆 status=1 列。
 * 每組以最新 created_at 為 survivor（相同 created_at 時取較大 id），其餘為 loser。
 */
class BankActiveDedupService
{
    use PreservesRowTimestamps;

    /**
     * @param  list<int>|null  $agentGroupIds  限定範疇的 agent_group_id；null = 整庫
     * @return Collection<int, array{
     *     agent_group_id: int,
     *     type: int,
     *     currency_id: int|null,
     *     code: string,
     *     survivor_id: int,
     *     loser_ids: array<int, int>
     * }>
     */
    public function detectDuplicateGroups(?array $agentGroupIds = null): Collection
    {
        $rows = Bank::query()
            ->where('status', 1)
            ->when($agentGroupIds !== null, static fn ($query) => $query->whereIn('agent_group_id', $agentGroupIds))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'agent_group_id', 'type', 'currency_id', 'code', 'created_at']);

        /** @var array<string, list<Bank>> $byKey */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->naturalKey($row)][] = $row;
        }

        /** @var list<array{agent_group_id: int, type: int, currency_id: int|null, code: string, survivor_id: int, loser_ids: array<int, int>}> $groups */
        $groups = [];
        foreach ($byKey as $members) {
            if (count($members) < 2) {
                continue;
            }

            // 已依 created_at desc, id desc 排序 → 第一筆即 survivor
            $survivor = $members[0];
            $losers = array_slice($members, 1);

            $groups[] = [
                'agent_group_id' => (int) $survivor->agent_group_id,
                'type' => (int) $survivor->type,
                'currency_id' => $survivor->currency_id !== null ? (int) $survivor->currency_id : null,
                'code' => (string) $survivor->code,
                'survivor_id' => (int) $survivor->id,
                'loser_ids' => array_map(fn (Bank $member): int => (int) $member->id, $losers),
            ];
        }

        // 型別與宣告完全相同，PHPStan 因 Collection TValue invariant 誤報，抑制此已知 false-positive
        return collect($groups); // @phpstan-ignore return.type
    }

    private function naturalKey(Bank $bank): string
    {
        return implode('|', [
            $bank->agent_group_id,
            $bank->type,
            $bank->currency_id ?? 'null',
            $bank->code,
        ]);
    }

    /**
     * 偵測 inactive shadow 重複：同 (agent_group_id, type, currency_id, name) 恰 1 筆 active
     * ＋ n 筆 inactive（deleted_at IS NULL）。
     *
     * shadow 列雖不佔 active 唯一鍵，但以 name 為搜尋鍵的同步（如 AddAgentCashFlow）
     * 可能撈到它並重新啟用而撞 uk_banks_active（TPower AGRO id 776/1300 實案）。
     * 同名多 active 屬 active 重複（detectDuplicateGroups／人工）職責；
     * 全 inactive 無正身可指，均不在此範疇。
     *
     * @param  list<int>|null  $agentGroupIds  限定範疇的 agent_group_id；null = 整庫
     * @return Collection<int, array{
     *     agent_group_id: int,
     *     type: int,
     *     currency_id: int|null,
     *     code: string,
     *     name: string,
     *     survivor_id: int,
     *     loser_ids: array<int, int>
     * }>
     */
    public function detectInactiveShadowGroups(?array $agentGroupIds = null): Collection
    {
        $rows = Bank::query()
            ->whereNull('deleted_at')
            ->when($agentGroupIds !== null, static fn ($query) => $query->whereIn('agent_group_id', $agentGroupIds))
            ->orderBy('id')
            ->get(['id', 'agent_group_id', 'type', 'currency_id', 'code', 'name', 'status']);

        /** @var array<string, list<Bank>> $byKey */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->nameScopeKey($row)][] = $row;
        }

        /** @var list<array{agent_group_id: int, type: int, currency_id: int|null, code: string, name: string, survivor_id: int, loser_ids: array<int, int>}> $groups */
        $groups = [];
        foreach ($byKey as $members) {
            if (count($members) < 2) {
                continue;
            }

            $actives = array_values(array_filter($members, fn (Bank $member): bool => (int) $member->status === 1));
            if (count($actives) !== 1) {
                continue;
            }

            $survivor = $actives[0];
            $shadows = array_values(array_filter($members, fn (Bank $member): bool => (int) $member->status !== 1));

            $groups[] = [
                'agent_group_id' => (int) $survivor->agent_group_id,
                'type' => (int) $survivor->type,
                'currency_id' => $survivor->currency_id !== null ? (int) $survivor->currency_id : null,
                'code' => (string) $survivor->code,
                'name' => (string) $survivor->name,
                'survivor_id' => (int) $survivor->id,
                'loser_ids' => array_map(fn (Bank $member): int => (int) $member->id, $shadows),
            ];
        }

        // 型別與宣告完全相同，PHPStan 因 Collection TValue invariant 誤報，抑制此已知 false-positive
        return collect($groups); // @phpstan-ignore return.type
    }

    private function nameScopeKey(Bank $bank): string
    {
        // name 以 case-fold 分組：MySQL ci collation 下同名搜尋（如 AddAgentCashFlow 的 where name）
        // 會匹配大小寫變體（TPower MUFG BANK/MUFG Bank 實案），偵測不對齊會漏清變體 shadow
        return implode('|', [
            $bank->agent_group_id,
            $bank->type,
            $bank->currency_id ?? 'null',
            $this->nameKey((string) $bank->name),
        ]);
    }

    /**
     * 清理所有 inactive shadow 重複組：合併會員綁定至 active 正身、shadow 列標記 deleted_at。
     *
     * shadow 與 survivor 同名（分組即以 name 為鍵），視為同一銀行，無 code 重用疑慮，
     * 故不設 flagged 安全閘。Bank model 無 SoftDeletes，deleted_at 僅為 dedup 清理標記，
     * 歷史訂單的 belongsTo 仍解析得到。
     *
     * @param  list<int>|null  $agentGroupIds  限定範疇的 agent_group_id；null = 整庫
     * @return array{groups: int, deactivated_bindings: int, repointed: int, repointed_realname_requests: int, soft_deleted_banks: int}
     */
    public function executeShadowCleanup(?array $agentGroupIds = null): array
    {
        $summary = ['groups' => 0, 'deactivated_bindings' => 0, 'repointed' => 0, 'repointed_realname_requests' => 0, 'soft_deleted_banks' => 0];

        $groups = $this->detectInactiveShadowGroups($agentGroupIds);

        if ($groups->isEmpty()) {
            return $summary;
        }

        $connection = (string) config('database.default');

        DB::transaction(function () use ($groups, $connection, &$summary): void {
            foreach ($groups as $group) {
                $survivor = Bank::query()->select(['id', 'name', 'code'])->find($group['survivor_id']);

                foreach ($group['loser_ids'] as $loserId) {
                    // 情況1：會員同時綁 survivor 與 shadow → 停用 shadow 綁定
                    $summary['deactivated_bindings'] += $this->deactivateDuplicateBindings($group, (int) $survivor->id, $loserId, $connection);

                    // 情況2：會員只綁 shadow → re-point 至 survivor 並同步出款訂單
                    $summary['repointed'] += $this->repointSoleBindings($group, $survivor, $loserId, $connection);

                    // 待審核實名件 re-point：舊系統審核放行拿 bank_id 直建綁定且零狀態檢查
                    $summary['repointed_realname_requests'] += $this->repointPendingRealnameRequests($group, (int) $survivor->id, $loserId, $connection);

                    // shadow 標記刪除：讓以 name 為搜尋鍵的同步不再撈到（status 已為 0，不再停用）
                    Bank::query()->where('id', $loserId)->update(['deleted_at' => now()]);
                    $this->writeAudit($connection, $group, (int) $survivor->id, $loserId, null, null, 'soft_delete_bank', null);
                    $summary['soft_deleted_banks']++;
                }

                $summary['groups']++;
            }
        });

        return $summary;
    }

    /**
     * 實際清理所有 active 重複組：每組保留 survivor、合併會員綁定、停用 loser，全程交易並寫審計。
     *
     * @param  list<int>|null  $agentGroupIds  限定範疇的 agent_group_id；null = 整庫
     * @return array{groups: int, deactivated_bindings: int, repointed: int, repointed_realname_requests: int, deactivated_banks: int, flagged: int}
     */
    public function execute(?array $agentGroupIds = null): array
    {
        $summary = ['groups' => 0, 'deactivated_bindings' => 0, 'repointed' => 0, 'repointed_realname_requests' => 0, 'deactivated_banks' => 0, 'flagged' => 0];

        $groups = $this->detectDuplicateGroups($agentGroupIds);

        if ($groups->isEmpty()) {
            return $summary;
        }

        $connection = (string) config('database.default');

        DB::transaction(function () use ($groups, $connection, &$summary): void {
            foreach ($groups as $group) {
                $survivor = Bank::query()->select(['id', 'name', 'code'])->find($group['survivor_id']);

                foreach ($group['loser_ids'] as $loserId) {
                    // 安全閘：名稱 case-fold 不等 + loser 有綁定 → 疑似 code 重用（不同銀行），跳過合併轉人工複核
                    if ($this->isSuspectedCodeReuse($survivor, $loserId)) {
                        $loserName = (string) Bank::query()->where('id', $loserId)->value('name');
                        $this->writeAudit($connection, $group, (int) $survivor->id, $loserId, null, null, 'flagged_manual_review', 'loser_name=' . $loserName);
                        $summary['flagged']++;

                        continue;
                    }

                    // 情況1：會員同時綁 survivor 與 loser → 停用 loser 綁定
                    $summary['deactivated_bindings'] += $this->deactivateDuplicateBindings($group, (int) $survivor->id, $loserId, $connection);

                    // 情況2：會員只綁 loser → re-point 至 survivor 並同步出款訂單
                    $summary['repointed'] += $this->repointSoleBindings($group, $survivor, $loserId, $connection);

                    // 待審核實名件 re-point：舊系統審核放行拿 bank_id 直建綁定且零狀態檢查
                    $summary['repointed_realname_requests'] += $this->repointPendingRealnameRequests($group, (int) $survivor->id, $loserId, $connection);

                    // 停用 loser 銀行列（從 active 下拉消失，但 belongsTo 仍解析得到）
                    Bank::query()->where('id', $loserId)->update(['status' => 0]);
                    $this->writeAudit($connection, $group, (int) $survivor->id, $loserId, null, null, 'deactivate_bank', null);
                    $summary['deactivated_banks']++;
                }

                $summary['groups']++;
            }
        });

        return $summary;
    }

    /**
     * 情況1：會員同時擁有 survivor 與 loser 綁定時，停用其 loser 綁定（避免重複）。
     *
     * @param  array{agent_group_id: int, code: string}  $group
     */
    private function deactivateDuplicateBindings(array $group, int $survivorId, int $loserId, string $connection): int
    {
        $bindings = DB::table('member_bank_accounts')
            ->where('bank_id', $loserId)
            ->where('status', 1)
            // 子查詢必須限 status=1：停用的 survivor 綁定不算「已綁 survivor」。不限的話，
            // 只有停用 survivor 綁定的會員會在這裡被停掉唯一有效的 loser 綁定，又因該列存在
            // 而被 repointSoleBindings 的 whereNotIn 排除，最後零個有效綁定、無法提款。
            ->whereIn('member_id', function ($query) use ($survivorId): void {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $survivorId)
                    ->where('status', 1);
            })
            ->get(['id', 'member_id']);

        foreach ($bindings as $binding) {
            DB::table('member_bank_accounts')->where('id', $binding->id)->update($this->preservingUpdatedAt(['status' => 0]));
            $this->writeAudit($connection, $group, $survivorId, $loserId, (int) $binding->member_id, (int) $binding->id, 'deactivate_binding', null);
        }

        return $bindings->count();
    }

    /**
     * 情況2：會員只綁 loser（無 survivor）時，將綁定 re-point 至 survivor，並同步該會員的出款訂單。
     *
     * @param  array{agent_group_id: int, code: string}  $group
     */
    private function repointSoleBindings(array $group, Bank $survivor, int $loserId, string $connection): int
    {
        $bindings = DB::table('member_bank_accounts')
            ->where('bank_id', $loserId)
            // 判準與 deactivateDuplicateBindings 互補，同樣只認 active 的 survivor 綁定：
            // 只有停用 survivor 綁定的會員歸這裡處理，re-point 後他在 survivor 下會有一停用
            // 一啟用兩列，前台看到的仍是那筆有效綁定。
            ->whereNotIn('member_id', function ($query) use ($survivor): void {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $survivor->id)
                    ->where('status', 1);
            })
            ->get(['id', 'member_id']);

        foreach ($bindings as $binding) {
            DB::table('member_bank_accounts')->where('id', $binding->id)->update($this->preservingUpdatedAt(['bank_id' => $survivor->id]));

            $ordersSynced = DB::table('withdrawal_orders')
                ->where('member_id', $binding->member_id)
                ->where('bank_id', $loserId)
                ->update($this->preservingUpdatedAt([
                    'bank_id' => $survivor->id,
                    'bank_code' => $survivor->code,
                    'bank_name' => $survivor->name,
                ]));

            $this->writeAudit($connection, $group, (int) $survivor->id, $loserId, (int) $binding->member_id, (int) $binding->id, 'repoint', 'orders_synced=' . $ordersSynced);
        }

        return $bindings->count();
    }

    /**
     * 待審核實名件 re-point：`realname_validation_requests.status=0` 且 bank_id 指向 loser 者改指 survivor。
     *
     * 舊系統 API_Agent RealnameValidateService 審核通過時直接以請求上的 bank_id 建
     * member_bank_accounts，無任何 banks.status／deleted_at 檢查——loser 停用或標刪後
     * 若不 re-point，核准會綁出指向死銀行的綁定。已審結（status 1/2）列僅為歷史快照、
     * 顯示走 bank_name 文字欄不 join banks，維持原值不動。非 flagged 路徑的 loser 與
     * survivor 名稱 case-fold 相同（同一銀行），請求上的 bank_name 快照仍準確。
     *
     * @param  array{agent_group_id: int, code: string}  $group
     */
    private function repointPendingRealnameRequests(array $group, int $survivorId, int $loserId, string $connection): int
    {
        $requests = DB::table('realname_validation_requests')
            ->where('bank_id', $loserId)
            ->where('status', 0)
            ->get(['id', 'member_id']);

        foreach ($requests as $request) {
            DB::table('realname_validation_requests')->where('id', $request->id)->update($this->preservingUpdatedAt(['bank_id' => $survivorId]));
            $this->writeAudit($connection, $group, $survivorId, $loserId, (int) $request->member_id, null, 'repoint_realname_request', 'request_id=' . $request->id);
        }

        return $requests->count();
    }

    /**
     * 疑似 code 重用：loser 名稱 case-fold 後與 survivor 不等，且 loser 仍有會員綁定。
     * 名稱漂移（大小寫、縮寫）與「同碼不同銀行」文字上無法可靠區分，故只要名稱不等且有綁定就轉人工，
     * 避免把 A 銀行會員誤 re-point 到 B 銀行。零綁定者合併無害、不在此攔。
     */
    private function isSuspectedCodeReuse(Bank $survivor, int $loserId): bool
    {
        $loserName = (string) Bank::query()->where('id', $loserId)->value('name');

        $nameDiffers = $this->nameKey((string) $survivor->name) !== $this->nameKey($loserName);
        if (!$nameDiffers) {
            return false;
        }

        return DB::table('member_bank_accounts')->where('bank_id', $loserId)->exists();
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param  array{agent_group_id: int, code: string}  $group
     */
    private function writeAudit(string $connection, array $group, int $survivorId, int $loserId, ?int $memberId, ?int $bindingId, string $action, ?string $detail): void
    {
        DB::table('bank_dedup_logs')->insert([
            'connection' => $connection,
            'agent_group_id' => $group['agent_group_id'],
            'code' => $group['code'],
            'survivor_bank_id' => $survivorId,
            'loser_bank_id' => $loserId,
            'member_id' => $memberId,
            'member_bank_account_id' => $bindingId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }
}
