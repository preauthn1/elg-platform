<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Helpers\Common;
use App\Services\CashFlow\Bank\CrossCodeConvergenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * 跨碼銀行收斂：偵測分級＋rename-bank 編排。
 *
 * 【分類】Bank — 銀行收斂軌①的編排層，收斂完成即無標的（退役條件見下方）。
 *
 * 與 dedup 家族分工：dedup 管同碼、rename-bank 管單組跨碼收斂的寫入與五道阻斷閘，
 * 本命令只負責「找組、分級、逐組驅動 rename-bank」——自身零寫入路徑。
 *
 * 為什麼不擴充 `BankActiveDedupService::execute()` 來做跨碼合併（2026-08-13 兩路審查一致否決）：
 * 同碼 dedup 的安全性建立在「碼不變」這個前提上；跨碼收斂還需要同 group cfb.bank_code 同步、
 * available_banks 舊碼改寫、五道阻斷閘、交易內鎖後重驗、survivor/loser preflight，這些 rename-bank
 * 已經具備，另建一套等於重造防呆。
 * 自動合併前必須先收斂 base(group 0)：loser 碼若在 base 仍有 active 列，`AgentBankSyncService`
 * 下次 sync 會照 base 模板重建並繼承 standard_bank_id，歧義以新 id 復現——所以這類組一律
 * flagged 不自動（regrowth 只看 base 是否 active；cfb 的引用屬「現役引用」，是另一個判準）。
 * ⚠️ rename-bank --execute 不可逆（無 rollback）；本命令 execute 前逐組先跑 rename-bank
 * dry-run，全綠才放行 execute，任何非 0 回傳＝該組降級 blocked 零寫入。
 * 人工防線四步缺一不可：dry-run 逐組核對 → 事前 SELECT 存查四張表（banks、cash_flow_banks、
 * 代付 ACF 的 available_banks、member_bank_accounts 綁定）→ 分批 --limit → 事後逐筆複驗。
 * dry-run 報表若揭露大量「同會員綁 survivor 與 loser 且 account_no 不同」的真雙帳號，
 * 那不是機械分流能處理的，量大時人工暫停不要硬跑。
 *
 * ## 彙總呼叫（單次 --from，逗號分隔）
 *
 * 每組對 rename-bank 的探測／執行都是**單次彙總呼叫**：
 * `'--from' => implode(',', $loser_ids)`（RenameBankCommand::parseLoserIds() 原生支援逗號分隔）。
 * 禁止逐 loser 各自呼叫一次——那會破壞 RenameBankCommand 對 --from 清單「先彙總再分流」的
 * 不變式（鍵位分配順序相依於 --from 順序、碼族閘被逐次削弱、同碼混合組守門可被繞過）。
 *
 * ## 退役條件
 *
 * 與 `bank:rename-bank` 同進退（本命令自身零寫入、只負責找組分級並驅動那支）：
 * 各站跨碼組偵測全數歸零、且 `cash-flow:sync` 不再建出並存的新碼列之後即可一併刪除。
 */
class ConvergeCrossCodeCommand extends Command
{
    protected $signature = 'bank:converge-cross-code
                            {--agent-group= : 限定 agent_group_id（逗號分隔；預設整庫，--execute 時必填）}
                            {--execute : 對 auto 組實際驅動 rename-bank（預設 dry-run）}
                            {--force : 跳過執行前確認}
                            {--limit= : 本輪最多處理的 auto 組數（分批）}';

    protected $description = '跨碼銀行收斂：standard_bank_id 分組偵測分級，auto 組逐組驅動 bank:rename-bank（預設 dry-run）';

