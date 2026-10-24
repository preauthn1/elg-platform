<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Models\Bank;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 把指定 banks 列的 `code` 改成另一個碼（單列改碼）。
 *
 * 【分類】Bank — ⚠️ 存量修正工具，過渡期；方向是「sync 依賴」不是非 sync-safe（退役條件見下方）。
 *
 * ## 為什麼既有的收斂命令做不到
 *
 * `bank:rename-bank` 是「同一代理群組內同行不同碼收斂」，需要 from／to 兩列；
 * `bank:merge-base-synonym-banks` 同樣是兩列合併，且要求被併掉的那列已停用。
 * 本命令處理的是**只有一列**的情況：該碼在目標層根本沒有對應列，沒有第二列可併。
 *
 * 實例（Alfawin77）：重複清理批次在 base 層與代理群組層挑了相反的存活碼——群組層
 * 留站別碼、軟刪標準碼，base 層卻留了標準碼。於是同一家銀行在兩層碼不同，而
 * `cash-flow:sync` 是「以三方配置的銀行名稱模糊匹配 base 層、取該列的碼」建
 * `cash_flow_banks`，寫進去的碼因此與會員實際在用的群組層碼對不上，代付查表落空。
 *
 * ## 撞鍵判定只看服務中的列
 *
 * `banks` 的 active 唯一鍵是 generated column，只對 `status=1` 的列產生值
 * （停用列為 NULL、不受約束）。故目標碼若只存在於停用列並不構成衝突，不該誤擋；
 * 判定範圍是「同代理群組＋同類型＋同幣別」，別的群組用同一個碼不衝突。
 *
 * ## 連帶改寫 cash_flow_banks.bank_code
 *
 * 代付查表以 `(cash_flow_id, currency_id, bank_code)` 為鍵、不 join banks，所以 banks 改了碼
 * 而 cash_flow_banks 沒跟上，該金流商對這家銀行的代付查表就落空。與 `bank:rename-bank`、
 * `bank:fix-mismatch` 同一把尺：本命令改完 `banks.code`，**同一交易內**把引用本列
 * （`bank_id` 相同）且 `bank_code` **逐字等於原碼**的 cash_flow_banks 列改成新碼。
 *
 * `bank_code` 已經不是原碼的列一律保留、只在報表與審計揭露：那些列是別的工具刻意寫的
 * （例如 `bank:add-cfb-rows` 補的群組層碼），不是本次改碼造成的脫節，順手改掉會把人家的
 * 修正蓋掉。比對在 PHP 端逐列做、不用 SQL 的 `WHERE bank_code = ?`：欄位 collation 不分
 * 大小寫，`MayBank` 與 `MAYBANK` 是不同的碼。
 *
 * 不動 ACF 的 `available_banks` JSON。那份是舊查碼路徑的來源，與查表路徑是否一致由
 * `cash-flow:verify-payout-bank-parity` 驗。
 *
 * ⚠️ 這是為存量修正寫的工具，用完應依過渡期代碼清單的紀律退役。無 rollback 命令，
 * 還原靠 `bank_dedup_logs`（`action=recode_bank`）的 detail 手工回寫：`aft`／`was` 是
 * banks 的碼，`cfb_synced` 是被連帶改寫的 cash_flow_banks id，還原時這些列要一起改回原碼。
 *
 * ## 退役條件
 *
 * 標的是「同一家銀行在 base 層與群組層碼不同」的存量。當那批清完、且 `cash-flow:sync`
 * 不再以銀行名稱模糊比對取 base 層的碼之後即無標的。
 */
class RecodeBankCommand extends Command
{
    use PreservesRowTimestamps;

