<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 依代付 ACF 的 `available_banks`，補上缺少的 `cash_flow_banks`（以下簡稱 cfb）單列。
 *
 * 【分類】Bank — ⚠️ 非 sync-safe 補列命令，過渡期工具（退役條件見下方）。
 *
 * ## 這支補的洞
 *
 * 代付要送給三方的銀行碼有兩條路徑：舊的讀 ACF 的 `available_banks` JSON，新的查 cfb 表，
 * 由 `PAYOUT_BANK_LOOKUP_PROVIDERS` 逐家灰度切換。切換的前提是兩條路徑答案一致，但有些
 * 金流商的 cfb 根本是空的（實例：VWorld MaxPay `cash_flow_id=114` 零列）——JSON 路徑通得過，
 * 一開閘就整批查空而擋單。
 *
 * 既有工具都補不了這個形態：`cash-flow:sync` 會先停用整個渠道的 cfb 再依碼表重建（見下方
 * 契約），`bank:fix-cfb-provider-code` 以「cfb 列已存在」為前提，`bank:deactivate-cfb-rows`
 * 只往停用的方向走。本命令只做一件事：**新增 cfb 列，不動 `banks` 表**。
 *
 * ## 權威來源是 ACF 的 JSON
 *
 * 每個 `{BankCode_ELG, BankCode_Third}` 轉成一列 cfb。同源保證灰度開閘前後答案相同，且用的是
 * 該代理群組實際在送的碼。🔴 **三方碼的對錯本命令無從驗證，只驗結構**——同一家銀行在不同
 * 金流商的碼可以完全相反（MaxPay 用全名 `Bank Of China`，GMPay 用短名 `BOC`），下手前回該
 * 金流商自己的 api.md 核對，並把依據寫進 `--reason`。
 *
 * ## `bank_id` 怎麼定位
 *
 * base 層（`agent_group_id = 0`）優先，base 沒有服務中的列時才用該 ACF 的代理群組層；兩層都
 * 沒有就是「定位不到」，預設整批零寫入，要放行得明示 `--skip-unresolvable`。
 *
 * 比對 `banks.code` 一律 `BINARY` **逐字相同**，不做名稱模糊比對、不順手轉大寫。欄位 collation
 * 是 `utf8mb4_unicode_ci`（大小寫不敏感），不加 `BINARY` 會把別的碼算進來——VWorld 的 `banks`
 * 現存 `MayBank` 與 `MAYBANK` 兩種變體共 10 列，是不同的銀行身分。名稱模糊比對正是
 * `cash-flow:sync` 配錯與反向改碼的根源，這裡刻意不用。
 *
 * ## 為什麼「一次只跑一張 ACF」
 *
 * cfb 的儲存唯一鍵是 `(cash_flow_id, currency_id, bank_id)`，**不含 `bank_code`**；而 runtime
 * 查表用的是 `(cash_flow_id, currency_id, bank_code)`。同一個碼掛在兩個不同 `bank_id` 上兩列
 * 都能落庫，但 runtime 只取最早那列 ⇒ 後建的等於沒建。
 *
 * 所以同一批裡出現「同碼不同銀行列」時本命令直接擋下、不任選（兩個候選都還沒建，任選等於憑
 * 執行順序決定銀行身分）；但若 DB 裡**已經有**一列一致且服務中的映射，就標「銀行身分待核」
 * 跳過、不再多建一列。⇒ 兩張 ACF 分兩趟跑，先跑的那張擁有銀行身分。
 *
 * ## 🔴 契約：本命令非 sync-safe
 *
 * `cash-flow:sync` 的 `processWithdrawalBanks()` 第一件事就是 `disableAllCashFlowBanks()`
 * （`SyncCashFlowsCommand.php:1537`），停用該渠道全部 cfb 後才依碼表重建，而重建只認碼表名稱
 * 比對得到的 base 層銀行 ⇒ **本命令建立的群組層列會被停用且不會被重啟**。
 * 每次對該 `cash_flow_id` 跑過 `cash-flow:sync` 之後都必須重跑本命令補回，
 * 清單見 `docs/未完成計劃/金流商架構重構/銀行收斂/sync全量重建面與補償命令.md`。
 *
 * ## 只寫五個欄位，其餘留給欄位預設與它們的擁有者
 *
 * `min_amount`／`max_amount` 沿用 DB 預設 0（該欄語意是「0 ＝ 不限制」），要設限額請另跑
 * `bank:set-amount-limits`。`standard_bank_id` 是便利欄、擁有者是 `bank:sync-projection`，
 * 本命令不代寫；**建議每次跑完本命令後補跑一次投影**，否則新列的該欄會是 NULL。
 *
 * ## 撤回
 *
 * 審計走 `bank_dedup_logs`（`action=manual_cfb_add`，`detail.id` 是新列的 cfb id）。
 * **有審計不等於有撤回能力**：`bank:dedup-rollback` 的白名單是 exact 比對、本 action 不在其中，
 * 擴充它會改變既有 rollback 行為屬另案。要還原請依審計的 `detail.id` 逐列停用
 * （`bank:deactivate-cfb-rows`）或人工刪除。
 *
 * ## 退役條件
 *
 * 與 `CashFlow/Guard/` 那批守護命令同一個根因，任一達成即可刪除：
 *
 * 1. `cash-flow:sync` 改為以 `standard_bank_id`／外鍵重建 cfb（不再先停用整表、也不再靠名稱
 *    模糊比對），缺列不會再生；或
 * 2. 銀行軌①收斂完成，群組層不再有 base 層沒有的碼——屆時 sync 依碼表重建即可覆蓋全部項目，
 *    本命令沒有標的。
 *
 * 在那之前刪除本命令＝每次對該渠道跑過 sync 之後，代付查表就少一批碼。
 *
 * php artisan bank:add-cfb-rows --acf=319 --reason='...'
 * php artisan bank:add-cfb-rows --acf=319 --reason='...' --execute --force
 */
