<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\BankConvergenceAbortedException;
use App\Models\Bank;
use App\Models\WithdrawalOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 停用「上游 `banks` 列已停用、自己卻還 `status=1`」的孤兒 `cash_flow_banks` 列。
 *
 * 【分類】Bank — 過渡期工具，根因是四層鏈缺 cascade（退役條件見下方）。
 *
 * ## 這是四層鏈通病在 cfb 這一層的表現
 *
 * `check:cashflow-orphans` 的 docblock 已記下同一個病灶：這條鏈上沒有 FK cascade、也沒有
 * 「上游停用即連動停用下游」的 Observer，所以停用或收斂一列銀行之後，掛在它底下的下游列
 * 會留著 `status=1`。那支處理的是 `agent_cash_flow_order_types`；`cash_flow_banks` 有同樣
 * 的問題卻一直沒有對應工具。
 *
 * 為什麼要清：代付查表以 `(cash_flow_id, currency_id, bank_code)` 為鍵、**不 join banks**，
 * 所以孤兒列仍查得到。多數情況下無人綁得到那個碼因而無害，但只要有人重建同名碼的銀行列
 * 並啟用，這些列就會帶著可能過期的送單參數復活。
 *
 * ## 場景
 *
 * `bank:rename-bank` 遇到撞唯一鍵時會把 loser 的 cfb 列標為「留置轉人工」而不動它
 * （`rename_retain_collision`）。收斂完成、loser 的 banks 列被停用標刪之後，那些留置列就
 * 成了孤兒。Alfawin77 base 層收斂後留下的四列即是（426／315／424／350）。
 *
 * ## 三道 gate，都不設放行旗標
 *
 * 1. **自己的 banks 列必須已停用**——上游還活著就不是孤兒，停用會讓會員選得到銀行卻查不到
 *    送單設定而擋單。
 * 2. **該 `bank_code` 在任何代理群組都不得有服務中的銀行列**（同幣別）。cfb 表不分群組，
 *    一列服務於所有用該碼的群組；只看自己那一列的上游會漏掉別群組仍在用的情況。
 * 3. **無 in-flight／可重發的舊碼單**（status 21/22/29；23 已由 MR !2235 退役）。判準與 `AvailableBanksAlignService`
 *    的 prune gate 3 相同，比對加 `BINARY`——欄位 collation 大小寫不敏感，不加會把別的碼算進來。
 *
 * ## 停用而非刪除
 *
 * 與 `MergeBaseSynonymBanks` 的慣例一致（`update(['status' => 0])`）：停用可逆、保留列本身
 * 供日後追溯，而刪除不可逆。要真正移除請另循備份與還原流程。
 *
 * ## 冪等
 *
 * 已停用的列視為無事可做：跳過、不重複寫審計、不算失敗。整批中只要有一列不合格即零寫入。
 *
 * ## 退役條件
 *
 * 與 `check:cashflow-orphans`／`fix:cashflow-orphans` 同一個根因：當四層之間建立
 * FK cascade，或加上「上游停用即連動停用下游」的 Observer 之後，孤兒 cfb 列不會再產生，
 * 三支可一併刪除。
 */
