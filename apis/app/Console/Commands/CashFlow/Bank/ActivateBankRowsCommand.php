<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 啟用指定的 banks 列（誤停、暫停後復用、收斂前置等一次性處置的共用出口）。
 *
 * 【分類】Bank — 可複用維運工具，長期保留。
 *
 * ## 為什麼需要這支
 *
 * `bank:deactivate-rows` 有停用出口，但反向沒有：整個 Bank/ 目錄沒有任何命令能把
 * `status` 從 0 改回 1。而「啟用一列」是好幾條既有流程的前置——最直接的是
 * `bank:rename-bank`，它的 preflight 要求 survivor 必須是 `status=1` 且未標刪
 * （`RenameBankCommand::validate()`），收斂目標剛好停用中時整條路就斷在這裡。
 * 在此之前只能靠後台人工點或裸寫 DB，兩者都不留審計。
 *
 * ## 停用與啟用不是對稱操作
 *
 * 停用要 `status=0` 與 `deleted_at` 並寫，否則會被 `AgentBankSyncService::syncToAgentGroup()`
 * 打回——它挑 target 只濾 `whereNull('deleted_at')`、不看 status，選中就改回 `status=1`。
 *
 * 啟用則**只寫 `status`、完全不碰 `deleted_at`**。因為已標刪的列一律在預檢阻斷（見下），
 * 走到寫入的列其 `deleted_at` 必然已是 NULL，沒有可寫的東西；而「清掉 deleted_at」是
 * 語意完全不同的復原動作，不該藏在啟用裡順手做。
 *
 * ## 預檢：撞鍵是唯一真正的風險
 *
 * 停用的風險在「還有人引用」，啟用沒有這個問題——多一列可用的銀行不會讓任何既有引用失效。
 * 啟用真正會壞的只有一件事：**憑空生出一個 active 鍵位**。
 *
 * 1. **撞鍵** ── `uk_banks_active` 的判準是四元組 `agent_group_id + type + currency_id + code`，
 *    以 generated stored column 實作（只有 `status=1` 的列才有值，inactive 重複不受限）。
 *    同鍵已有 active 列時啟用第二列，在已建索引的站台是寫入失敗、在尚未建的站台是靜默
 *    製造重複——而該索引至今未全站上線，擋不擋只能靠命令自己。
 *    ⚠️ 佔位判定**不濾 `deleted_at`**：`status=1` 但已標刪的矛盾列是本專案真實存在的形態，
 *    它照樣佔著 generated column 的鍵位。
 * 2. **同批互撞** ── 兩個 `--bank` 指向同四元組時，逐列查庫都看不出衝突（兩列都還是
 *    inactive），要等寫到第二列才撞。故在分流後另做一次批次內的鍵位彙總。
 * 3. **已標刪** ── `AgentBankSyncService`、`AvailableBanksAlignService`、
 *    `BackfillStandardBanksCommand` 都帶 `whereNull('deleted_at')`。啟用一個標刪列
 *    只會做出「後台看得到、多條同步路徑視為不存在」的矛盾資料，且它同時佔住鍵位、
 *    擋掉真正該啟用的那列。要復用標刪列請先確認它為什麼被標刪，本命令不提供繞過旗標。
 *
 * ## 限額為 0 是警告不是阻斷
 *
 * 代理群組層的列限額為 0 代表「沒設限額」，語意風險在群組層才成立（平台共用層的 0
 * 是正常的「不限制」）。啟用一列 0/0 的群組層銀行等於開一條沒有上下限的提款通道，
 * 故印出警告並點名 `bank:set-amount-limits`——但不阻斷：限額是獨立的一件事，
 * 塞在啟用裡當硬條件會逼人為了過關而先亂填一個值。
 *
 * ## 預設不寫入
 *
 * 與 bank: 家族其他寫入型命令一致（rename-bank／dedup-active／deactivate-rows 皆為
 * `--execute`）。不帶 `--execute` 就只輸出預檢報表，強制經過一次看報表——點名的 id
 * 打錯時（1459 打成 1495）才有機會當場發現。
 *
 * ## 還原
 *
 * 審計寫 `bank_dedup_logs`（action `activate_row`），`detail.was` 記異動前的 status。
 * 還原即 `bank:deactivate-rows`，但注意那支會一併寫 `deleted_at`——不是逐位元的反向。
 *
 * 用法：
 *   php artisan bank:activate-rows --bank=1459 --reason="銀行收斂 survivor 前置"
 *   php artisan bank:activate-rows --bank=1459 --reason="銀行收斂 survivor 前置" --execute --force
 *
 * ## 退役條件
 *
 * **不設退役條件。** 「啟用一列銀行」與停用同屬常態營運動作，不隨銀行收斂結束而消失；
 * 本命令是這件事的共用出口，散在各處自己寫 update 或改後台才是問題。
 */