class AddCfbRowsCommand extends Command
{
    protected $signature = 'bank:add-cfb-rows
                            {--acf=* : 來源 agent_cash_flows.id（可指定多個，但同碼不同銀行列會擋下）}
                            {--target= : 只補這一個 BankCode_ELG（不給就補該 ACF 所有缺的）}
                            {--reason= : 補列理由與依據，寫入審計供日後追溯（必填）}
                            {--skip-unresolvable : 明示放行「定位不到服務中銀行列」的項目}
                            {--allow-alias : 明示放行「不同銀行碼配相同三方碼」}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '依代付 ACF 的 available_banks 補上缺少的 cash_flow_banks 列（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** `bank_dedup_logs.code` 是 varchar(16)；DB 開 STRICT_ALL_TABLES ⇒ 超長是拋錯不是截斷。 */
    private const CODE_MAX_LENGTH = 16;

    /** `cash_flow_banks.bank_code` 與 `provider_bank_code` 都是 varchar(64)。 */
    private const CFB_CODE_MAX_LENGTH = 64;

    private const AUDIT_ACTION = 'manual_cfb_add';

    public function handle(): int
    {
        $rawAcfIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('acf'));
        $invalidIds = array_values(array_filter(
            $rawAcfIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalidIds !== []) {
            $this->error(sprintf(
                '--acf 必須是純數字的 agent_cash_flows.id，收到「%s」。多個請重複旗標（--acf=319 --acf=315），不可用逗號分隔。',
                implode('」「', $invalidIds)
            ));

            return self::FAILURE;
        }

        $acfIds = array_values(array_unique(array_map('intval', $rawAcfIds)));
        $target = (string) $this->option('target');
        $reason = trim((string) $this->option('reason'));

        $invalid = $this->validateOptions($acfIds, $target, $reason);

        if ($invalid !== null) {
            $this->error($invalid);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        $plan = $this->classifyRows($acfIds, $target);

        $this->reportPlan($plan);

        if ($plan['blockers'] !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($plan['blockers'] as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($plan['targets'] === []) {
            $this->info('沒有需要新增的 cash_flow_banks 列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可新增，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($plan['targets'])
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($acfIds, $target, $reason);

        $this->info(sprintf(
            '新增完成：%d 列（審計見 bank_dedup_logs，action=%s）。',
            $written,
            self::AUDIT_ACTION
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $acfIds
     */
    private function validateOptions(array $acfIds, string $target, string $reason): ?string
    {
        if ($acfIds === []) {
            return '--acf 必填：至少要指定一張來源 agent_cash_flows.id。';
        }

        if ($target !== '' && mb_strlen($target) > self::CODE_MAX_LENGTH) {
            return sprintf('--target 超過 %d 字元，審計的 code 欄位放不下。', self::CODE_MAX_LENGTH);
        }

        if ($reason === '') {
            return '--reason 必填：補列依據（該金流商 api.md 的哪份清單）會寫入審計，供日後追溯與還原。';
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入影響代付查表——寧可先擋下。';
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH);
        }

        return null;
    }

    /**
     * 分流管線：來源 → 候選 → 定位 → 跨 ACF 一致性 → 批內收斂 → 對既有列 → 別名。
     *
     * `$lock=true` 供交易內重驗使用。預檢到人工確認之間的窗口是分鐘級，期間代理後台、對齊命令
     * 或另一個執行者都可能改動同一批資料，所以判定必須用鎖到的值重算——否則鎖只做了序列化、
     * 沒關掉 TOCTOU 窗口。旗標（`--skip-unresolvable`／`--allow-alias`）在方法內讀取，
     * 確保預演與重驗用的是同一組條件。
     *
     * @param  list<int>  $acfIds
     * @return array{targets: list<array<string, mixed>>, satisfied: list<string>, skipped: list<string>, aliases: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $acfIds, string $target, bool $lock = false): array
    {
        $blockers = [];
        $skipped = [];

        ['sources' => $sources, 'blockers' => $sourceBlockers] = $this->loadSources($acfIds, $lock);
        $blockers = array_merge($blockers, $sourceBlockers);

        ['candidates' => $candidates, 'blockers' => $candidateBlockers] = $this->collectCandidates($sources, $target);
        $blockers = array_merge($blockers, $candidateBlockers);

        [
            'resolved' => $resolved,
            'unresolvable' => $unresolvable,
            'blockers' => $resolveBlockers,
        ] = $this->resolveBankRows($candidates);
        $blockers = array_merge($blockers, $resolveBlockers);

        // 「定位不到」是唯一可以用旗標放行的分類（D14）；其餘阻擋都沒有繞道
        if ((bool) $this->option('skip-unresolvable')) {
            $skipped = array_merge($skipped, $unresolvable);
        } else {
            $blockers = array_merge($blockers, $unresolvable);
        }

        $blockers = array_merge($blockers, $this->detectCrossAcfConflicts($resolved, $acfIds, $lock));

        ['merged' => $merged, 'blockers' => $mergeBlockers] = $this->mergeBatch($resolved);
        $blockers = array_merge($blockers, $mergeBlockers);

        [
            'targets' => $targets,
            'satisfied' => $satisfied,
            'skipped' => $existingSkipped,
            'blockers' => $existingBlockers,
            'existing' => $existing,
        ] = $this->compareWithExisting($merged, $lock);
        $skipped = array_merge($skipped, $existingSkipped);
        $blockers = array_merge($blockers, $existingBlockers);

        ['aliases' => $aliases, 'blockers' => $aliasBlockers] = $this->detectAliasConflicts($targets, $existing);
        $blockers = array_merge($blockers, $aliasBlockers);

        return [
            'targets' => $targets,
            'satisfied' => $satisfied,
            'skipped' => $skipped,
            'aliases' => $aliases,
            'blockers' => $blockers,
        ];
    }

    /**
     * 載入來源 ACF，並驗它確實是代付渠道、幣別存在。
     *
     * 同時對 `cash_flows` 取鎖作為序列化錨點：兩個執行者可以挑不同的 ACF 卻寫進同一個金流商，
     * 而 cfb 唯一鍵不含 `bank_code`、擋不住「同碼不同 bank_id」的競態，只鎖來源 ACF 不夠。
     *
     * @param  list<int>  $acfIds
     * @return array{sources: list<array{acf: AgentCashFlow, cash_flow_id: int, currency_id: int, agent_group_id: int}>, blockers: list<string>}
     */
    private function loadSources(array $acfIds, bool $lock): array
    {
        $query = AgentCashFlow::query()->whereIn('id', $acfIds);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get()->keyBy('id');

        $cashFlowIds = $rows->pluck('cash_flow_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
        $cashFlowQuery = CashFlow::query()->whereIn('id', $cashFlowIds);

        if ($lock) {
            $cashFlowQuery->lockForUpdate();
        }

        $cashFlows = $cashFlowQuery->get()->keyBy('id');

        $currencyIds = $rows->pluck('currency_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
        $knownCurrencyIds = Currency::query()->whereIn('id', $currencyIds)->pluck('id')
            ->map(static fn ($v): int => (int) $v)->all();

        $sources = [];
        $blockers = [];

        foreach ($acfIds as $id) {
            $acf = $rows->get($id);

            if ($acf === null) {
                $blockers[] = sprintf('acf=%d 不存在', $id);

                continue;
            }

            $cashFlowId = (int) $acf->cash_flow_id;
            $cashFlow = $cashFlows->get($cashFlowId);

            // available_banks 是代付通道的語意，代收 ACF 拿它建 cfb 沒有任何路徑會讀
            if ($cashFlow === null || (int) $cashFlow->type !== 3) {
                $blockers[] = sprintf(
                    'acf=%d 的金流商 %d 不是代付渠道（cash_flows.type≠3），available_banks 對它沒有語意',
                    $id,
                    $cashFlowId
                );

                continue;
            }

            $currencyId = (int) $acf->currency_id;

            if (!in_array($currencyId, $knownCurrencyIds, true)) {
                $blockers[] = sprintf(
                    'acf=%d 的幣別 %d 不存在於 currencies，建出來的 cfb 列不會有任何渠道對得上',
                    $id,
                    $currencyId
                );

                continue;
            }

            $sources[] = [
                'acf' => $acf,
                'cash_flow_id' => $cashFlowId,
                'currency_id' => $currencyId,
                'agent_group_id' => (int) $acf->agent_group_id,
            ];
        }

        return ['sources' => $sources, 'blockers' => $blockers];
    }

    /**
     * 把來源 ACF 的每個 available_banks 項目轉成候選，並做結構驗證。
     *
     * 只驗結構不驗業務正確性：擋壞掉的項目、非字串的碼、空字串、超長值、同一張 ACF 內把同一個
     * 銀行碼對到兩個不同三方碼。**不 trim、不轉數字**——碼是識別碼，`007` 與 `7` 是兩個碼，
     * 前導零與空白都必須原樣保留（比對慣例與 `WithdrawalPayoutTrait` 一致）。
     *
     * 來源 ACF 的 JSON 只要有一個項目壞掉就整張擋下、不挑好的用：權威來源本身可疑時，
     * 正確處置是讓人看一眼，而不是靜靜地少建幾列。
     *
     * @param  list<array{acf: AgentCashFlow, cash_flow_id: int, currency_id: int, agent_group_id: int}>  $sources
     * @return array{candidates: list<array<string, mixed>>, blockers: list<string>}
     */
    private function collectCandidates(array $sources, string $target): array
    {
        $candidates = [];
        $blockers = [];
        $matchedFilter = false;

        foreach ($sources as $source) {
            $acfId = (int) $source['acf']->id;
            $entries = $this->readEntries($source['acf']);
            $seen = [];

            foreach ($entries as $index => $entry) {
                $position = is_int($index) ? $index : 0;
                $problem = $this->inspectEntry($entry);

                if ($problem !== null) {
                    $blockers[] = sprintf('acf=%d 第 %d 個項目結構不合法：%s', $acfId, $position + 1, $problem);

                    continue;
                }

                /** @var array{BankCode_ELG: string, BankCode_Third: string} $entry */
                $entryElg = $entry['BankCode_ELG'];
                $entryThird = $entry['BankCode_Third'];

                if (array_key_exists($entryElg, $seen)) {
                    if ($seen[$entryElg] !== $entryThird) {
                        $blockers[] = sprintf(
                            'acf=%d 把同一個銀行碼對到兩個不同的三方碼（%s → %s／%s），來源自相矛盾',
                            $acfId,
                            $entryElg,
                            $seen[$entryElg],
                            $entryThird
                        );
                    }

                    // 同碼同三方碼＝單純重複，取一次就好
                    continue;
                }

                $seen[$entryElg] = $entryThird;

                if ($target !== '' && $entryElg !== $target) {
                    continue;
                }

                $matchedFilter = true;

                $candidates[] = [
                    'acf_id' => $acfId,
                    'source_group' => $source['agent_group_id'],
                    'idx' => $position,
                    'cash_flow_id' => $source['cash_flow_id'],
                    'currency_id' => $source['currency_id'],
                    'target' => $entryElg,
                    'third' => $entryThird,
                ];
            }
        }

        if ($target !== '' && $sources !== [] && !$matchedFilter) {
            $blockers[] = sprintf('--target=%s 不在任何來源 ACF 的 available_banks 內，沒有東西可補', $target);
        }

        return ['candidates' => $candidates, 'blockers' => $blockers];
    }

    /**
     * 單一項目的結構檢查；回傳不合法的原因，`null` 表示通過。
     */
    private function inspectEntry(mixed $entry): ?string
    {
        if (!is_array($entry)) {
            return '不是物件';
        }

        foreach (['BankCode_ELG', 'BankCode_Third'] as $key) {
            if (!array_key_exists($key, $entry)) {
                return sprintf('缺少 %s', $key);
            }

            if (!is_string($entry[$key])) {
                return sprintf('%s 不是字串（實際為 %s）', $key, get_debug_type($entry[$key]));
            }

            if ($entry[$key] === '') {
                return sprintf('%s 是空字串', $key);
            }
        }

        if (mb_strlen($entry['BankCode_ELG']) > self::CODE_MAX_LENGTH) {
            return sprintf(
                'BankCode_ELG「%s」超過 %d 字元，審計 bank_dedup_logs.code 放不下（DB 開 STRICT_ALL_TABLES，超長是拋錯不是截斷）',
                $entry['BankCode_ELG'],
                self::CODE_MAX_LENGTH
            );
        }

        if (mb_strlen($entry['BankCode_Third']) > self::CFB_CODE_MAX_LENGTH) {
            return sprintf(
                'BankCode_Third「%s」超過 %d 字元，cash_flow_banks.provider_bank_code 放不下',
                $entry['BankCode_Third'],
                self::CFB_CODE_MAX_LENGTH
            );
        }

        return null;
    }

    /**
     * 定位每個候選的 `bank_id`：base 層優先，base 無服務中的列才用該 ACF 的群組層。
     *
     * 刻意**不對 `banks` 取鎖**：定位條件包含 `BINARY code = ?` 而 `banks.code` 沒有索引，
     * 加 `lockForUpdate()` 會把該幣別掃過的列全部鎖住——對線上是遠比「銀行列剛好在交易中途被
     * 停用」更大的風險。萬一真的撞上，結果是建出一列指向剛停用銀行的 cfb，
     * 可用 `bank:deactivate-cfb-rows` 收乾淨。
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{resolved: list<array<string, mixed>>, unresolvable: list<string>, blockers: list<string>}
     */
    private function resolveBankRows(array $candidates): array
    {
        $resolved = [];
        $unresolvable = [];
        $blockers = [];

        foreach ($candidates as $candidate) {
            $currencyId = (int) $candidate['currency_id'];
            $code = (string) $candidate['target'];
            $sourceGroup = (int) $candidate['source_group'];

            $baseRows = $this->activeBankRows(0, $currencyId, $code);
            $groupRows = $sourceGroup === 0 ? [] : $this->activeBankRows($sourceGroup, $currencyId, $code);

            if (count($baseRows) > 1) {
                $blockers[] = sprintf(
                    'acf=%d 的「%s」在 base 層有多個服務中的候選銀行列（%s），身分不明不能任選',
                    $candidate['acf_id'],
                    $code,
                    $this->describeBankRows($baseRows)
                );

                continue;
            }

            if ($baseRows !== []) {
                $identityConflict = $this->detectLayerIdentityConflict($baseRows[0], $groupRows);

                if ($identityConflict !== null) {
                    $blockers[] = sprintf(
                        'acf=%d 的「%s」base 層與群組層對這個碼的銀行身分不一致：%s',
                        $candidate['acf_id'],
                        $code,
                        $identityConflict
                    );

                    continue;
                }

                $resolved[] = $this->withBankRow($candidate, $baseRows[0], 'base');

                continue;
            }

            if (count($groupRows) > 1) {
                $blockers[] = sprintf(
                    'acf=%d 的「%s」在代理群組 %d 有多個服務中的候選銀行列（%s），身分不明不能任選',
                    $candidate['acf_id'],
                    $code,
                    $sourceGroup,
                    $this->describeBankRows($groupRows)
                );

                continue;
            }

            if ($groupRows !== []) {
                $resolved[] = $this->withBankRow($candidate, $groupRows[0], 'group');

                continue;
            }

            $unresolvable[] = sprintf(
                'acf=%d 的「%s」在 base 層與代理群組 %d 都定位不到服務中的銀行列（比對逐字相同），無法決定 bank_id',
                $candidate['acf_id'],
                $code,
                $sourceGroup
            );
        }

        return ['resolved' => $resolved, 'unresolvable' => $unresolvable, 'blockers' => $blockers];
    }

    /**
     * base 與群組層是否對同一個碼指向不同的銀行身分。
     *
     * 判準只有 `standard_bank_id`——那是唯一機器讀得懂的身分標記。任一側為 NULL 就無從判定，
     * 此時不擋（`bank:sync-mappings` 的回填率並非 100%，一律擋會讓多數正常情況也走不下去）。
     *
     * @param  array<string, mixed>  $baseRow
     * @param  list<array<string, mixed>>  $groupRows
     */
    private function detectLayerIdentityConflict(array $baseRow, array $groupRows): ?string
    {
        if (count($groupRows) !== 1) {
            return null;
        }

        $baseStandard = $baseRow['standard_bank_id'];
        $groupStandard = $groupRows[0]['standard_bank_id'];

        if ($baseStandard === null || $groupStandard === null || (int) $baseStandard === (int) $groupStandard) {
            return null;
        }

        return sprintf(
            'base banks=%d 對到標準銀行 %d，群組 banks=%d 對到標準銀行 %d',
            (int) $baseRow['id'],
            (int) $baseStandard,
            (int) $groupRows[0]['id'],
            (int) $groupStandard
        );
    }

    /**
     * 撈某一層服務中的同碼銀行列。
     *
     * `Bank` 沒有掛 SoftDeletes trait，`deleted_at` 必須自己濾；比對用 `BINARY` 逐字相同。
     *
     * @return list<array<string, mixed>>
     */
    private function activeBankRows(int $agentGroupId, int $currencyId, string $code): array
    {
        return Bank::query()
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereRaw('BINARY code = ?', [$code])
            ->orderBy('id')
            ->get(['id', 'agent_group_id', 'code', 'name', 'standard_bank_id'])
            ->map(static fn (Bank $bank): array => [
                'id' => (int) $bank->id,
                'agent_group_id' => (int) $bank->agent_group_id,
                'code' => (string) $bank->code,
                'name' => (string) $bank->name,
                'standard_bank_id' => $bank->standard_bank_id === null ? null : (int) $bank->standard_bank_id,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function describeBankRows(array $rows): string
    {
        return implode('、', array_map(
            static fn (array $row): string => sprintf('banks=%d「%s」', (int) $row['id'], (string) $row['name']),
            $rows
        ));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $bankRow
     * @return array<string, mixed>
     */
    private function withBankRow(array $candidate, array $bankRow, string $layer): array
    {
        return $candidate + [
            'bank_id' => (int) $bankRow['id'],
            'bank_group' => (int) $bankRow['agent_group_id'],
            'bank_name' => (string) $bankRow['name'],
            'layer' => $layer,
        ];
    }

    /**
     * 跨 ACF 一致性：`--acf` 只限定來源，但 cfb 是金流商＋幣別共用、沒有 ACF 也沒有群組條件。
     *
     * 只指定測試組的 ACF 也會建立正式組實際會用到的映射 ⇒ 必須唯讀掃描同一個
     * `(cash_flow_id, currency_id)` 底下**其他服務中**的 ACF，看它們是否把同一個銀行碼對到
     * 不同的三方碼。停用的 ACF 不掃：`WithdrawalPayoutService::resolveChannels` 只撈
     * `status = 1` 的通道，停用的那張不會有訂單走到，它的舊映射不構成現行衝突。
     *
     * 其他 ACF 自己的 JSON 若有壞掉的項目，這裡靜靜略過——本命令只對 `--acf` 指定的來源負責，
     * 別人的資料品質不該在這裡擋下整批。
     *
     * @param  list<array<string, mixed>>  $resolved
     * @param  list<int>  $acfIds
     * @return list<string>
     */
    private function detectCrossAcfConflicts(array $resolved, array $acfIds, bool $lock): array
    {
        $blockers = [];
        $scopes = [];

        foreach ($resolved as $candidate) {
            $scopes[sprintf('%d|%d', $candidate['cash_flow_id'], $candidate['currency_id'])] = [
                'cash_flow_id' => (int) $candidate['cash_flow_id'],
                'currency_id' => (int) $candidate['currency_id'],
            ];
        }

        foreach ($scopes as $scopeKey => $scope) {
            $query = AgentCashFlow::query()
                ->where('cash_flow_id', $scope['cash_flow_id'])
                ->where('currency_id', $scope['currency_id'])
                ->where('status', 1)
                ->whereNotIn('id', $acfIds);

            if ($lock) {
                $query->lockForUpdate();
            }

            $mapping = [];

            foreach ($query->get() as $other) {
                foreach ($this->readEntries($other) as $entry) {
                    if (!is_array($entry)
                        || !isset($entry['BankCode_ELG'], $entry['BankCode_Third'])
                        || !is_string($entry['BankCode_ELG'])
                        || !is_string($entry['BankCode_Third'])
                    ) {
                        continue;
                    }

                    $mapping[$entry['BankCode_ELG']] ??= ['third' => $entry['BankCode_Third'], 'acf' => (int) $other->id];
                }
            }

            foreach ($resolved as $candidate) {
                if (sprintf('%d|%d', $candidate['cash_flow_id'], $candidate['currency_id']) !== $scopeKey) {
                    continue;
                }

                $known = $mapping[$candidate['target']] ?? null;

                if ($known === null || $known['third'] === $candidate['third']) {
                    continue;
                }

                $blockers[] = sprintf(
                    'acf=%d 的「%s」三方碼與 acf=%d 不一致（%s vs %s）；cfb 不分代理群組，一列會同時服務兩張 ACF',
                    $candidate['acf_id'],
                    $candidate['target'],
                    $known['acf'],
                    $candidate['third'],
                    $known['third']
                );
            }
        }

        return $blockers;
    }

    /**
     * 批內收斂：同一次執行產生的候選彼此比對。
     *
     * 1. 同 `bank_id` ＋同碼＋同三方碼 → 併成一次 INSERT，保留全部來源
     * 2. 同 `bank_id` 但碼或三方碼不同 → 擋（唯一鍵只容得下一列）
     * 3. 同 runtime 查表鍵但不同 `bank_id` → 擋，**不任選**（兩個候選都還沒建，
     *    任選等於憑執行順序決定銀行身分；正確做法是分兩趟跑，讓先跑的那張擁有身分）
     *
     * @param  list<array<string, mixed>>  $resolved
     * @return array{merged: list<array<string, mixed>>, blockers: list<string>}
     */
    private function mergeBatch(array $resolved): array
    {
        $byBank = [];
        $blockers = [];

        foreach ($resolved as $candidate) {
            $bankKey = sprintf('%d|%d|%d', $candidate['cash_flow_id'], $candidate['currency_id'], $candidate['bank_id']);
            $source = ['acf' => $candidate['acf_id'], 'g' => $candidate['source_group'], 'idx' => $candidate['idx']];

            if (!isset($byBank[$bankKey])) {
                $byBank[$bankKey] = [
                    'cash_flow_id' => (int) $candidate['cash_flow_id'],
                    'currency_id' => (int) $candidate['currency_id'],
                    'bank_id' => (int) $candidate['bank_id'],
                    'bank_group' => (int) $candidate['bank_group'],
                    'bank_name' => (string) $candidate['bank_name'],
                    'layer' => (string) $candidate['layer'],
                    'target' => (string) $candidate['target'],
                    'third' => (string) $candidate['third'],
                    'sources' => [$source],
                ];

                continue;
            }

            if ($byBank[$bankKey]['target'] !== $candidate['target'] || $byBank[$bankKey]['third'] !== $candidate['third']) {
                $blockers[] = sprintf(
                    '銀行列 banks=%d 在同一批裡被要求建成兩種映射（%s→%s／%s→%s），唯一鍵只容得下一列',
                    (int) $candidate['bank_id'],
                    $byBank[$bankKey]['target'],
                    $byBank[$bankKey]['third'],
                    $candidate['target'],
                    $candidate['third']
                );

                continue;
            }

            $byBank[$bankKey]['sources'][] = $source;
        }

        $byRuntime = [];

        foreach ($byBank as $bankKey => $row) {
            $byRuntime[sprintf('%d|%d|%s', $row['cash_flow_id'], $row['currency_id'], $row['target'])][] = $bankKey;
        }

        $conflicting = [];

        foreach ($byRuntime as $bankKeys) {
            if (count($bankKeys) < 2) {
                continue;
            }

            $rows = array_map(static fn (string $key): array => $byBank[$key], $bankKeys);
            $blockers[] = sprintf(
                '銀行碼「%s」在同一批裡指向不同的銀行列（%s）：runtime 查表鍵不含 bank_id、只會取最早那列，'
                . '後建的等於沒建。請分兩趟跑，讓實際出款的那張 ACF 先跑並擁有銀行身分',
                (string) $rows[0]['target'],
                implode('、', array_map(
                    static fn (array $row): string => sprintf(
                        'banks=%d「%s」（來自 acf=%d）',
                        (int) $row['bank_id'],
                        (string) $row['bank_name'],
                        (int) $row['sources'][0]['acf']
                    ),
                    $rows
                ))
            );

            foreach ($bankKeys as $key) {
                $conflicting[$key] = true;
            }
        }

        $merged = [];

        foreach ($byBank as $bankKey => $row) {
            if (!isset($conflicting[$bankKey])) {
                $merged[] = $row;
            }
        }

        return ['merged' => $merged, 'blockers' => $blockers];
    }

    /**
     * 對既有 cfb 列比對。
     *
     * runtime 只讀 `status = 1`，而唯一鍵包含停用列 ⇒「已存在」與「已生效」不是同一件事，
     * 兩種情況要分開處置：
     *
     * - 唯一鍵已被佔用：只有「服務中＋碼與三方碼全一致」才算已滿足可跳過；停用、錯碼一律擋
     *   （硬 INSERT 會撞唯一鍵，靜靜改既有列則超出本命令「只新增」的契約）
     * - 唯一鍵沒被佔用，但同一個 runtime 查表鍵已由**別的** `bank_id` 提供一致且服務中的映射：
     *   標「銀行身分待核」跳過，**不為了符合 base 優先再建一列**——建了也永遠取不到
     *
     * @param  list<array<string, mixed>>  $merged
     * @return array{targets: list<array<string, mixed>>, satisfied: list<string>, skipped: list<string>, blockers: list<string>, existing: array<string, list<object>>}
     */
    private function compareWithExisting(array $merged, bool $lock): array
    {
        $targets = [];
        $satisfied = [];
        $skipped = [];
        $blockers = [];
        $existing = [];

        foreach ($merged as $row) {
            $scopeKey = sprintf('%d|%d', $row['cash_flow_id'], $row['currency_id']);

            if (!isset($existing[$scopeKey])) {
                $existing[$scopeKey] = $this->loadExistingRows($row['cash_flow_id'], $row['currency_id'], $lock);
            }

            $rows = $existing[$scopeKey];
            $occupied = null;
            $sameCode = [];

            foreach ($rows as $candidate) {
                if ((int) $candidate->bank_id === $row['bank_id']) {
                    $occupied = $candidate;
                }

                // PHP 的 `===` 是逐位元組比對，與定位用的 BINARY 同一把尺
                if ((int) $candidate->status === 1 && (string) $candidate->bank_code === $row['target']) {
                    $sameCode[] = $candidate;
                }
            }

            if ($occupied !== null) {
                $problem = $this->describeOccupiedRow($occupied, $row);

                if ($problem === null) {
                    $satisfied[] = sprintf(
                        'cfb=%d 已提供 %s→%s（銀行 banks=%d），無事可做',
                        (int) $occupied->id,
                        $row['target'],
                        $row['third'],
                        $row['bank_id']
                    );
                } else {
                    $blockers[] = $problem;
                }

                continue;
            }

            if ($sameCode !== []) {
                $mismatched = array_values(array_filter(
                    $sameCode,
                    static fn (object $candidate): bool => (string) $candidate->provider_bank_code !== $row['third']
                ));

                if ($mismatched !== []) {
                    $blockers[] = sprintf(
                        '銀行碼「%s」已由 cfb=%d（銀行 banks=%d）提供三方碼「%s」，與本次的「%s」不同；'
                        . 'runtime 只會取最早那列，先釐清哪個才是對的',
                        $row['target'],
                        (int) $mismatched[0]->id,
                        (int) $mismatched[0]->bank_id,
                        (string) $mismatched[0]->provider_bank_code,
                        $row['third']
                    );

                    continue;
                }

                $skipped[] = sprintf(
                    '銀行碼「%s」的映射已由 cfb=%d（銀行 banks=%d）提供且一致，本次的 banks=%d 銀行身分待核；'
                    . '再建一列 runtime 也取不到，故不建',
                    $row['target'],
                    (int) $sameCode[0]->id,
                    (int) $sameCode[0]->bank_id,
                    $row['bank_id']
                );

                continue;
            }

            $targets[] = $row;
        }

        return [
            'targets' => $targets,
            'satisfied' => $satisfied,
            'skipped' => $skipped,
            'blockers' => $blockers,
            'existing' => $existing,
        ];
    }

    /**
     * 唯一鍵已被佔用時，這一列有什麼問題；`null` 表示已滿足。
     *
     * @param  array<string, mixed>  $row
     */
    private function describeOccupiedRow(object $occupied, array $row): ?string
    {
        if ((int) $occupied->status !== 1) {
            return sprintf(
                'cfb=%d（銀行 banks=%d）已存在但已停用，runtime 讀不到它；本命令只新增不改既有列，'
                . '請先確認該列是否該重新啟用',
                (int) $occupied->id,
                $row['bank_id']
            );
        }

        if ((string) $occupied->bank_code !== $row['target']) {
            return sprintf(
                'cfb=%d（銀行 banks=%d）已存在但銀行碼是「%s」、本次要的是「%s」；唯一鍵不含 bank_code，'
                . '同一列容不下兩個碼，請先釐清',
                (int) $occupied->id,
                $row['bank_id'],
                (string) $occupied->bank_code,
                $row['target']
            );
        }

        if ((string) $occupied->provider_bank_code !== $row['third']) {
            return sprintf(
                'cfb=%d（銀行 banks=%d，碼 %s）既有的三方碼「%s」與本次的「%s」三方碼不同；'
                . '改既有列請用 bank:fix-cfb-provider-code',
                (int) $occupied->id,
                $row['bank_id'],
                $row['target'],
                (string) $occupied->provider_bank_code,
                $row['third']
            );
        }

        return null;
    }

    /**
     * @return list<object>
     */
    private function loadExistingRows(int $cashFlowId, int $currencyId, bool $lock): array
    {
        // 驅動索引是 (cash_flow_id, currency_id)；整個範圍讀進來一次，避免逐列打 DB，
        // 加鎖時也順帶把這個範圍序列化住（bank_code 沒有索引，逐碼查反而更慢也更難鎖乾淨）
        $query = DB::table('cash_flow_banks')
            ->where('cash_flow_id', $cashFlowId)
            ->where('currency_id', $currencyId)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code', 'provider_bank_code', 'status'])
            ->all();
    }

    /**
     * 別名偵測：同一個 `(cash_flow_id, currency_id)` 底下，一個三方碼被兩個不同銀行碼共用。
     *
     * 比對範圍同時涵蓋本批要新增的列與**既有服務中的列**——實務上的別名多半是這樣出現的：
     * 第一趟建了 `MBBGBSG→MBB`，第二趟才輪到另一張 ACF 的 `MBBESG→MBB`。
     *
     * 這不必然是錯的（同一家銀行在兩層各有一個碼是常態），但也可能是把兩家不同銀行送進同一個
     * 三方帳戶，所以預設擋下、要放行得明示 `--allow-alias`；放行時仍逐組列出。
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, list<object>>  $existing
     * @return array{aliases: list<string>, blockers: list<string>}
     */
    private function detectAliasConflicts(array $targets, array $existing): array
    {
        $groups = [];

        foreach ($targets as $row) {
            $scopeKey = sprintf('%d|%d', $row['cash_flow_id'], $row['currency_id']);
            $groups[$scopeKey][$row['third']][$row['target']] = sprintf('本次新增（銀行 banks=%d）', $row['bank_id']);
        }

        foreach ($groups as $scopeKey => $_) {
            foreach ($existing[$scopeKey] ?? [] as $candidate) {
                if ((int) $candidate->status !== 1) {
                    continue;
                }

                $third = (string) $candidate->provider_bank_code;

                if (!isset($groups[$scopeKey][$third])) {
                    continue;
                }

                $groups[$scopeKey][$third][(string) $candidate->bank_code] ??= sprintf('既有 cfb=%d', (int) $candidate->id);
            }
        }

        $aliases = [];

        foreach ($groups as $scopeKey => $byThird) {
            [$cashFlowId, $currencyId] = array_map('intval', explode('|', $scopeKey));

            foreach ($byThird as $third => $codes) {
                if (count($codes) < 2) {
                    continue;
                }

                $detail = [];

                foreach ($codes as $code => $origin) {
                    $detail[] = sprintf('%s（%s）', $code, $origin);
                }

                $aliases[] = sprintf(
                    '金流商 %d／幣別 %d 的三方碼「%s」被 %d 個銀行碼共用：%s',
                    $cashFlowId,
                    $currencyId,
                    $third,
                    count($codes),
                    implode('、', $detail)
                );
            }
        }

        if ($aliases === [] || (bool) $this->option('allow-alias')) {
            return ['aliases' => $aliases, 'blockers' => []];
        }

        return [
            'aliases' => [],
            'blockers' => array_map(
                static fn (string $line): string => $line . '；確認這些碼確實指向同一個收款目標後，加 --allow-alias 放行',
                $aliases
            ),
        ];
    }

    /**
     * 四區報表：新增／已滿足／跳過／別名放行。未解決的那一區由 handle() 以 error 印出。
     *
     * @param  array{targets: list<array<string, mixed>>, satisfied: list<string>, skipped: list<string>, aliases: list<string>, blockers: list<string>}  $plan
     */
    private function reportPlan(array $plan): void
    {
        foreach ($plan['targets'] as $row) {
            $this->line(sprintf(
                '  [新增] 金流商 %d／幣別 %d  銀行 banks=%d「%s」（%s 層／群組 %d）  銀行碼 %s／三方碼 %s  來源 %s',
                $row['cash_flow_id'],
                $row['currency_id'],
                $row['bank_id'],
                $row['bank_name'],
                $row['layer'] === 'base' ? 'base' : '群組',
                $row['bank_group'],
                $row['target'],
                $row['third'],
                $this->describeSources($row['sources'])
            ));
        }

        foreach ($plan['satisfied'] as $line) {
            $this->line('  [已滿足] ' . $line);
        }

        foreach ($plan['skipped'] as $line) {
            $this->line('  [跳過] ' . $line);
        }

        foreach ($plan['aliases'] as $line) {
            $this->warn('  [別名放行] ' . $line);
        }
    }

    /**
     * @param  list<array{acf: int, g: int, idx: int}>  $sources
     */
    private function describeSources(array $sources): string
    {
        return implode('、', array_map(
            static fn (array $source): string => sprintf(
                'acf=%d（群組 %d，第 %d 項）',
                $source['acf'],
                $source['g'],
                $source['idx'] + 1
            ),
            $sources
        ));
    }

    /**
     * 在單一交易內鎖後重驗、全部 INSERT ＋寫審計；任一不過整批回滾。
     *
     * @param  list<int>  $acfIds
     */
    private function apply(array $acfIds, string $target, string $reason): int
    {
        $written = 0;

        DB::transaction(function () use ($acfIds, $target, $reason, &$written): void {
            $recheck = $this->classifyRows($acfIds, $target, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            $connection = (string) config('database.default');
            $stamp = Carbon::now();

            foreach ($recheck['targets'] as $row) {
                $cfbId = (int) DB::table('cash_flow_banks')->insertGetId([
                    'cash_flow_id' => $row['cash_flow_id'],
                    'currency_id' => $row['currency_id'],
                    'bank_id' => $row['bank_id'],
                    'bank_code' => $row['target'],
                    'provider_bank_code' => $row['third'],
                    // runtime 查表只讀 status=1，不靠欄位預設值——那是別處可以改的東西
                    'status' => 1,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    // 這一欄記的是「bank 所屬群組」；來源 ACF 的群組另外記在 detail.src[].g
                    'agent_group_id' => $row['bank_group'],
                    'code' => $row['target'],
                    // 單列新增沒有 survivor／loser 之分：自我參照表示「本列即標的」
                    'survivor_bank_id' => $row['bank_id'],
                    'loser_bank_id' => $row['bank_id'],
                    'action' => self::AUDIT_ACTION,
                    'detail' => $this->encodeAuditDetail([
                        't' => 'cash_flow_banks',
                        'id' => $cfbId,
                        'cf' => $row['cash_flow_id'],
                        'cur' => $row['currency_id'],
                        'bank' => $row['bank_id'],
                        'layer' => $row['layer'],
                        'third' => $row['third'],
                        'src' => $row['sources'],
                        'reason' => $reason,
                    ]),
                    'created_at' => $stamp,
                ]);

                $written++;
            }
        });

        return $written;
    }

    /**
     * 讀 ACF 的 available_banks 項目清單。
     *
     * @return array<array-key, mixed>
     */
    private function readEntries(AgentCashFlow $acf): array
    {
        $info = $acf->info;

        if (is_string($info)) {
            $info = json_decode($info, true);
        }

        if (!is_array($info)) {
            return [];
        }

        $entries = $info['available_banks'] ?? [];

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function encodeAuditDetail(array $detail): string
    {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (mb_strlen($encoded) > self::DETAIL_MAX_LENGTH) {
            throw new BankConvergenceAbortedException(
                sprintf('審計 detail 超出 %d 字元，整批中止：%s', self::DETAIL_MAX_LENGTH, $encoded)
            );
        }

        return $encoded;
    }

    /**
     * 印出本次的寫入目標連線與資料庫名。
     *
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 ACF id 區間會重疊——
     * 連錯站時預檢照樣可能全綠。呼叫點必須排在所有業務判定之前。
     */
    private function discloseWriteTarget(): void
    {
        $this->line(sprintf('寫入目標 connection：%s', (string) config('database.default')));
        $this->line(sprintf('寫入目標 database：%s', (string) DB::connection()->getDatabaseName()));
    }

    private function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');

        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->newLine();
        $this->warn(sprintf('⚠️  當前資料庫連線: %s', (string) $dbConnection));
        $this->newLine();

        return $this->confirm('確定要在此環境執行？', false);
    }
}
