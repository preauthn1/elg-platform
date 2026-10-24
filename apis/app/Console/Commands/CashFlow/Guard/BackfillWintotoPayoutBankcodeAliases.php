<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 補 Wintoto（g70/g93）全部 active MYR 代付渠道 available_banks 的「雙碼聯集」。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * **Wintoto（g70／g93）執行 `agent:add-agent-cash-flow`（或串起它的 `cash-flow:auto-sync`）之後必跑**
 * ——那支依「該渠道 `status=1` 的 `cash_flow_banks` 列」整份重算 available_banks，聯集條目會被沖掉。
 * ⚠️ **只跑 `cash-flow:sync` 不會**：該命令對 available_banks 零引用（見下方〈誰才是重建者〉）。
 * 漏跑的後果：帶歷史碼的會員綁定／訂單（近 30 天 g70 有 913 筆 WTMY02 訂單）代付比對不中 → 轉人工。
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 追加式、只補缺席碼；預設 dry-run，`--execute` 才寫入；
 * BLOCKED 防呆任一觸發即整體攔截零寫入（含跑錯白標 DB 的 group／currency 檢查）。
 *
 * ## 誰才是重建者（2026-09-10 實查更正）
 *
 * ⚠️ **不是 `cash-flow:sync`。** `SyncCashFlowsCommand` 對 `available_banks` 零引用，
 * 也沒有任何寫 `agent_cash_flows` 的路徑；它建的 `banks` 列一律 `agent_group_id = 0`（base 層）。
 * 重建 available_banks 的是 `agent:add-agent-cash-flow`——`buildAvailableBanks()`
 * （`AddAgentCashFlow.php:1651`）只 map「該渠道 `status=1` 的 `cash_flow_banks` 列」，
 * **沒有對應 active cfb 列的項目會被丟掉**；該渠道 cfb 為零列時提早 return、整份不動。
 *
 * 兩支由 `cash-flow:auto-sync` 依序串起（`AutoSyncCashFlowCommand.php:309` 呼 sync、
 * `:358` 呼 add-agent-cash-flow），所以實務上「跑完 sync」多半兩者都跑到——但只跑
 * `cash-flow:sync --ids=` 時本命令的成果不會被沖掉。
 *
 * ## 退役條件
 * 需要**同時**達成：
 * 1. 銀行軌1 rename 完成，會員綁定與 banks 收斂為單套碼；且
 * 2. 歷史訂單快照碼不再參與 runtime 比對（或代付 matching 改走 `cash_flow_banks` 外鍵查表，
 *    即「方案 B2 代付查表化」全面開閘）。
 *
 * 根治方向是 matching identity fallback（尚未排期），本命令是在那之前的止血。
 *
 * ## 關聯
 * - 上位路線：`docs/未完成計劃/RB/TODO.md` §3.1（五軌總綱）＋ §4.4（Wintoto 代付雙碼 backlog）
 * - 代付查表化（B2）：同上文件「代付查表化灰度開閘」列
 *
 * 背景：banks 表已收斂為單套碼（多為 WTMYxx canonical），但會員綁定與訂單快照仍帶
 * 歷史碼（MayBank/HongLeongBank/TPMYxx…，近 30 天 g70 有 913 筆 WTMY02 訂單而該
 * group 無任何 WTMY02 banks 列）；代付 matching 以 $order->bank_code 字面比對
 * BankCode_ELG，任何一套碼缺席即「不支援銀行」→ fallback 人工。單套化改寫會反向
 * 斷掉舊碼訂單，故止血方向為雙碼聯集：同一家銀行的所有歷史碼在每條 ACF 都各自
 * 對到該 ACF 自己的三方碼。
 *
 * 修法（追加式、最小變更）：以 IDENTITY_CODE_SETS（同一家銀行的等價碼集合）對每條
 * 目標 ACF 推導缺口——集合內任一碼已存在於該 ACF 清單（坐實來源），即為集合內其餘
 * 缺席碼追加條目；新條目完整複製坐實來源 entry（保留 min/max 等欄位）僅替換
 * BankCode_ELG。三方碼一律取自坐實來源，禁止跨 ACF 借碼、禁止憑空發明。
 *
 * 防呆（BLOCKED 任一發生即整體攔截零寫入）：
 * - ACF 存在且 agent_group_id / currency_id 相符（跑錯白標 DB 在此被擋）；
 * - 同一集合在該 ACF 的多筆既有條目三方碼不一致 → 轉人工；
 * - 坐實來源之間 entry 其餘欄位（限額等）不一致 → 轉人工；
 * - 集合在該 group 的 active canonical bank（status=1）不唯一（0 或 >1）時不追加，
 *   僅列 SKIP 報表（例：AFFIN 兩 group 均已停用）。
 *
 * rollback 底稿：依執行輸出的 CREATE 清單，自 available_banks 移除該些條目即可。
 * ⚠️ 非 sync-safe：`agent:add-agent-cash-flow` 重建 available_banks 後需重跑本命令（冪等）。
 *
 * php artisan cash-flow:backfill-wintoto-payout-bankcode-aliases            # dry-run（預設）
 * php artisan cash-flow:backfill-wintoto-payout-bankcode-aliases --execute  # 實際執行（需確認）
 */
