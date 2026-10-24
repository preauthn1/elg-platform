<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Enums\CashFlowTypeEnum;
use App\Helpers\Common;
use App\Models\Bank;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 跨碼銀行收斂:偵測與分級(純讀,零寫入)。
 *
 * 偵測「同 (agent_group_id, standard_bank_id) 多筆存活且 code 相異」的組。
 * 存活=未軟刪,**不看 status**:停用的重複碼一樣是收斂對象,碼層的重複不會因為它被停用就消失。
 *
 * ⚠️ 與 BankProjectionService::agentBankCandidates 的歧義定義**刻意分流,不再同源**
 * (舊版註解寫「同源」,2026-09-14 起不成立)。兩者守的是不同的事:
 *
 * - 本服務守「碼層還有沒有重複」⇒ 不看 status,收斂完才會消失。
 * - 投影分揀守「投影表選不選得出代表」⇒ 看 status,同組只剩一列啟用就選得出來。
 *
 * 所以同一組資料會同時出現在本服務的偵測結果與投影的 resolved 桶:
 * 投影通過不代表收斂做完,碼層清單的權威出處是本服務(bank:converge-cross-code)。
 *
 * 寫入一律由呼叫端驅動 bank:rename-bank 既有收斂管線,本服務不落任何寫入。
 */
class CrossCodeConvergenceService
{
    /** 非終態提款單狀態（在途單閘：在途單的 bank_code 是三方送單參數，不得改寫，任一筆存在即整組 flagged）。 */
    private const PENDING_ORDER_STATUSES = [1, 3, 21, 22, 23, 29];

    /** 碼語意黑名單：鏈別／必填欄／內轉語意硬編在碼上，誤合不可逆，一律不自動。 */
    private const BLOCKED_CODES = ['IFSC', 'FON', 'ERC20', 'TRC20'];

    /**
     * @param  list<int>|null  $agentGroupIds
     * @return Collection<int, array{agent_group_id:int, standard_bank_id:int, rows:Collection<int, Bank>}>
     */
    public function detectCrossCodeGroups(?array $agentGroupIds = null): Collection
    {
        /** @var Collection<int, Bank> $banks */
        $banks = Bank::query()
            ->where('agent_group_id', '>', 0)
            ->whereNull('deleted_at')
            ->whereNotNull('standard_bank_id')
            ->when($agentGroupIds !== null, fn ($query) => $query->whereIn('agent_group_id', $agentGroupIds))
            ->orderBy('id')
            ->get([
                'id', 'agent_group_id', 'standard_bank_id', 'code', 'name', 'type',
                'currency_id', 'status', 'min_amount', 'max_amount', 'rate', 'tax_type', 'tax',
            ]);

        /** @var Collection<string, Collection<int, Bank>> $grouped */
        $grouped = $banks->groupBy(fn (Bank $bank): string => $bank->agent_group_id . ':' . $bank->standard_bank_id);

        return $grouped
            ->filter(fn (Collection $rows): bool => $rows->count() >= 2
                && $rows->pluck('code')->unique()->count() > 1)
            ->map(fn (Collection $rows): array => [
                'agent_group_id' => (int) $rows->first()->agent_group_id,
                'standard_bank_id' => (int) $rows->first()->standard_bank_id,
                'rows' => $rows->values(),
            ])
            ->values();
    }

