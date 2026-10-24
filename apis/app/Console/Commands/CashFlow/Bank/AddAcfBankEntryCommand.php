<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 在代付 ACF 的 `available_banks` 追加一個缺少的項目（銀行碼 ＋ 三方碼）。
 *
 * 【分類】Bank — ⚠️ 追加的項目不會自動持久，過渡期工具（退役條件見下方）。
 *
 * ## 這支補的是「改」與「刪」之外的第三種洞
 *
 * 同家族既有兩支都以「項目已存在」為前提：`bank:fix-acf-bank-entry` 改既有項目的其中一個
 * 欄位、`bank:remove-acf-bank-entry` 刪掉整個項目。但最常見的斷付形態其實是**項目根本不
 * 存在**——該群組有服務中的銀行列、會員也綁得上去，通道的 `available_banks` 卻沒有對應
 * 條目，`convertPayoutBankCodeFromJson()` 查不到就回空字串，訂單卡在「不支援銀行」。
 *
 * 實例（VWorld 代理群組 70）：`ICBKSG`（ICBC）有 5 筆會員綁定且銀行列服務中，GMPay 官方
 * 清單沒有 ICBC 所以該通道確實無解，但 MaxPay 官方 v1.9 清單有 `ICBC`，其 ACF 卻沒有這個
 * 項目 ⇒ 這 5 人在有可用通道的情況下仍然出不了款。
 *
 * ## 與 `cash-flow:backfill-wintoto-payout-bankcodes` 的關係
 *
 * 那支是同型的追加式修補，但目標與條目硬編在常數裡（Wintoto 馬幣專用），且要求三方碼能從
 * 該 ACF 的既有清單「坐實」。本命令把它一般化：目標由參數指定，三方碼的正確性改由呼叫者
 * 以外部權威（該金流商自己的 api.md／官方清單）判定並寫進 `--reason`。
 *
 * 🔴 **三方碼的對錯本命令無從驗證，只驗結構**。同一家銀行在不同金流商的碼可以完全不同，
 * 且寫法可能相反——MaxPay（PayLoh）用全名 `Bank Of China`／`Liquid Pay`，GMPay 用短名
 * `BOC`／`LiquidPay`，兩者逐項對應。**下 `--third` 前必須回該金流商的 api.md 核對**，
 * 不可拿另一家的碼套用，也不可拿站內其他通道的值互比。
 *
 * ## 預檢（任一不過即整批零寫入）
 *
 * 1. ACF 存在，且其 `cash_flows.type` 為代付——代收 ACF 沒有 `available_banks` 語意。
 * 2. `--target` 在該 ACF 的代理群組＋幣別有**服務中**的銀行列。沒有就代表會員根本選不到它，
 *    補了條目也沒有用，等於把問題換個位置（判準與 `bank:fix-acf-bank-entry` 一致）。
 * 3. `--target` 尚未出現在該份 JSON。已存在代表這是「改碼」而非「補項」，該用 fix 那支；
 *    硬追加會製造同碼重複，而舊路徑 foreach 取第一個匹配，後加的那筆永遠不會生效。
 * 4. `--third` 尚未出現在該份 JSON。同一個三方碼掛兩個項目，會讓其中一家銀行實質缺席
 *    （這正是 fix 那支要修的形態，不該由本命令製造出來）。
 *
 * ### 第 4 條會擋掉「同一家銀行換新碼」的情境 ⇒ 該用 fix 那支
 *
 * 銀行收斂期間最常見的需求是「這家銀行改用新碼了，把新碼補進通道清單」。那會撞上第 4 條：
 * 舊碼的項目還在，三方碼是同一個，於是本命令一律阻斷。**這是對的**——同一家銀行不該在
 * 同一份清單裡有兩個項目。
 *
 * ⇒ 那個情境要用 `bank:fix-acf-bank-entry --entry-third=<三方碼> --from=<舊碼> --to=<新碼>`
 * 就地換碼，不是用本命令追加。
 *
 * 實例（2026-09-10 VWorld 代理群組 70）：`UOBSG` 收斂到 `SGD0001`，兩者的三方碼都是 `UOB`。
 * 用本命令補 `SGD0001` 被第 4 條擋下（零寫入），改用 fix 就地把項目的 `BankCode_ELG`
 * 從 `UOBSG` 換成 `SGD0001`、三方碼不動，一次到位。
 *
 * ## 預設不寫入
 *
 * 與 bank 家族一致：不帶 `--execute` 只輸出預檢報表。改的是生產 ACF 的 JSON、無 rollback
 * 命令；還原方式是依審計 `available_banks_align_logs`（`action=manual_entry_add`、
 * `new_code` 為追加的銀行碼、`detail.third` 為三方碼）自 `available_banks` 移除該項目。
 *
 * php artisan bank:add-acf-bank-entry --acf=319 --target=ICBKSG --third=ICBC --reason='...'
 * php artisan bank:add-acf-bank-entry --acf=319 --target=ICBKSG --third=ICBC --reason='...' --execute --force
 *
 * ## 退役條件
 *
 * ⚠️ **沖掉追加項目的不是 `cash-flow:sync`**（該命令對 `available_banks` 零引用），
 * 而是 `agent:add-agent-cash-flow`：它把 `available_banks` 依「該渠道 `status=1` 的
 * `cash_flow_banks` 列」整份重算（`AddAgentCashFlow.php:1711` 的 `buildAvailableBanks()`
 * 只 map 那批 cfb 列），**沒有對應 active cfb 列的項目會被丟掉**。
 * ⇒ 要讓本命令補的項目真正持久，應同時用 `bank:add-cfb-rows` 補上對應的 cfb 列。
 *
 * 真正的退役條件＝代付查表灰度全面開閘、`convertPayoutBankCodeFromJson()` 退場後，
 * `available_banks` 不再是代付碼的權威，補 JSON 項目就沒有意義。
 */
