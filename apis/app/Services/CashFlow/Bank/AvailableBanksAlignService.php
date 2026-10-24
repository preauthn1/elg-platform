<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 把代付 ACF 的 available_banks BankCode_ELG 對齊到 banks 表的 canonical active code。
 *
 * 對齊方向只能順著 sync：把 JSON 改成 canonical，不能反過來把 canonical 改回舊碼——
 * AgentBankSyncService 下一次 sync 會再把 canonical 帶回 agent 層，drift 立刻重生。
 * 匹配只靠 name 完全相等（經 nameKey 正規化），沒有 alias 或 standard_bank_id 回退：舊碼列的
 * name 必須與 canonical active 列同名才 hop 得到；落入 unmatched 的多半是名稱還沒正名
 * （例如 OCBC Bank 對 (MYR) - OCBC Bank），處置是先正名再重跑，不是在這裡加模糊比對。
 */
class AvailableBanksAlignService
{
    /**
     * prune gate 3 的攔阻狀態：in-flight（21/22）＋可重發（29，非終態、重發會再查碼）。
     * 這些單以 `wo.bank_code` 快照查 JSON target entry，entry 刪了會查不到碼而擋單。
     * 23（三方查證中）已由 MR !2235 退役，六站 2026-09-16 實查存量為零，故不列。
     */
    private const PRUNE_BLOCKING_WO_STATUSES = [
        WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
        WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
    ];

    /**
     * 偵測 scope 內代付 ACF（CashFlow.type=3）的 available_banks drift（唯讀無鎖，供 dry-run）。
     *
     * @return Collection<int, array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}>
     */
    public function detectDrift(?int $agentGroupId = null, ?int $cashFlowId = null, bool $pruneDuplicates = false): Collection
    {
        $items = collect();

        foreach ($this->withdrawalCashFlows($agentGroupId, $cashFlowId) as $acf) {
            foreach ($this->resolveAcf($acf, $pruneDuplicates) as $item) {
                $items->push($item);
            }
        }

        return $items;
    }

    /**
     * 執行對齊：逐 ACF DB::transaction + lockForUpdate 鎖定，鎖內 resolveAcf 重算（防與 sync／後台並發改 info 競態）；
     * align 就地 rename BankCode_ELG（保留其餘鍵），unmatched/conflict 只寫審計不改資料。
     * canonical banks 為 non-locking read，靠 maintenance window + 寫後 parity 兜底。
     *
     * 每個白標的執行順序：
     * 1. 先確認 `AgentBankSyncService` 的去重鍵已部署、banks canonical 已收斂——否則 canonical 仍是舊碼，校正方向會錯。
     * 2. execute 時段避開 `cash-flow:sync` 與後台改 banks（canonical 是 non-locking read，並發會讀到 stale）。
     * 3. 先 dry-run 盤點 align／unmatched／conflict，人工看過 unmatched 與 conflict。
     * 4. `--execute` 必帶 `--agent-group` 或 `--cash-flow` 限範圍（命令對無範圍 execute 直接拒絕）。
     * 5. 寫後跑 `cash-flow:verify-payout-bank-parity` 比對 available_banks 與 cash_flow_banks 是否一致。
     *    它不重查 canonical banks 本身、且部分不一致只警告不影響回傳，擋不住「canonical 已改而兩邊
     *    都留舊碼」的情形，stale 風險仍要靠避開 sync 時段與人工複核 dry-run 報表。
     *
     * @return array{aligned: int, unmatched: int, conflict: int, pruned: int}
     */
    public function align(?int $agentGroupId = null, ?int $cashFlowId = null, bool $pruneDuplicates = false): array
    {
        $summary = ['aligned' => 0, 'unmatched' => 0, 'conflict' => 0, 'pruned' => 0];
        $connection = (string) config('database.default');

        $acfIds = $this->withdrawalCashFlows($agentGroupId, $cashFlowId)->pluck('id');

        foreach ($acfIds as $acfId) {
            DB::transaction(function () use ($acfId, $connection, $pruneDuplicates, &$summary): void {
                $acf = AgentCashFlow::query()->whereKey($acfId)->lockForUpdate()->first();
                if ($acf === null) {
                    return;
                }

                // 鎖內重新 resolve（用鎖內最新 info + canonical）
                $items = $this->resolveAcf($acf, $pruneDuplicates);
                if ($items === []) {
                    return;
                }

                $renameMap = [];
                $pruneCodes = [];
                foreach ($items as $item) {
                    if ($item['resolution'] === 'align' && $item['new_code'] !== null) {
                        $renameMap[$item['old_code']] = $item['new_code'];
                    }

                    if ($item['resolution'] === 'prune') {
                        $pruneCodes[$item['old_code']] = true;
                    }
                }

                if ($renameMap !== [] || $pruneCodes !== []) {
                    $info = $this->readInfo($acf);
                    $entries = $info['available_banks'] ?? [];
                    foreach ($entries as $i => $entry) {
                        $target = is_array($entry) ? (string) ($entry['BankCode_ELG'] ?? '') : '';

                        // prune＝整個 entry 移除（canonical entry 已在同 ACF、Third 相同，見 pruneVerdict）
                        if (isset($pruneCodes[$target])) {
                            unset($entries[$i]);

                            continue;
                        }

                        if (isset($renameMap[$target])) {
                            $entries[$i]['BankCode_ELG'] = (string) $renameMap[$target];
                        }
                    }
                    $info['available_banks'] = array_values($entries);
                    $acf->update(['info' => $info]);
                }

                foreach ($items as $item) {
                    $this->writeAudit($connection, $item);
                    // resolution 'align' 對應 summary key 'aligned'、'prune' 對應 'pruned'（unmatched/conflict 同名）
                    $summary[match ($item['resolution']) {
                        'align' => 'aligned',
                        'prune' => 'pruned',
                        default => $item['resolution'],
                    }]++;
                }
            });
        }

        return $summary;
    }