    /**
     * 逐列蒐集引用證據（現役引用五項＋歷史計數＋regrowth 旗標）。
     *
     * 現役引用為什麼是這五項：代付查表鍵是 bank_code 不是 bank_id，drift 列（bank_id 指 base、
     * bank_code 是 agent 碼）必須算現役；available_banks 命中限代付 ACF 且同幣別，否則會被同 group
     * 他幣別的 ACF 誤中；member_bank_accounts 綁定是收斂時唯一會被改寫的會員資產，兩筆都有綁定
     * 是「其實是兩家不同銀行」的最強訊號，所以兩側都有現役引用一律 flagged 不自動。
     *
     * @return array{cfb_code_refs:int, cfb_id_refs:int, json_acf_ids:list<int>, active_bindings:int, recent_orders:int, live:bool, pending_orders:int, historical_orders:int, historical_bindings:int, base_active:bool}
     */
    public function gatherEvidence(Bank $bank): array
    {
        $cfbCodeRefs = DB::table('cash_flow_banks')
            ->where('bank_code', $bank->code)
            ->where('currency_id', $bank->currency_id)
            ->where('status', 1)
            ->count();

        $cfbIdRefs = DB::table('cash_flow_banks')
            ->where('bank_id', $bank->id)
            ->where('status', 1)
            ->count();

        $jsonAcfIds = $this->availableBanksHits($bank);

        $activeBindings = DB::table('member_bank_accounts')
            ->where('bank_id', $bank->id)
            ->where('status', 1)
            ->count();

        $recentOrders = DB::table('withdrawal_orders')
            ->where('bank_id', $bank->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->count();

        return [
            'cfb_code_refs' => $cfbCodeRefs,
            'cfb_id_refs' => $cfbIdRefs,
            'json_acf_ids' => $jsonAcfIds,
            'active_bindings' => $activeBindings,
            'recent_orders' => $recentOrders,
            'live' => $cfbCodeRefs > 0 || $cfbIdRefs > 0 || $jsonAcfIds !== []
                || $activeBindings > 0 || $recentOrders > 0,
            'pending_orders' => DB::table('withdrawal_orders')
                ->where('bank_id', $bank->id)
                ->whereIn('status', self::PENDING_ORDER_STATUSES)
                ->count(),
            'historical_orders' => DB::table('withdrawal_orders')->where('bank_id', $bank->id)->count(),
            'historical_bindings' => DB::table('member_bank_accounts')->where('bank_id', $bank->id)->count(),
            // 對齊 RenameBankCommand::warnAboutRegrowth():agent_group_id=0 AND
            // currency_id AND code AND status=1,不濾 deleted_at——base 標刪列仍會被
            // AgentBankSyncService 當模板長回來,濾掉 deleted_at 會漏掉真實 regrowth 風險。
            'base_active' => Bank::query()
                ->where('agent_group_id', 0)
                ->where('currency_id', $bank->currency_id)
                ->where('code', $bank->code)
                ->where('status', 1)
                ->exists(),
        ];
    }

    /**
     * 前置閘＋分流。
     *
     * 閘序:跨 type → 幣別 → 費率 → 碼語意 → 在途單 → 現役分布 → regrowth。
     * 任一閘不過即 flagged 並累積 reasons(不短路,一次回報全部理由)。
     * 跨 type 組 flagged 後，人工要判的不只「合列」：type 不同也可能是 standard mapping 對錯了
     * （例如 POSB 與 DBS 映到同一個 standard），第二種解是改 mapping，不是合列。注意 bank:sync-mappings
     * 的自動與人工 reviewed 路徑都只補 standard_bank_id 為 NULL 的列，已映錯的列要先把映射清掉才能重跑，
     * 直接重跑不會修正。
     *
     * @param  Collection<int, Bank>  $rows
     * @return array{tier:string, survivor_id:?int, loser_ids:list<int>, reasons:list<string>, evidence:array<int, array>}
     */
    public function classify(Collection $rows): array
    {
        $evidence = [];
        foreach ($rows as $bank) {
            $evidence[(int) $bank->id] = $this->gatherEvidence($bank);
        }

        $reasons = [];

        if ($rows->pluck('type')->unique()->count() > 1) {
            $reasons[] = 'cross_type';
        }

        if ($rows->pluck('currency_id')->unique()->count() > 1) {
            $reasons[] = 'currency_mismatch';
        }

        $first = $rows->first();
        $feeMismatch = $rows->contains(fn (Bank $bank): bool => (int) $bank->tax_type !== (int) $first->tax_type
            || Common::numCompare((string) $bank->rate, (string) $first->rate) !== 0
            || Common::numCompare((string) $bank->tax, (string) $first->tax) !== 0);
        if ($feeMismatch) {
            $reasons[] = 'fee_mismatch';
        }

        $semantic = $rows->contains(fn (Bank $bank): bool => (int) $bank->type === 3
            || in_array(strtoupper((string) $bank->code), self::BLOCKED_CODES, true));
        if ($semantic) {
            $reasons[] = 'code_semantics';
        }

        if (collect($evidence)->contains(fn (array $item): bool => $item['pending_orders'] > 0)) {
            $reasons[] = 'pending_orders';
        }

        $liveIds = collect($evidence)->filter(fn (array $item): bool => $item['live'])->keys();

        // survivor 必須 status=1(啟用中),否則收斂完不能立刻服務會員/代付查表。
        // 組內若無任何啟用列,兩分支都無合法標的可選,直接 flagged。
        $activeIds = $rows->filter(fn (Bank $bank): bool => (int) $bank->status === 1)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $survivorId = null;
        if ($activeIds === []) {
            $reasons[] = 'no_active_row';
        } elseif ($liveIds->count() === 1) {
            $candidateId = (int) $liveIds->first();
            // 唯一現役列本身若非啟用,退回在啟用列中依歷史/id 擇優(active 優先)。
            $survivorId = in_array($candidateId, $activeIds, true)
                ? $candidateId
                : $this->pickSurvivorByHistory($evidence, $activeIds);
        } elseif ($liveIds->isEmpty()) {
            // 全零組:在啟用列中依歷史引用多者勝、平手取 id 小(active 優先於歷史計數)
            $survivorId = $this->pickSurvivorByHistory($evidence, $activeIds);
        } else {
            $reasons[] = 'multi_live';
        }

        $loserIds = [];
        if ($survivorId !== null) {
            $loserIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === $survivorId)->values()->all();

            // regrowth 閘：任一 loser 碼在 base 仍 active——下次 sync 會照 base 模板重建並繼承 standard_bank_id，歧義以新 id 復現；要先收斂 base 才能合
            if (collect($loserIds)->contains(fn (int $id): bool => $evidence[$id]['base_active'])) {
                $reasons[] = 'regrowth_risk';
            }
        }

        return [
            'tier' => $reasons === [] ? 'auto' : 'flagged',
            'survivor_id' => $survivorId,
            'loser_ids' => $loserIds,
            'reasons' => $reasons,
            'evidence' => $evidence,
        ];
    }