class ActivateBankRowsCommand extends Command
{
    protected $signature = 'bank:activate-rows
                            {--bank=* : 要啟用的 banks.id（可指定多個）}
                            {--reason= : 啟用理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '啟用指定的 banks 列（只寫 status=1；撞鍵或已標刪則整批零寫入）';

    /**
     * 審計 detail 的長度上限，與 DeactivateBankRowsCommand::DETAIL_MAX_LENGTH 一致。
     * 截斷的 JSON 比人話更糟——解不開又救不回，寧可整筆失敗讓人查。
     */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** 平台共用層的 agent_group_id；該層限額為 0 是「不限制」的正常語意。 */
    private const BASE_AGENT_GROUP_ID = 0;

    public function handle(): int
    {
        $rawBankIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('bank'));
        $invalidBankIds = array_values(array_filter(
            $rawBankIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalidBankIds !== []) {
            $this->error(sprintf(
                '--bank 必須是純數字的 banks.id，收到「%s」。多個請重複旗標（--bank=7 --bank=9），不可用逗號分隔。',
                implode('」「', $invalidBankIds)
            ));

            return self::FAILURE;
        }

        $bankIds = array_values(array_unique(array_map('intval', $rawBankIds)));
        $reason = trim((string) $this->option('reason'));

        if ($bankIds === []) {
            $this->error('--bank 必填：請指定要啟用的 banks.id。');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason 必填：啟用理由會寫入審計，供日後追溯與反演。');

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

        ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers]
            = $this->classifyRows($bankIds);

        $this->reportPlan($targets, $skipped);

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要啟用的列。');

            return self::SUCCESS;
        }

        $this->warnZeroAmountLimits($targets);

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可啟用，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->activate($bankIds, $reason);

        $this->info(sprintf('啟用完成：%d 列（審計見 bank_dedup_logs，action=activate_row）。', $written));

        return self::SUCCESS;
    }