class BackfillWintotoPayoutBankcodeAliases extends Command
{
    public const BANK_TYPE = 1;

    /** VWorld connection 的 MYR */
    public const CURRENCY_ID = 1;

    /**
     * 目標 ACF（2026-07-14 排查拍板：Wintoto g70/g93 全部 active MYR 代付 ACF）。
     * 179/297=TTPay(cf39)、304/302=KIRA(cf92)、84=AllPay(cf23)、220/298=TTPay(cf39)、327=DGPay代付(cf120)。
     *
     * @var array<int, int> acf_id => 預期 agent_group_id
     */
    public const TARGET_ACF = [
        179 => 70,
        297 => 70,
        304 => 70,
        84 => 93,
        220 => 93,
        298 => 93,
        302 => 93,
        327 => 93,
    ];

    /**
     * 同一家銀行的等價碼集合（canonical WTMYxx ＋ 歷史 alias；2026-07-14 以 DB 實據拍板：
     * cf39 的 WTMY→三方碼對照、各 ACF 既有 pair、banks 停用列名稱）。
     * 單一碼的銀行（MBSB/RYT BANK/MYR0072…）無 alias 可補，不入表。
     *
     * @var list<list<string>>
     */
    public const IDENTITY_CODE_SETS = [
        ['WTMY01', 'PublicBank'],
        ['WTMY02', 'MayBank'],
        ['WTMY03', 'HongLeongBank'],
        ['WTMY04', 'CIMB'],
        ['WTMY05', 'MYAB'],
        ['WTMY06', 'AFFIN', 'AFIN', 'TPMY08'],
        ['WTMY07', 'ABMB'],
        ['WTMY08', 'BIMB', 'TPMY07'],
        ['WTMY09', 'OCBC'],
        ['WTMY10', 'RHB'],
        ['WTMY11', 'STCB'],
        ['WTMY12', 'UNOB'],
        ['WTMY13', 'HSBC', 'TPMY14'],
        ['WTMY14', 'SINA', 'TPMY10'],
        ['WTMY15', 'AGRO', 'TPMY15'],
        ['WTMY16', 'RAKY'],
        ['WTMY17', 'RAJH', 'MYR0068'],
        ['WTMY18', 'KFHB'],
        ['WTMY19', 'MUAM'],
        ['WTMY20', 'CCCC', 'TPMY20'],
        ['WTMY91', 'TPMY21'],
        ['WTMY92', 'VWMY34'],
    ];