    /**
     * 在候選 id 集合內依「歷史引用多者勝、平手取 id 小」擇優(I-7:候選集合限啟用列)。
     *
     * @param  array<int, array>  $evidence
     * @param  list<int>  $candidateIds
     */
    private function pickSurvivorByHistory(array $evidence, array $candidateIds): int
    {
        return (int) collect($evidence)
            ->filter(fn (array $item, int $id): bool => in_array($id, $candidateIds, true))
            ->map(fn (array $item, int $id): array => [
                'id' => $id,
                'history' => $item['historical_orders'] + $item['historical_bindings'],
            ])
            ->sortBy([['history', 'desc'], ['id', 'asc']])
            ->first()['id'];
    }

    /**
     * available_banks JSON 命中——限代付 ACF(cash_flows.type=3)且同幣別（不限縮會被同 group 他幣別 ACF 誤中）。
     * entry 鍵沿用舊路徑讀取的 BankCode_ELG(RenameBankCommand::findAvailableBanksConflicts 同源)。
     *
     * @return list<int>
     */
    private function availableBanksHits(Bank $bank): array
    {
        $hits = [];

        $acfRows = DB::table('agent_cash_flows')
            ->join('cash_flows', 'cash_flows.id', '=', 'agent_cash_flows.cash_flow_id')
            ->where('agent_cash_flows.agent_group_id', $bank->agent_group_id)
            ->where('agent_cash_flows.currency_id', $bank->currency_id)
            ->where('cash_flows.type', CashFlowTypeEnum::WITHDRAW->value)
            ->get(['agent_cash_flows.id', 'agent_cash_flows.info']);

        foreach ($acfRows as $row) {
            $info = json_decode((string) $row->info, true);

            if (!is_array($info) || !is_array($info['available_banks'] ?? null)) {
                continue;
            }

            foreach ($info['available_banks'] as $entry) {
                if (is_array($entry) && (string) ($entry['BankCode_ELG'] ?? '') === (string) $bank->code) {
                    $hits[] = (int) $row->id;
                    break;
                }
            }
        }

        return $hits;
    }
}