    /**
     * 逐列分流成「可啟用／已處理過／不合格」三類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間
     * 的窗口是分鐘級（非毫秒級），期間他人可能啟用了同鍵的另一列。
     *
     * @param  list<int>  $bankIds
     * @return array{targets: list<Bank>, skipped: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $bankIds, bool $lock = false): array
    {
        $query = Bank::query()->whereIn('id', $bankIds);

        if ($lock) {
            $query->lockForUpdate();
        }

        $banks = $query->get()->keyBy('id');

        $targets = [];
        $skipped = [];
        $blockers = [];

        foreach ($bankIds as $id) {
            $bank = $banks->get($id);

            if ($bank === null) {
                $blockers[] = sprintf('id=%d 不存在', $id);

                continue;
            }

            if ((int) $bank->status === 1) {
                $skipped[] = sprintf('id=%d「%s」已是啟用中', $id, $bank->name);

                continue;
            }

            // 標刪列一律阻斷：多條同步路徑帶 whereNull('deleted_at')，啟用它只會做出
            // 「後台看得到、同步視為不存在」的矛盾資料，而它照樣佔住鍵位。
            if ($bank->deleted_at !== null) {
                $blockers[] = sprintf(
                    'id=%d「%s」已標記 deleted_at（%s），本命令不復活標刪列',
                    $id,
                    $bank->name,
                    $bank->deleted_at,
                );

                continue;
            }

            $occupant = $this->activeKeyOccupant($bank, $lock);

            if ($occupant !== null) {
                $blockers[] = sprintf(
                    'id=%d「%s」的鍵位（群組 %d／type %d／幣別 %d／碼 %s）已被 active 的 id=%d 佔住',
                    $id,
                    $bank->name,
                    (int) $bank->agent_group_id,
                    (int) $bank->type,
                    (int) $bank->currency_id,
                    (string) $bank->code,
                    (int) $occupant->id,
                );

                continue;
            }

            $targets[] = $bank;
        }

        return [
            'targets' => $targets,
            'skipped' => $skipped,
            'blockers' => [...$blockers, ...$this->batchKeyCollisions($targets)],
        ];
    }

    /**
     * 找出同鍵已存在的 active 列（`uk_banks_active` 的四元組）。
     *
     * ⚠️ 不濾 `deleted_at`：唯一索引的 generated column 只看 `status=1`，
     * 「已啟用但已標刪」的矛盾列照樣佔位。
     */
    private function activeKeyOccupant(Bank $bank, bool $lock): ?Bank
    {
        $query = Bank::query()
            ->where('agent_group_id', $bank->agent_group_id)
            ->where('type', $bank->type)
            ->where('currency_id', $bank->currency_id)
            ->where('code', $bank->code)
            ->where('status', 1)
            ->whereKeyNot($bank->id)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * 批次內的鍵位互撞。
     *
     * 逐列查庫看不出來——同批的兩列此刻都還是 inactive，誰都不佔位，要等寫到第二列
     * 才撞。故在分流後對 targets 再做一次鍵位彙總。
     *
     * @param  list<Bank>  $targets
     * @return list<string>
     */
    private function batchKeyCollisions(array $targets): array
    {
        $seen = [];
        $collisions = [];

        foreach ($targets as $bank) {
            $key = implode('|', [
                (int) $bank->agent_group_id,
                (int) $bank->type,
                (int) $bank->currency_id,
                (string) $bank->code,
            ]);

            if (isset($seen[$key])) {
                $collisions[] = sprintf(
                    'id=%d 與 id=%d 在同一批次內共用鍵位（群組 %d／type %d／幣別 %d／碼 %s），只能擇一啟用',
                    $seen[$key],
                    (int) $bank->id,
                    (int) $bank->agent_group_id,
                    (int) $bank->type,
                    (int) $bank->currency_id,
                    (string) $bank->code,
                );

                continue;
            }

            $seen[$key] = (int) $bank->id;
        }

        return $collisions;
    }

    /**
     * @param  list<Bank>  $targets
     * @param  list<string>  $skipped
     */
    private function reportPlan(array $targets, array $skipped): void
    {
        foreach ($skipped as $line) {
            $this->line('  [跳過] ' . $line);
        }

        foreach ($targets as $bank) {
            $this->line(sprintf('  [啟用] id=%d  %s  (%s)', $bank->id, $bank->name, $bank->code));
        }
    }

    /**
     * 代理群組層限額為 0 的警告。
     *
     * 平台共用層（agent_group_id = 0）的 0 是「不限制」的正常語意，不警告；群組層的 0
     * 則等於開一條沒有上下限的提款通道。警告不阻斷——限額是獨立的一件事，塞成硬條件
     * 只會逼人先亂填一個值來過關。
     *
     * @param  list<Bank>  $targets
     */
    private function warnZeroAmountLimits(array $targets): void
    {
        foreach ($targets as $bank) {
            if ((int) $bank->agent_group_id === self::BASE_AGENT_GROUP_ID) {
                continue;
            }

            if ((float) $bank->min_amount > 0 && (float) $bank->max_amount > 0) {
                continue;
            }

            $this->warn(sprintf(
                '⚠️  id=%d「%s」的限額是 %s／%s——代理群組層的 0 等於沒有上下限，'
                . '啟用後請接著跑 bank:set-amount-limits 補齊。',
                $bank->id,
                $bank->name,
                (string) $bank->min_amount,
                (string) $bank->max_amount,
            ));
        }
    }

    /**
     * 於交易內鎖定並重驗後啟用，回傳實際寫入列數。
     *
     * @param  list<int>  $bankIds
     */
    private function activate(array $bankIds, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $run = 'act_' . $stamp->format('YmdHis');
        $written = 0;

        DB::transaction(function () use ($bankIds, $reason, $connection, $stamp, $run, &$written): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間可能有人啟用了同鍵的另一列。
            // 判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉 TOCTOU 窗口。
            $recheck = $this->classifyRows($bankIds, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $bank) {
                $before = DB::table('banks')
                    ->where('id', $bank->id)
                    ->first(['id', 'status', 'deleted_at']);

                if ($before === null) {
                    throw new BankConvergenceAbortedException(
                        sprintf('id=%d 在鎖定後消失，整批回滾。', $bank->id)
                    );
                }

                $written++;

                // 只寫 status：deleted_at 必然已是 NULL（標刪列在預檢阻斷），
                // 而清 deleted_at 是語意不同的復原動作，不藏在啟用裡順手做。
                DB::table('banks')->where('id', $bank->id)->update([
                    'status' => 1,
                    'updated_at' => $stamp,
                ]);

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => (int) $bank->agent_group_id,
                    'code' => (string) $bank->code,
                    // 純啟用沒有 survivor：自我參照表示「本列即標的」，反演時只看 loser 側
                    'survivor_bank_id' => (int) $bank->id,
                    'loser_bank_id' => (int) $bank->id,
                    'action' => 'activate_row',
                    'detail' => $this->encodeAuditDetail([
                        'run' => $run,
                        't' => 'banks',
                        'id' => (int) $bank->id,
                        'was' => [
                            'status' => (int) $before->status,
                            'deleted_at' => $before->deleted_at,
                        ],
                        'aft' => [
                            'status' => 1,
                            'deleted_at' => $before->deleted_at,
                        ],
                        'reason' => $reason,
                    ]),
                    'created_at' => $stamp,
                ]);
            }
        });

        return $written;
    }

    /**
     * 編碼審計 detail。
     *
     * 無效 UTF-8 直接拋（json_encode 預設回 false，會靜默寫入空審計，而 banks 那筆
     * 寫入照樣 commit）；超長也拋——截斷的 JSON 比人話更糟，解不開又救不回。
     *
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
     * 阻斷才是最需要核對環境的時刻，「鍵位被佔」既可能是真的，也可能只是連錯站，
     * 沒有這兩行就分不出來。
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