    protected $signature = 'bank:recode-bank
                            {--pair=* : 改碼配對，格式 <banks.id>:<新 code>（可指定多個）}
                            {--reason= : 改碼理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '把指定 banks 列的 code 改成另一個碼（任一配對不合格則整批零寫入）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    public function handle(): int
    {
        $rawPairs = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('pair'));

        if ($rawPairs === []) {
            $this->error('--pair 必填：格式 <banks.id>:<新 code>，可重複指定（--pair=663:TPID011 --pair=657:TPID015）。');

            return self::FAILURE;
        }

        $pairs = [];
        $malformed = [];

        foreach ($rawPairs as $raw) {
            if (preg_match('/^(\d+):([A-Za-z0-9_\-]+)$/', $raw, $m) !== 1) {
                $malformed[] = $raw;

                continue;
            }

            $pairs[] = ['id' => (int) $m[1], 'code' => $m[2]];
        }

        if ($malformed !== []) {
            $this->error(sprintf(
                '--pair 格式須為 <banks.id>:<新 code>，收到「%s」。',
                implode('」「', $malformed)
            ));

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason 必填：改碼理由會寫入審計，供日後追溯與還原。');

            return self::FAILURE;
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            $this->error('--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入不可逆——寧可先擋下。');

            return self::FAILURE;
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $this->error(sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH));

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($pairs);

        $this->reportPlan($targets);

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要改碼的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可改碼，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($pairs, $reason);

        $this->info(sprintf('改碼完成：%d 列（審計見 bank_dedup_logs，action=recode_bank）。', $written));

        return self::SUCCESS;
    }

    /**
     * 逐配對分流成「可改碼／不合格」兩類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間的
     * 窗口是分鐘級，期間他人可能建立同碼的服務中列而讓改碼撞上唯一鍵。
     *
     * @param  list<array{id: int, code: string}>  $pairs
     * @return array{targets: list<array{bank: Bank, code: string, cfb_synced: list<int>, cfb_kept: list<int>}>, blockers: list<string>}
     */
    private function classifyRows(array $pairs, bool $lock = false): array
    {
        $ids = array_map(static fn (array $p): int => $p['id'], $pairs);

        $query = Bank::query()->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        $banks = $query->get()->keyBy('id');

        $targets = [];
        $blockers = [];

        foreach ($pairs as $pair) {
            $bank = $banks->get($pair['id']);

            if ($bank === null) {
                $blockers[] = sprintf('id=%d 不存在', $pair['id']);

                continue;
            }

            if ((string) $bank->code === $pair['code']) {
                $blockers[] = sprintf('id=%d 現有碼已是「%s」，沒有可執行的變更', $pair['id'], $pair['code']);

                continue;
            }

            // 撞鍵判定對齊 active 唯一鍵的口徑：同代理群組＋同類型＋同幣別，且只看服務中的列
            $collision = Bank::query()
                ->where('agent_group_id', (int) $bank->agent_group_id)
                ->where('type', (int) $bank->type)
                ->where('currency_id', (int) $bank->currency_id)
                ->where('code', $pair['code'])
                ->where('status', 1)
                ->where('id', '!=', (int) $bank->id)
                ->first(['id', 'name']);

            if ($collision !== null) {
                $blockers[] = sprintf(
                    'id=%d 改碼為「%s」會撞上服務中的 id=%d「%s」（同代理群組＋類型＋幣別），違反 active 唯一鍵',
                    $pair['id'],
                    $pair['code'],
                    (int) $collision->id,
                    (string) $collision->name
                );

                continue;
            }

            ['synced' => $cfbSynced, 'kept' => $cfbKept] = $this->classifyCashFlowBankRows($bank, $lock);

            $targets[] = ['bank' => $bank, 'code' => $pair['code'], 'cfb_synced' => $cfbSynced, 'cfb_kept' => $cfbKept];
        }

        return ['targets' => $targets, 'blockers' => $blockers];
    }