class AddAcfBankEntryCommand extends Command
{
    protected $signature = 'bank:add-acf-bank-entry
                            {--acf=* : 要追加項目的 agent_cash_flows.id（可指定多個）}
                            {--target= : 要追加的 BankCode_ELG（須在該群組有服務中的銀行列）}
                            {--third= : 要追加的 BankCode_Third（正確性以該金流商 api.md 為準，本命令不驗）}
                            {--reason= : 追加理由與依據，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '在代付 ACF 的 available_banks 追加缺少的銀行項目（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** `available_banks_align_logs.new_code` 是 varchar(16)，超出會被 MySQL 截斷或報錯 */
    private const CODE_MAX_LENGTH = 16;

    public function handle(): int
    {
        $rawAcfIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('acf'));
        $invalid = array_values(array_filter(
            $rawAcfIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalid !== []) {
            $this->error(sprintf(
                '--acf 必須是純數字的 agent_cash_flows.id，收到「%s」。多個請重複旗標（--acf=57 --acf=58），不可用逗號分隔。',
                implode('」「', $invalid)
            ));

            return self::FAILURE;
        }

        $acfIds = array_values(array_unique(array_map('intval', $rawAcfIds)));
        $target = trim((string) $this->option('target'));
        $third = trim((string) $this->option('third'));
        $reason = trim((string) $this->option('reason'));

        $invalidOptions = $this->validateOptions($acfIds, $target, $third, $reason);

        if ($invalidOptions !== null) {
            $this->error($invalidOptions);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($acfIds, $target, $third);

        $this->reportPlan($targets, $target, $third);

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要追加的項目。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 個項目可追加，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($acfIds, $target, $third, $reason);

        $this->info(sprintf(
            '追加完成：%d 個項目（審計見 available_banks_align_logs，action=manual_entry_add）。',
            $written
        ));

        return self::SUCCESS;
    }

    /**
     * 參數層驗證：兩個碼齊全、長度寫得進審計、reason 有依據。
     *
     * @param  list<int>  $acfIds
     */
    private function validateOptions(array $acfIds, string $target, string $third, string $reason): ?string
    {
        if ($acfIds === [] || $target === '' || $third === '') {
            return '--acf、--target、--third 皆為必填：追加項目需要銀行碼與三方碼兩者。';
        }

        if (mb_strlen($target) > self::CODE_MAX_LENGTH) {
            return sprintf('--target 超過 %d 字元，審計的 new_code 欄位放不下。', self::CODE_MAX_LENGTH);
        }

        if ($reason === '') {
            return '--reason 必填：追加依據（該金流商 api.md 的哪份清單）會寫入審計，供日後追溯與還原。';
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入不可逆——寧可先擋下。';
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH);
        }

        return null;
    }

    /**
     * 逐 ACF 分流成「可追加／不合格」兩類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間的窗口是
     * 分鐘級，期間代理後台或對齊命令都可能改動同一份 JSON。
     *
     * @param  list<int>  $acfIds
     * @return array{targets: list<array{acf: AgentCashFlow, index: int}>, blockers: list<string>}
     */
    private function classifyRows(array $acfIds, string $target, string $third, bool $lock = false): array
    {
        $query = AgentCashFlow::query()->whereIn('id', $acfIds);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get()->keyBy('id');
        $payoutCashFlowIds = CashFlow::query()
            ->whereIn('id', $rows->pluck('cash_flow_id')->unique()->all())
            ->where('type', 3)
            ->pluck('id')
            ->all();

        $targets = [];
        $blockers = [];

        foreach ($acfIds as $id) {
            $acf = $rows->get($id);

            if ($acf === null) {
                $blockers[] = sprintf('acf=%d 不存在', $id);

                continue;
            }

            // available_banks 是代付通道的語意，代收 ACF 追加了也沒有任何路徑會讀它
            if (!in_array((int) $acf->cash_flow_id, array_map('intval', $payoutCashFlowIds), true)) {
                $blockers[] = sprintf(
                    'acf=%d 的金流商 %d 不是代付渠道（cash_flows.type≠3），available_banks 對它沒有語意',
                    $id,
                    (int) $acf->cash_flow_id
                );

                continue;
            }

            // 該碼在該群組沒有服務中的列 ⇒ 會員根本選不到，補了條目也不會有訂單走到它
            $hasActiveRow = Bank::query()
                ->where('agent_group_id', (int) $acf->agent_group_id)
                ->where('currency_id', (int) $acf->currency_id)
                ->where('code', $target)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->exists();

            if (!$hasActiveRow) {
                $blockers[] = sprintf(
                    'acf=%d 的代理群組 %d／幣別 %d 沒有服務中的「%s」銀行列，補了會員仍選不到',
                    $id,
                    (int) $acf->agent_group_id,
                    (int) $acf->currency_id,
                    $target
                );

                continue;
            }

            $entries = $this->readEntries($acf);
            $duplicate = null;

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                // 已有同銀行碼 ⇒ 這是改碼不是補項，該用 bank:fix-acf-bank-entry
                if ((string) ($entry['BankCode_ELG'] ?? '') === $target) {
                    $duplicate = sprintf(
                        'acf=%d 已有「%s」的項目（三方碼 %s）；那是改碼而非補項，請改用 bank:fix-acf-bank-entry，'
                        . '硬追加會製造同碼重複而後加的那筆永遠不會生效',
                        $id,
                        $target,
                        (string) ($entry['BankCode_Third'] ?? '')
                    );

                    break;
                }

                // 已有同三方碼 ⇒ 追加後兩個項目共用一個三方碼，會讓其中一家銀行實質缺席
                if ((string) ($entry['BankCode_Third'] ?? '') === $third) {
                    $duplicate = sprintf(
                        'acf=%d 的三方碼「%s」已掛在「%s」上；同一個三方碼掛兩個項目會讓其中一家銀行實質缺席，請先釐清',
                        $id,
                        $third,
                        (string) ($entry['BankCode_ELG'] ?? '')
                    );

                    break;
                }
            }

            if ($duplicate !== null) {
                $blockers[] = $duplicate;

                continue;
            }

            $targets[] = ['acf' => $acf, 'index' => count($entries)];
        }

        return ['targets' => $targets, 'blockers' => $blockers];
    }