    /**
     * @param  array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}  $item
     */
    protected function writeAudit(string $connection, array $item): void
    {
        DB::table('available_banks_align_logs')->insert([
            'connection' => $connection,
            'agent_cash_flow_id' => $item['agent_cash_flow_id'],
            'cash_flow_id' => $item['cash_flow_id'],
            'agent_group_id' => $item['agent_group_id'],
            'currency_id' => $item['currency_id'],
            'old_code' => mb_substr($item['old_code'], 0, 64), // JSON 原值可能任意長，truncate 防 insert 失敗
            'new_code' => $item['new_code'],
            'action' => $item['resolution'],
            'detail' => $item['detail'],
        ]);
    }

    /**
     * 解析單一 ACF 的 available_banks drift（detect 與 align 共用；align 於 lockForUpdate 鎖內呼叫）。
     * currency_id=null → 整筆 unmatched（不跨幣別 hop）；否則逐 entry 走 resolveEntry。
     *
     * @return list<array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}>
     */
    protected function resolveAcf(AgentCashFlow $acf, bool $pruneDuplicates = false): array
    {
        $entries = $this->readInfo($acf)['available_banks'] ?? [];
        if (!is_array($entries) || $entries === []) {
            return [];
        }

        $agentGroup = (int) $acf->agent_group_id;
        $currencyId = $acf->currency_id !== null ? (int) $acf->currency_id : null;

        // 同 ACF 全部 target（PHP 精確比對、與 BINARY 同語意）：rename 目標撞既存 entry 時改標 conflict，防重複 entry。
        // 同時建 target→Third map 供 prune gate 2：同 target 多 entry 且 Third 不一致＝ambiguous（值設 false），
        // gate 2 對不上、天然擋下（重複 entry 本身是另一種病，不能假設刪哪個都一樣）。
        $siblingElg = [];
        $thirdMap = [];
        foreach ($entries as $entry) {
            $code = is_array($entry) ? (string) ($entry['BankCode_ELG'] ?? '') : '';
            if ($code === '') {
                continue;
            }

            $siblingElg[] = $code;
            $third = is_array($entry) && array_key_exists('BankCode_Third', $entry) ? (string) $entry['BankCode_Third'] : null;

            if (array_key_exists($code, $thirdMap) && $thirdMap[$code] !== $third) {
                $thirdMap[$code] = false;

                continue;
            }

            $thirdMap[$code] ??= $third;
        }

        $items = [];
        foreach ($entries as $entry) {
            $target = is_array($entry) ? (string) ($entry['BankCode_ELG'] ?? '') : '';
            if ($target === '') {
                continue;
            }

            if ($currencyId === null) {
                $items[] = $this->makeItem($acf, $agentGroup, null, $target, 'unmatched', null, 'currency_id 為 null，不跨幣別對齊');

                continue;
            }

            $item = $this->resolveEntry($acf, $agentGroup, $currencyId, $target, $siblingElg, $pruneDuplicates ? $thirdMap : null);
            if ($item !== null) {
                $items[] = $item; // null = 已對齊，不列入
            }
        }

        return $items;
    }