    /**
     * 引用本列的 cash_flow_banks 分成「會連帶改寫」與「保留」兩類。
     *
     * 判準是 bank_code 逐字等於本列現碼；在 PHP 端比對，SQL 的 `=` 走不分大小寫的 collation。
     *
     * @return array{synced: list<int>, kept: list<int>}
     */
    private function classifyCashFlowBankRows(Bank $bank, bool $lock): array
    {
        $query = DB::table('cash_flow_banks')->where('bank_id', (int) $bank->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $synced = [];
        $kept = [];

        foreach ($query->get(['id', 'bank_code']) as $row) {
            if ((string) $row->bank_code === (string) $bank->code) {
                $synced[] = (int) $row->id;

                continue;
            }

            $kept[] = (int) $row->id;
        }

        return ['synced' => $synced, 'kept' => $kept];
    }

    /**
     * @param  list<array{bank: Bank, code: string, cfb_synced: list<int>, cfb_kept: list<int>}>  $targets
     */
    private function reportPlan(array $targets): void
    {
        foreach ($targets as $target) {
            $bank = $target['bank'];
            $this->line(sprintf(
                '  [改碼] id=%d「%s」代理群組 %d  %s → %s',
                (int) $bank->id,
                (string) $bank->name,
                (int) $bank->agent_group_id,
                (string) $bank->code,
                $target['code']
            ));

            if ($target['cfb_synced'] !== []) {
                $this->line(sprintf(
                    '         連帶改寫 %d 筆 cash_flow_banks 的 bank_code（id：%s）',
                    count($target['cfb_synced']),
                    implode(', ', $target['cfb_synced'])
                ));
            }

            if ($target['cfb_kept'] !== []) {
                $this->warn(sprintf(
                    '         ⚠️  另有 %d 筆 cash_flow_banks 引用本列但 bank_code 不是「%s」，保留不動（id：%s）；那是別的工具刻意寫的碼，要改請另行處理',
                    count($target['cfb_kept']),
                    (string) $bank->code,
                    implode(', ', $target['cfb_kept'])
                ));
            }
        }
    }

    /**
     * @param  list<array{id: int, code: string}>  $pairs
     */
    private function apply(array $pairs, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $run = 'recode_' . $stamp->format('YmdHis');
        $written = 0;

        DB::transaction(function () use ($pairs, $reason, $connection, $stamp, $run, &$written): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間他人可能建立同碼的服務中列。
            // 判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉 TOCTOU 窗口。
            $recheck = $this->classifyRows($pairs, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $target) {
                $bank = $target['bank'];
                $oldCode = (string) $bank->code;

                DB::table('banks')->where('id', (int) $bank->id)->update([
                    'code' => $target['code'],
                    'updated_at' => $stamp,
                ]);

                $written++;

                // 查表鍵是 bank_code，不跟著改代付就查不到這家銀行；只改逐字等於原碼的列（分類見 classifyCashFlowBankRows）
                if ($target['cfb_synced'] !== []) {
                    DB::table('cash_flow_banks')
                        ->whereIn('id', $target['cfb_synced'])
                        ->update($this->preservingUpdatedAt(['bank_code' => $target['code']]));
                }

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => (int) $bank->agent_group_id,
                    // code 欄記原碼：反查「這個碼後來變成什麼」時以它為索引
                    'code' => $oldCode,
                    // 單列改碼沒有 survivor／loser 之分：自我參照表示「本列即標的」
                    'survivor_bank_id' => (int) $bank->id,
                    'loser_bank_id' => (int) $bank->id,
                    'action' => 'recode_bank',
                    'detail' => $this->encodeAuditDetail([
                        'run' => $run,
                        't' => 'banks',
                        'id' => (int) $bank->id,
                        'was' => ['code' => $oldCode],
                        'aft' => ['code' => $target['code']],
                        'cfb_synced' => $target['cfb_synced'],
                        'cfb_kept' => $target['cfb_kept'],
                        'reason' => $reason,
                    ]),
                    'created_at' => $stamp,
                ]);
            }
        });

        return $written;
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 `banks.id`
     * 區間重疊——連錯站時預檢照樣可能全綠。呼叫點必須排在所有業務判定之前：預檢
     * 阻斷才是最需要核對環境的時刻，「撞鍵」既可能是真的，也可能只是連錯站。
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