    /**
     * @param  list<array{acf: AgentCashFlow, index: int}>  $targets
     */
    private function reportPlan(array $targets, string $target, string $third): void
    {
        foreach ($targets as $target) {
            $acf = $target['acf'];
            $this->line(sprintf(
                '  [追加] acf=%d  金流商 %d／代理群組 %d  新增為第 %d 個項目  銀行碼 %s／三方碼 %s',
                (int) $acf->id,
                (int) $acf->cash_flow_id,
                (int) $acf->agent_group_id,
                $target['index'] + 1,
                $target,
                $third
            ));
        }
    }

    /**
     * @param  list<int>  $acfIds
     */
    private function apply(array $acfIds, string $target, string $third, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $written = 0;

        DB::transaction(function () use ($acfIds, $target, $third, $reason, $connection, $stamp, &$written): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間他人可能改動同一份 JSON。
            // 判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉 TOCTOU 窗口。
            $recheck = $this->classifyRows($acfIds, $target, $third, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $target) {
                $acf = $target['acf'];

                $info = $this->readInfo($acf);
                $entries = array_values($info['available_banks'] ?? []);

                // 追加在最後：既有項目的索引不變，舊路徑 foreach 的既有命中順序也不受影響
                $entries[] = ['BankCode_ELG' => $target, 'BankCode_Third' => $third];
                $info['available_banks'] = $entries;

                $acf->update(['info' => $info]);
                $written++;

                DB::table('available_banks_align_logs')->insert([
                    'connection' => $connection,
                    'agent_cash_flow_id' => (int) $acf->id,
                    'cash_flow_id' => (int) $acf->cash_flow_id,
                    'agent_group_id' => (int) $acf->agent_group_id,
                    'currency_id' => (int) $acf->currency_id,
                    // 追加沒有舊值；欄位 NOT NULL 故寫空字串，語意由 action 表達
                    'old_code' => '',
                    'new_code' => $target,
                    'action' => 'manual_entry_add',
                    'detail' => $this->encodeAuditDetail([
                        'third' => $third,
                        'idx' => $target['index'],
                        'reason' => $reason,
                    ]),
                    'created_at' => $stamp,
                ]);
            }
        });

        return $written;
    }

    /**
     * 讀 ACF 的 available_banks 項目清單。
     *
     * @return array<int, mixed>
     */
    private function readEntries(AgentCashFlow $acf): array
    {
        return $this->readInfo($acf)['available_banks'] ?? [];
    }

    /**
     * 讀 ACF info；容忍 double-encoded（info 讀回 string 的 legacy 情況）。
     *
     * @return array<string, mixed>
     */
    private function readInfo(AgentCashFlow $acf): array
    {
        $info = $acf->info;

        if (is_string($info)) {
            $info = json_decode($info, true);
        }

        return is_array($info) ? $info : [];
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 ACF id 區間重疊——
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

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));

        return $this->confirm('確定要在此環境執行？', false);
    }
}