class DeactivateCfbRowsCommand extends Command
{
    protected $signature = 'bank:deactivate-cfb-rows
                            {--cfb=* : 要停用的 cash_flow_banks.id（可指定多個）}
                            {--reason= : 停用理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '停用上游銀行列已停用的孤兒 cash_flow_banks 列（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /**
     * 會擋下停用的提款單狀態：in-flight（21/22）＋可重發（29，非終態、重發會再查碼）。
     * 與 `AvailableBanksAlignService::PRUNE_BLOCKING_WO_STATUSES` 同一套判準，兩處要一起改
     * （23 已由 MR !2235 退役，理由見該常數）。
     */
    private const BLOCKING_WO_STATUSES = [
        WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
        WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
    ];

    public function handle(): int
    {
        $rawIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('cfb'));
        $invalidIds = array_values(array_filter(
            $rawIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalidIds !== []) {
            $this->error(sprintf(
                '--cfb 必須是純數字的 cash_flow_banks.id，收到「%s」。多個請重複旗標（--cfb=426 --cfb=315），不可用逗號分隔。',
                implode('」「', $invalidIds)
            ));

            return self::FAILURE;
        }

        $cfbIds = array_values(array_unique(array_map('intval', $rawIds)));
        $reason = trim((string) $this->option('reason'));

        $invalid = $this->validateOptions($cfbIds, $reason);

        if ($invalid !== null) {
            $this->error($invalid);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers] = $this->classifyRows($cfbIds);

        foreach ($targets as $target) {
            $this->line(sprintf(
                '  [停用] cfb=%d  金流商 %d／幣別 %d  銀行碼 %s／三方碼 %s',
                $target['id'],
                $target['cash_flow_id'],
                $target['currency_id'],
                $target['bank_code'],
                $target['provider_bank_code']
            ));
        }

        foreach ($skipped as $line) {
            $this->line('  [跳過] ' . $line);
        }

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要停用的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info('dry-run：未寫入任何資料，加 --execute 才會實際執行。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($cfbIds, $reason);

        $this->info(sprintf(
            '停用完成：%d 列（審計見 bank_dedup_logs，action=deactivate_orphan_cfb）。',
            $written
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $cfbIds
     */
    private function validateOptions(array $cfbIds, string $reason): ?string
    {
        if ($cfbIds === []) {
            return '--cfb 必填：至少要指定一列 cash_flow_banks.id。';
        }

        if ($reason === '') {
            return '--reason 必填：停用理由會寫入審計，供日後追溯與還原。';
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
     * 逐列分流成「可停用／已停用跳過／阻擋」三類。
     *
     * $lock=true 時以 `lockForUpdate()` 讀取，供交易內重驗使用。
     *
     * @param  list<int>  $cfbIds
     * @return array{targets: list<array{id: int, cash_flow_id: int, currency_id: int, bank_id: int, bank_code: string, provider_bank_code: string, agent_group_id: int}>, skipped: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $cfbIds, bool $lock = false): array
    {
        $targets = [];
        $skipped = [];
        $blockers = [];

        foreach ($cfbIds as $cfbId) {
            $query = DB::table('cash_flow_banks')->where('id', $cfbId);

            if ($lock) {
                $query->lockForUpdate();
            }

            $row = $query->first(['id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code', 'provider_bank_code', 'status']);

            if ($row === null) {
                $blockers[] = sprintf('cash_flow_banks 沒有 id=%d 的列。', $cfbId);

                continue;
            }

            if ((int) $row->status === 0) {
                $skipped[] = sprintf('cfb=%d 已是停用狀態，無事可做。', $cfbId);

                continue;
            }

            $blocker = $this->inspectRow($row);

            if ($blocker !== null) {
                $blockers[] = $blocker;

                continue;
            }

            $ownBank = Bank::query()->where('id', $row->bank_id)->first(['id', 'agent_group_id']);

            $targets[] = [
                'id' => (int) $row->id,
                'cash_flow_id' => (int) $row->cash_flow_id,
                'currency_id' => (int) $row->currency_id,
                'bank_id' => (int) $row->bank_id,
                'bank_code' => (string) $row->bank_code,
                'provider_bank_code' => (string) $row->provider_bank_code,
                'agent_group_id' => (int) ($ownBank->agent_group_id ?? 0),
            ];
        }

        return ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers];
    }

    /**
     * 三道 gate 的實作；回傳阻擋原因，`null` 表示這一列可停用。
     */
    private function inspectRow(object $row): ?string
    {
        $ownBankActive = Bank::query()
            ->where('id', $row->bank_id)
            ->where('status', 1)
            ->exists();

        if ($ownBankActive) {
            return sprintf(
                'cfb=%d 的 banks 列（id=%d）仍在服務中，這不是孤兒：停用會讓會員選得到銀行卻查不到送單設定而擋單。',
                (int) $row->id,
                (int) $row->bank_id
            );
        }

        // cfb 不分代理群組，一列服務於所有用該碼的群組；只看自己那一列的上游會漏掉別群組仍在用的情況
        $activeElsewhere = Bank::query()
            ->where('code', $row->bank_code)
            ->where('currency_id', $row->currency_id)
            ->where('status', 1)
            ->first(['id', 'agent_group_id']);

        if ($activeElsewhere !== null) {
            return sprintf(
                'cfb=%d 的銀行碼「%s」在代理群組 %d 仍有服務中的列（banks id=%d）：該群組的會員綁得到，仍需要這筆送單設定。',
                (int) $row->id,
                (string) $row->bank_code,
                (int) $activeElsewhere->agent_group_id,
                (int) $activeElsewhere->id
            );
        }

        $blocking = WithdrawalOrder::query()
            ->whereIn('status', self::BLOCKING_WO_STATUSES)
            ->whereRaw('BINARY bank_code = ?', [(string) $row->bank_code])
            ->count();

        if ($blocking > 0) {
            return sprintf(
                'cfb=%d 的銀行碼「%s」有 %d 筆 in-flight／可重發提款單（status 21/22/29）：'
                . '停用後代付查表會落空而斷付，待結清再停用。',
                (int) $row->id,
                (string) $row->bank_code,
                $blocking
            );
        }

        return null;
    }

    /**
     * 在交易內重驗後停用並寫審計。
     *
     * @param  list<int>  $cfbIds
     */
    private function apply(array $cfbIds, string $reason): int
    {
        $written = 0;

        DB::transaction(function () use ($cfbIds, $reason, &$written): void {
            ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($cfbIds, lock: true);

            if ($blockers !== []) {
                throw new BankConvergenceAbortedException('鎖內重驗未通過，整批回滾：' . implode('；', $blockers));
            }

            $connection = (string) config('database.default');
            $stamp = Carbon::now();

            foreach ($targets as $target) {
                DB::table('cash_flow_banks')->where('id', $target['id'])->update([
                    'status' => 0,
                    'updated_at' => $stamp,
                ]);

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => $target['agent_group_id'],
                    'code' => $target['bank_code'],
                    // 單列停用沒有 survivor／loser 之分：自我參照表示「本列即標的」
                    'survivor_bank_id' => $target['bank_id'],
                    'loser_bank_id' => $target['bank_id'],
                    'action' => 'deactivate_orphan_cfb',
                    'detail' => $this->encodeAuditDetail([
                        't' => 'cash_flow_banks',
                        'id' => $target['id'],
                        'cf' => $target['cash_flow_id'],
                        'cur' => $target['currency_id'],
                        'third' => $target['provider_bank_code'],
                        'was' => ['status' => 1],
                        'aft' => ['status' => 0],
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 cfb id 區間會重疊，
     * 光看命令列分不出改的是哪一站——所以每次執行都先把目標印出來。
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
