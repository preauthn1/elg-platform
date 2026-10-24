<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Models\Bank;

/**
 * 把 base bank（agent_group_id=0）active canonical 同步到指定 agent group（L1 防再生）。
 *
 * 去重鍵＝active-only 四元組 (agent_group_id, type, currency_id, code)：**code 穩定、不覆寫**——
 * 改名後重跑不再滋生名字略異的重複銀行（取代舊 name-key 就地覆寫 code，根治 G3 drift 源）。
 * 融合 !1769 防護：base 撈模板只認 status=1、target 以 active 優先（orderByDesc status）、
 * 寫入前 isActiveCodeHeldByOther 撞鍵跳過防撞 uk_banks_active。
 *
 * base 查詢自帶 status=1 不是多餘的重複防護，不可拿掉：上游兩道防線結構上都堵不住 inactive 孤兒——
 * bank:dedup-active 的 shadow 偵測以 name 分組，同 code 不同 name 的 inactive 孤兒不入列；
 * bank:guard-active-banks 的 active_uk 對 status≠1 產出 NULL、MySQL 唯一索引放行多個 NULL；
 * banks 的歷史孤兒收斂至今沒有命令做。少了這個 where，base 上同碼的下架舊列會被當模板繼承，
 * agent 層長出 status=0 的新列。
 * 這個 status 過濾與 SyncCashFlowsCommand::findPlatformBankByName() 的 status=1 是一對孿生點，
 * 語意相同、改一個要想另一個；兩處曾被兩輪自動化審查同時漏抓。
 * 本 Service 刻意不動 banks「每個 agent group 各一份副本」的架構，也不收斂既有的歷史孤兒列或
 * 多餘的重複列；它只防止未來繼承錯 status。重跑對既有列的影響只有一種：target 只在未標刪的列裡取
 * 「有 active 取 active，否則 id 最小的那筆 inactive」（已標刪的列取不到，會改走新增一列），更新時把
 * base 的 status 一併寫上去，所以 base 是 active 時那一筆會被拉回 status=1；同四元組其餘的重複列不動。
 * 多筆重複、同 code 不同 name 的孤兒仍要另跑清理命令。
 *
 * @knowledge rules/standard-bank-list
 */
class AgentBankSyncService
{
    public function __construct(private readonly BankStandardMappingService $mapping) {}

    /**
     * @param  array<int, string>  $bankCodes
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncToAgentGroup(
        array $bankCodes,
        int $agentGroupId,
        int $currencyId,
        int $effectiveMinAmount,
        int $effectiveMaxAmount,
    ): array {
        // base 撈模板：只認 active canonical（status=1）；**不 keyBy('code')**——same code 不同 type 不可互相覆蓋
        $baseBanks = Bank::query()
            ->where('agent_group_id', 0)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->whereIn('code', $bankCodes)
            ->orderBy('id')
            ->get();

        // 同 group+currency rate 沿用（orderBy id 確定序，避免無排序 MySQL 不確定）
        $existingRate = Bank::query()
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->orderBy('id')
            ->value('rate');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($baseBanks as $baseBank) {
            $code = (string) $baseBank->code;
            $type = (int) $baseBank->type;
            $rate = $existingRate ?? $baseBank->rate;

            // 去重鍵：四元組 (agent_group, type, currency, code)，active 優先（同 code 存在 inactive shadow 時取 active）
            $target = Bank::query()
                ->whereNull('deleted_at')
                ->where('agent_group_id', $agentGroupId)
                ->where('type', $type)
                ->where('currency_id', $currencyId)
                ->where('code', $code)
                ->orderByDesc('status')
                ->orderBy('id')
                ->first();

            $minAmount = ($target !== null && $target->min_amount > 0)
                ? (int) $target->min_amount
                : $effectiveMinAmount;
            $maxAmount = ($target !== null && $target->max_amount > 0)
                ? (int) $target->max_amount
                : $effectiveMaxAmount;

            // 撞鍵 defense：寫入將成 active，且該碼被同範疇另一 active 列（非 target）持有 → 跳過防撞 uk_banks_active
            if ((int) $baseBank->status === 1
                && $this->isActiveCodeHeldByOther($agentGroupId, $type, $currencyId, $code, $target?->id)) {
                $skipped++;

                continue;
            }

            $attributes = [
                'code' => $code,
                'type' => $type,
                'name' => $baseBank->name,
                'third_party_code' => $baseBank->third_party_code,
                'info' => $baseBank->info,
                'rate' => $rate,
                'tax_type' => $baseBank->tax_type,
                'tax' => $baseBank->tax,
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'sort' => $baseBank->sort,
                'image_url' => $baseBank->image_url,
                'status' => $baseBank->status,
                'remark' => $baseBank->remark,
                'admin' => $baseBank->admin,
            ];

            if ($target === null) {
                $newBank = Bank::query()->create([
                    'agent_group_id' => $agentGroupId,
                    'currency_id' => $currencyId,
                    // Phase 2 防再生：群組列直接繼承 base 列的身份映射（確定性，免匹配）
                    'standard_bank_id' => $baseBank->standard_bank_id,
                    ...$attributes,
                ]);
                if ($baseBank->standard_bank_id !== null) {
                    $this->mapping->auditFill((int) $newBank->id, (int) $baseBank->standard_bank_id, 'inherit_base', 100, 'base_bank');
                }
                $created++;
            } else {
                $target->update($attributes);
                // 既有列只補空、不覆寫（target 已有映射或 base 無映射時皆不動）
                if ($target->standard_bank_id === null && $baseBank->standard_bank_id !== null) {
                    $target->update(['standard_bank_id' => $baseBank->standard_bank_id]);
                    $this->mapping->auditFill((int) $target->id, (int) $baseBank->standard_bank_id, 'inherit_base', 100, 'base_bank');
                }
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * 檢查代碼是否已被同範疇（agent_group_id + type + currency_id）另一 active 列持有。
     */
    private function isActiveCodeHeldByOther(int $agentGroupId, int $type, int $currencyId, string $code, ?int $exceptId): bool
    {
        return Bank::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('agent_group_id', $agentGroupId)
            ->where('type', $type)
            ->where('currency_id', $currencyId)
            ->where('code', $code)
            ->where('status', 1)
            ->exists();
    }
}