    public function handle(CrossCodeConvergenceService $service): int
    {
        $agentGroupIds = $this->resolveAgentGroupIds();
        if ($agentGroupIds === false) {
            return self::FAILURE;
        }

        // I-5：--execute 屬不可逆批次寫入，禁止整庫一次全量執行；逐站 rollout 才安全。
        if ($this->option('execute') && $agentGroupIds === null) {
            $this->error('--execute 模式必須指定 --agent-group（逐站 rollout，禁止整庫一次全量執行）；請加 --agent-group=<id[,id...]> 縮小範圍。');

            return self::FAILURE;
        }

        $groups = $service->detectCrossCodeGroups($agentGroupIds);
        $this->info(sprintf('跨碼組：%d 組', $groups->count()));

        if ($groups->isEmpty()) {
            $this->info('0 組，無需收斂。');

            return self::SUCCESS;
        }

        $classified = $groups->map(fn (array $group): array => $group + ['result' => $service->classify($group['rows'])]);
        $auto = $classified->filter(fn (array $group): bool => $group['result']['tier'] === 'auto')->values();
        $flagged = $classified->filter(fn (array $group): bool => $group['result']['tier'] === 'flagged')->values();

        $this->renderSummary($auto, $flagged);

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $batch = $limit !== null ? $auto->take($limit) : $auto;

        if ($this->option('execute')) {
            if (!$this->option('force') && !$this->confirm(sprintf(
                '即將對 %d 組 auto 逐組驅動 bank:rename-bank --execute（不可逆，無 rollback），繼續？',
                $batch->count()
            ))) {
                $this->info('已取消。');

                return self::SUCCESS;
            }

            $stats = ['converged' => 0, 'blocked' => 0];

            // I-6：每組獨立 try/catch，單組例外不中止整批；exit code 統一在迴圈外依
            // blocked 數決定（I-4）。
            foreach ($batch as $group) {
                try {
                    $this->convergeGroup($group) ? $stats['converged']++ : $stats['blocked']++;
                } catch (Throwable $e) {
                    $stats['blocked']++;
                    $this->error(sprintf(
                        '  組 g%d/std%d：例外中止（%s），計入 blocked，續處理下一組',
                        $group['agent_group_id'], $group['standard_bank_id'], $e->getMessage()
                    ));
                }
            }

            $this->info(sprintf(
                '完成：converged=%d、blocked=%d、flagged=%d（見上表，人工裁決後逐組 bank:rename-bank --from --to）',
                $stats['converged'], $stats['blocked'], $flagged->count()
            ));

            // I-4：execute 模式下有 blocked 即回報 FAILURE，讓上游排程/CI 能感知未完全收斂。
            return $stats['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $probeStats = ['probe_ok' => 0, 'probe_blocked' => 0];

        foreach ($batch as $group) {
            $this->probeGroup($group) ? $probeStats['probe_ok']++ : $probeStats['probe_blocked']++;
        }

        // I-3：dry-run 結尾印探測統計，作為稽核軌跡與 exit code 判斷依據。
        $this->info(sprintf(
            'probe_ok=%d、probe_blocked=%d、flagged=%d',
            $probeStats['probe_ok'], $probeStats['probe_blocked'], $flagged->count()
        ));
        $this->line('  [dry-run] 未寫入');

        // I-4：dry-run 探測有 blocked 同樣回報 FAILURE，與既有全綠案例（無 blocked）不受影響。
        return $probeStats['probe_blocked'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<int>|null|false */
    private function resolveAgentGroupIds(): array|null|false
    {
        $raw = $this->option('agent-group');

        // 未給（null）＝整庫掃描；給了但空字串（''）語意不同——視為誤用一律 fail-closed。
        if ($raw === null) {
            return null;
        }

        if ($raw === '') {
            $this->error('--agent-group 有給但為空字串，與「未給」（整庫掃描）語意不同；請移除該選項或提供有效值。');

            return false;
        }

        $ids = [];
        foreach (explode(',', (string) $raw) as $piece) {
            $piece = trim($piece);
            // fail-closed：非數字不得靜默轉型（對齊 RenameBankCommand 慣例）
            if ($piece === '' || !ctype_digit($piece) || (int) $piece <= 0) {
                $this->error(sprintf('--agent-group 含無效值：「%s」', $piece));

                return false;
            }
            $ids[] = (int) $piece;
        }

        return $ids;
    }

    private function renderSummary(Collection $auto, Collection $flagged): void
    {
        $this->table(
            ['tier', 'group', 'standard', 'survivor', 'losers', 'amount_diff', 'reasons'],
            $auto->concat($flagged)->map(fn (array $group): array => [
                $group['result']['tier'] === 'auto' ? 'auto' : 'flagged',
                $group['agent_group_id'],
                $group['standard_bank_id'],
                $group['result']['survivor_id'] ?? '-',
                implode(',', $group['result']['loser_ids']),
                $this->amountDiffColumn($group['rows']),
                implode(',', $group['result']['reasons']),
            ])->all()
        );
    }

    /**
     * I-2：組內 min_amount／max_amount 限額揭露。人工裁決前若不揭露，容易誤把限額
     * 不等價的組當成單純換碼一鍵放行。相等留 `-`，不等則列出 `min:a/b`／`max:a/b`。
     */
    private function amountDiffColumn(Collection $rows): string
    {
        $mins = $rows->pluck('min_amount');
        $maxs = $rows->pluck('max_amount');

        $parts = [];
        if ($this->amountDiverges($mins)) {
            $parts[] = 'min:' . $mins->implode('/');
        }
        if ($this->amountDiverges($maxs)) {
            $parts[] = 'max:' . $maxs->implode('/');
        }

        return $parts === [] ? '-' : implode(' ', $parts);
    }

    /** decimal 相等比較一律走 Common::numCompare，禁止裸字串/數值直比。 */
    private function amountDiverges(Collection $amounts): bool
    {
        $first = (string) $amounts->first();

        return $amounts->contains(fn ($amount): bool => Common::numCompare((string) $amount, $first, 4) !== 0);
    }

    /** dry-run：單次彙總呼叫 rename-bank（--from 逗號分隔全部 loser），非 0 回傳即 blocked。 */
    private function probeGroup(array $group): bool
    {
        $survivorId = (int) $group['result']['survivor_id'];
        $loserIds = $group['result']['loser_ids'];

        $exit = Artisan::call('bank:rename-bank', [
            '--agent-group' => (string) $group['agent_group_id'],
            '--from' => implode(',', $loserIds),
            '--to' => (string) $survivorId,
        ], $this->output);

        if ($exit !== self::SUCCESS) {
            $this->warn(sprintf(
                '  組 g%d/std%d：rename-bank 探測 %s→%d 阻斷（exit=%d），該組轉 blocked',
                $group['agent_group_id'], $group['standard_bank_id'], implode(',', $loserIds), $survivorId, $exit
            ));

            return false;
        }

        return true;
    }

    /**
     * 收斂單一 auto 組：單次彙總 dry-run 探測全綠 → 單次彙總 execute。
     *
     * 彙總呼叫的理由（C-1）：--from 一次帶齊全部 loser id（逗號分隔），對齊
     * RenameBankCommand::parseLoserIds（:1190-1205）與同檔 293-296「先彙總再分流」
     * 不變式——逐 loser 個別呼叫會讓鍵位分配順序相依於 --from 順序，且碼族閘/同碼
     * 混合組守門會被逐次削弱。先探測後執行則把「部分收斂」窗口壓到探測與執行之間
     * 的併發變動（rename-bank 交易內鎖重驗仍會攔，僅結果變 blocked）。
     */
    private function convergeGroup(array $group): bool
    {
        $survivorId = (int) $group['result']['survivor_id'];
        $loserIds = $group['result']['loser_ids'];
        $pair = [
            '--agent-group' => (string) $group['agent_group_id'],
            '--from' => implode(',', $loserIds),
            '--to' => (string) $survivorId,
        ];

        if (Artisan::call('bank:rename-bank', $pair, $this->output) !== self::SUCCESS) {
            $this->warn(sprintf('  組 g%d/std%d：探測阻斷，整組跳過零寫入', $group['agent_group_id'], $group['standard_bank_id']));

            return false;
        }

        $exit = Artisan::call('bank:rename-bank', $pair + ['--execute' => true, '--force' => true], $this->output);

        if ($exit !== self::SUCCESS) {
            $this->error(sprintf(
                '  組 g%d/std%d：execute 阻斷（exit=%d），未寫入——本組轉人工',
                $group['agent_group_id'], $group['standard_bank_id'], $exit
            ));

            return false;
        }

        // I-6：成功收斂即印稽核軌跡（stdout），供事後對照本輪動了哪些組。
        $this->info(sprintf(
            'g%d/std%d converged:survivor %d ← losers %s',
            $group['agent_group_id'], $group['standard_bank_id'], $survivorId, implode(',', $loserIds)
        ));

        return true;
    }
}