    protected $signature = 'cash-flow:backfill-wintoto-payout-bankcode-aliases
                            {--execute : 實際執行補寫（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = '補 Wintoto g70/g93 代付渠道 available_banks 的雙碼聯集條目（預設 dry-run）';

    public function handle(): int
    {
        $errors = [];
        $plan = $this->buildPlan($errors);

        if ($errors !== []) {
            $this->error(sprintf('目標與 DB 現況不符（%d 項），整體攔截、未寫入任何資料：', count($errors)));
            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        $this->table(
            ['acf_id', 'group', 'identity(canonical)', 'BankCode_ELG', 'BankCode_Third', 'action'],
            array_map(static fn (array $row): array => [
                $row['acf_id'],
                $row['agent_group_id'],
                $row['canonical'],
                $row['target'],
                $row['third'],
                $row['action'],
            ], $plan),
        );

        $creates = array_values(array_filter($plan, static fn (array $row): bool => $row['action'] === 'CREATE'));
        $skips = count(array_filter($plan, static fn (array $row): bool => str_starts_with($row['action'], 'SKIP')));

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf('🔍 Dry-run 模式，未寫入。待追加 %d 筆條目、略過 %d 筆。加 --execute 以實際執行。', count($creates), $skips));

            return self::SUCCESS;
        }

        if ($creates === []) {
            $this->newLine();
            $this->info('✅ 無需變更（全部已就緒，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認在 %d 條 ACF 的 available_banks 追加 %d 筆條目？', count(array_unique(array_column($creates, 'acf_id'))), count($creates)), false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $this->executeBackfill($creates);

        $this->newLine();
        $this->info(sprintf('✅ 完成，追加 %d 筆條目（rollback 底稿＝上表 CREATE 列，自 available_banks 移除即可還原）。', count($creates)));

        return self::SUCCESS;
    }

    /**
     * 逐目標 ACF × 等價碼集合推導計劃；驗證錯誤累積於 $errors（非空即整體攔截）。
     *
     * @param  list<string>  $errors
     * @return list<array{acf_id: int, agent_group_id: int, canonical: string, target: string, third: string, action: string, entry: array<string, mixed>}>
     */
    private function buildPlan(array &$errors): array
    {
        $plan = [];

        foreach (self::TARGET_ACF as $acfId => $expectedGroupId) {
            $agentCashFlow = AgentCashFlow::query()->find($acfId);

            if ($agentCashFlow === null) {
                $errors[] = sprintf('ACF %d：DB 無此列', $acfId);

                continue;
            }

            if ((int) $agentCashFlow->agent_group_id !== $expectedGroupId) {
                $errors[] = sprintf('ACF %d：agent_group_id=%d ≠ 預期 %d', $acfId, (int) $agentCashFlow->agent_group_id, $expectedGroupId);

                continue;
            }

            if ((int) $agentCashFlow->currency_id !== self::CURRENCY_ID) {
                $errors[] = sprintf('ACF %d：currency_id=%s ≠ 預期 %d', $acfId, (string) $agentCashFlow->currency_id, self::CURRENCY_ID);

                continue;
            }

            $availableBanks = $agentCashFlow->info['available_banks'] ?? null;

            if (!is_array($availableBanks)) {
                $errors[] = sprintf('ACF %d：info.available_banks 缺失或格式錯誤', $acfId);

                continue;
            }

            foreach (self::IDENTITY_CODE_SETS as $codeSet) {
                $plan = [...$plan, ...$this->planForIdentity($acfId, $expectedGroupId, $codeSet, $availableBanks, $errors)];
            }
        }

        return $plan;
    }

    /**
     * 單一 ACF × 單一等價碼集合的追加計劃。
     *
     * @param  list<string>  $codeSet
     * @param  list<array<string, mixed>>  $availableBanks
     * @param  list<string>  $errors
     * @return list<array{acf_id: int, agent_group_id: int, canonical: string, target: string, third: string, action: string, entry: array<string, mixed>}>
     */
    private function planForIdentity(int $acfId, int $groupId, array $codeSet, array $availableBanks, array &$errors): array
    {
        // 坐實來源：該 ACF 既有清單中屬於本集合的條目（無即代表渠道不支援此銀行，不追加）
        $sources = array_values(array_filter(
            $availableBanks,
            static fn (array $bank): bool => in_array((string) ($bank['BankCode_ELG'] ?? ''), $codeSet, true),
        ));

        if ($sources === []) {
            return [];
        }

        $thirds = array_unique(array_map(static fn (array $bank): string => (string) ($bank['BankCode_Third'] ?? ''), $sources));

        if (count($thirds) > 1) {
            $errors[] = sprintf('ACF %d：集合 [%s] 既有條目三方碼不一致（%s），轉人工複核', $acfId, implode(', ', $codeSet), implode(' / ', $thirds));

            return [];
        }

        // 坐實來源除 BankCode_ELG 外的欄位（限額等）必須一致，否則無法決定新條目形狀
        $shapes = array_unique(array_map(static function (array $bank): string {
            unset($bank['BankCode_ELG']);
            ksort($bank);

            return (string) json_encode($bank);
        }, $sources));

        if (count($shapes) > 1) {
            $errors[] = sprintf('ACF %d：集合 [%s] 既有條目欄位形狀不一致，轉人工複核', $acfId, implode(', ', $codeSet));

            return [];
        }

        $canonicals = $this->resolveActiveCanonicals($groupId, $codeSet);
        $canonicalLabel = $canonicals === [] ? '(無 active)' : implode('/', $canonicals);

        $existingCodes = array_map(static fn (array $bank): string => (string) ($bank['BankCode_ELG'] ?? ''), $availableBanks);
        $missingCodes = array_values(array_diff($codeSet, $existingCodes));

        if (count($canonicals) > 1 && $missingCodes !== []) {
            // 收斂後不應發生；發生即該 group 又冒出同銀行雙 active 碼，整體攔截
            $errors[] = sprintf('group %d：集合 [%s] 有多個 active canonical（%s），banks 表未收斂', $groupId, implode(', ', $codeSet), $canonicalLabel);
        }

        $action = match (true) {
            count($canonicals) === 0 => 'SKIP(無 active canonical)',
            count($canonicals) > 1 => 'SKIP(canonical 不唯一)',
            default => 'CREATE',
        };

        $rows = [];
        foreach ($missingCodes as $code) {
            $entry = $sources[0];
            $entry['BankCode_ELG'] = $code;

            $rows[] = [
                'acf_id' => $acfId,
                'agent_group_id' => $groupId,
                'canonical' => $canonicalLabel,
                'target' => $code,
                'third' => $thirds[0],
                'action' => $action,
                'entry' => $entry,
            ];
        }

        return $rows;
    }

    /**
     * 集合內於該 group 有 active bank 列（type=1, MYR, status=1）的碼。
     *
     * @param  list<string>  $codeSet
     * @return list<string>
     */
    private function resolveActiveCanonicals(int $groupId, array $codeSet): array
    {
        return Bank::query()
            ->where('agent_group_id', $groupId)
            ->where('type', self::BANK_TYPE)
            ->where('currency_id', self::CURRENCY_ID)
            ->whereIn('code', $codeSet)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /**
     * 執行補寫（單一交易）：逐 ACF lockForUpdate 後追加條目，只動 available_banks。
     *
     * @param  list<array{acf_id: int, agent_group_id: int, canonical: string, target: string, third: string, action: string, entry: array<string, mixed>}>  $creates
     */
    private function executeBackfill(array $creates): void
    {
        $byAcf = [];
        foreach ($creates as $row) {
            $byAcf[$row['acf_id']][] = $row;
        }

        DB::transaction(function () use ($byAcf): void {
            foreach ($byAcf as $acfId => $rows) {
                /** @var AgentCashFlow $agentCashFlow */
                $agentCashFlow = AgentCashFlow::query()->lockForUpdate()->findOrFail($acfId);

                $info = $agentCashFlow->info;

                foreach ($rows as $row) {
                    $info['available_banks'][] = $row['entry'];
                }

                $agentCashFlow->info = $info;
                $agentCashFlow->save();
            }
        });
    }
}