    /**
     * per-entry type-aware 判定：target（code 走 BINARY exact）→ banks (type,name)（多組 distinct→conflict，含跨 type 同碼）
     * → group+currency+type+name active canonical（name 走 PHP nameKey）：== target 已對齊(null)、≠ align、查無 unmatched、多 conflict。
     * canonical 已存在於同 ACF 其他 entry（$siblingElg）→ conflict：rename 會製造重複 target entry（Third 不同時代付行為隨陣列順序不確定）。
     *
     * @param  list<string>  $siblingElg
     * @param  array<string, string|false|null>|null  $thirdMap  非 null＝啟用 prune 判定（target→Third；false＝同 target 多 entry Third 不一致）
     * @return array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}|null
     */
    protected function resolveEntry(AgentCashFlow $acf, int $agentGroupId, int $currencyId, string $target, array $siblingElg = [], ?array $thirdMap = null): ?array
    {
        // code 為 identifier，用 BINARY exact 比對（? binding 安全），避免 utf8mb4_unicode_ci 把 case/accent variant 誤匹配
        $rows = Bank::query()
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->whereRaw('BINARY code = ?', [$target])
            ->get(['type', 'name']);

        if ($rows->isEmpty()) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'unmatched', null, 'target 無對應 banks row');
        }

        $distinct = $rows->map(fn (Bank $r): string => (int) $r->type . '|' . $this->nameKey((string) $r->name))->unique()->values();
        if ($distinct->count() > 1) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, 'target 對應多組 (type,name)（含跨 type 同碼）');
        }

        $type = (int) $rows->first()->type;
        $name = (string) $rows->first()->name;

        // name 比對全走 PHP nameKey（與上方 distinct 同源），不用 MySQL where('name')——避免 collation（accent/pad space/全形）分歧
        $nameKeyTarget = $this->nameKey($name);
        $canonicalCodes = Bank::query()
            ->whereNull('deleted_at')
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->where('type', $type)
            ->where('status', 1)
            ->get(['code', 'name'])
            ->filter(fn (Bank $b): bool => $this->nameKey((string) $b->name) === $nameKeyTarget)
            ->pluck('code')
            ->map(fn ($c): string => (string) $c)
            ->unique()
            ->values();

        if ($canonicalCodes->isEmpty()) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'unmatched', null, 'name「' . $name . '」(type ' . $type . ') 無 active canonical');
        }

        if ($canonicalCodes->count() > 1) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, 'name+type 多 active canonical: ' . $canonicalCodes->implode(','));
        }

        $canonical = (string) $canonicalCodes->first();
        if ($canonical === $target) {
            return null; // target 已是自己 (type,name) 的 active canonical → 已對齊
        }

        if (in_array($canonical, $siblingElg, true)) {
            if ($thirdMap !== null) {
                return $this->pruneVerdict($acf, $agentGroupId, $currencyId, $target, $canonical, $thirdMap);
            }

            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, 'canonical「' . $canonical . '」已存在於同 ACF available_banks，rename 將造成重複 entry，待人工合併');
        }

        return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'align', $canonical, null);
    }

    /**
     * `--prune-duplicate-entries` 的判定：canonical entry 已在同 ACF 時，死 entry（本 target）
     * 能否安全刪除。與 `bank:fix-mismatch` 的 prune gate 同哲學：
     *
     * - gate 1（target 無 active canonical 引用）：由呼叫端流程涵蓋——走得到這裡代表 target 不在
     *   該 (type,name) 的 active canonical 集合（跨 name 同碼在更早的 distinct 檢查就 conflict）。
     * - gate 2（Third 嚴格相同）：死 entry 與 canonical entry 的 `BankCode_Third` PHP 端 `===`
     *   ——相同＝刪除對送碼零影響；不同＝刪了會改變送三方的碼，擋下。同 target 多 entry 且
     *   Third 不一致（map 值 false）亦擋。
     * - gate 3（無攔阻中舊碼單）：runtime 以 `wo.bank_code` 快照查 JSON target entry，
     *   in-flight（21/22）與可重發（29）的舊碼單刪了 entry 會查不到碼而擋單
     *   （23 已退役，見 `PRUNE_BLOCKING_WO_STATUSES`）。
     *   `status` 有索引、先縮到極少列再以 BINARY 比對 bank_code。
     *
     * @param  array<string, string|false|null>  $thirdMap
     * @return array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}
     */
    protected function pruneVerdict(AgentCashFlow $acf, int $agentGroupId, int $currencyId, string $target, string $canonical, array $thirdMap): array
    {
        $deadThird = $thirdMap[$target] ?? null;
        $canonicalThird = $thirdMap[$canonical] ?? null;

        if ($deadThird === false || $canonicalThird === false) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, sprintf(
                'canonical「%s」已存在但同 target 多 entry 且 Third 不一致，prune 不適用、待人工合併',
                $canonical,
            ));
        }

        if ($deadThird !== $canonicalThird) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, sprintf(
                'canonical「%s」已存在但兩 entry 的 Third 不同（"%s" vs "%s"），刪除會改變送碼、待人工挑碼',
                $canonical,
                $deadThird ?? '(無)',
                $canonicalThird ?? '(無)',
            ));
        }

        $blocking = WithdrawalOrder::query()
            ->whereIn('status', self::PRUNE_BLOCKING_WO_STATUSES)
            ->whereRaw('BINARY bank_code = ?', [$target])
            ->count();

        if ($blocking > 0) {
            return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'conflict', null, sprintf(
                'canonical「%s」已存在且 Third 相同，但有 %d 筆 in-flight／可重發舊碼單（status 21/22/29）——刪 entry 會讓它們查不到碼，待結清再 prune',
                $canonical,
                $blocking,
            ));
        }

        return $this->makeItem($acf, $agentGroupId, $currencyId, $target, 'prune', $canonical, sprintf(
            '重複 entry：canonical「%s」已在同 ACF、Third 相同（"%s"）、無攔阻中舊碼單，刪除本 entry',
            $canonical,
            $deadThird ?? '(無)',
        ));
    }

    /**
     * scope 內代付 ACF（CashFlow.type=3）。
     *
     * @return Collection<int, AgentCashFlow>
     */
    protected function withdrawalCashFlows(?int $agentGroupId, ?int $cashFlowId): Collection
    {
        $withdrawalIds = CashFlow::query()->where('type', 3)->pluck('id');

        return AgentCashFlow::query()
            ->whereIn('cash_flow_id', $withdrawalIds)
            ->when($cashFlowId !== null, fn ($q) => $q->where('cash_flow_id', $cashFlowId))
            ->when($agentGroupId !== null, fn ($q) => $q->where('agent_group_id', $agentGroupId))
            ->get();
    }

    /**
     * 讀 ACF info；容忍 double-encoded（$acf->info 讀回 string 的 legacy 情況）。
     *
     * @return array<string, mixed>
     */
    protected function readInfo(AgentCashFlow $acf): array
    {
        $info = $acf->info;
        if (is_string($info)) {
            $info = json_decode($info, true);
        }

        return is_array($info) ? $info : [];
    }

    protected function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @return array{agent_cash_flow_id: int, cash_flow_id: int, agent_group_id: int, currency_id: int|null, old_code: string, resolution: string, new_code: string|null, detail: string|null}
     */
    protected function makeItem(AgentCashFlow $acf, int $agentGroupId, ?int $currencyId, string $oldCode, string $resolution, ?string $newCode, ?string $detail): array
    {
        return [
            'agent_cash_flow_id' => (int) $acf->id,
            'cash_flow_id' => (int) $acf->cash_flow_id,
            'agent_group_id' => $agentGroupId,
            'currency_id' => $currencyId,
            'old_code' => $oldCode,
            'resolution' => $resolution,
            'new_code' => $newCode,
            'detail' => $detail,
        ];
    }
}
