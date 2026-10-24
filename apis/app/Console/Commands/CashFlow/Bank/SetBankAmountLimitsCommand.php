<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Helpers\Common;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 逐列調整 banks 的提款限額（收單一敞口用，非整批重算）。
 *
 * 【分類】Bank — 可複用維運工具，長期保留；⚠️ 對 base 層非 sync-safe（見下方）。
 *
 * ## 為什麼不用 cash-flow:reset-banks-min-max-amount
 *
 * 那支是「整群組＋幣別依所有代付 ACF 的 info 重算」（取最小 min／最大 max），
 * 選取範圍是整個群組，收一兩個敞口會連帶改動同群組其餘數十列；且它只濾
 * cash_flow.status、不濾 ACF 自身 status，已停用通道的限額也會被算進來。
 * 本命令以 banks.id 點名，選取即範圍，適合「這幾列的上限要收掉」這種訴求。
 *
 * ## 拒絕 0 —— banks 的 0 不是「不限制」
 *
 * 前台 WithdrawalController::getBanks() 對 0 的解讀是「回退代理層自助提款設定」
 * （AgentService::getWithdrawalAmountLimit()，讀 cash_flow_id=6 的 ACF info.MaxAmount），
 * 所以把上限設成 0 的效果是把該行放大到代理預設值——VPlus33 實案是 500,000，
 * 與操作者「解除限制」的預期相反且方向危險。要放寬請給明確的大數值。
 *
 * 同理，**寫入後任一側仍為 0 也一律阻斷**：0 表示該側走代理層設定，本列的數值
 * 無從驗證區間。min=0 的列只給 --max=50 時，代理預設下限可能遠高於 50，拿本列的
 * 0 去比卻是合法的，於是寫出永遠提不了款的列——那正是區間閘門存在的唯一理由。
 * 反向（max=0 的列只給 --min）則會誤判為「min > max 該行將無法提款」，與事實相反。
 * 要調這類列請同時指定 --min 與 --max，讓結果值兩側都是明確數值。
 *
 * ## 結果值判定，不是選項值判定
 *
 * min/max 的合法性用「寫入後的值」比較：只給 --max 時仍要與該列既有 min 比對，
 * 否則會寫出 min > max 這種永遠提不了款的區間。
 *
 * ## 金額全程走字串，且未指定的欄位不進 UPDATE
 *
 * banks.min_amount／max_amount 是 decimal(18,4)，Eloquent 讀回來是字串。轉 int 會把
 * 0.1000 截成 0——也就是從側面寫進上面那個被明文拒絕的 sentinel，而報表顯示 0→0
 * 看起來像沒動。故入境即 Common::numAdd('0', …, 4) 正規化、比較一律 Common::numCompare，
 * 審計 was 記原始字串值（它是唯一的反演來源）。未指定的那一側完全不進 UPDATE payload，
 * 而不是「把既有值原樣寫回」——後者在有小數時等於靜默改值。
 *
 * ## 判定在鎖內重跑
 *
 * 預檢到人工確認之間的窗口是分鐘級，期間他人（代理後台／cash-flow:sync／
 * agent:add-agent-cash-flow）可能改動同一列。故交易內以 lockForUpdate() 重跑整組
 * 分類，不合格就整批 rollback——鎖若只服務寫入、判定仍沿用鎖前的值，就會用舊值
 * 覆蓋掉對方的調整，而 min <= max 也是拿舊值驗的。
 *
 * ## sync-safe
 *
 * 群組層（agent_group_id > 0）寫入非 0 值後，AgentBankSyncService::syncToAgentGroup()
 * 會沿用既有值（既有 > 0 就保留），cash-flow:sync 與 agent:add-agent-cash-flow 都不會覆寫。
 * ⚠️ 但 base 層（agent_group_id = 0）不同：SyncCashFlowsCommand::resolveAmountLimits()
 * 對既有 > 0 的列取 max(既有, config)，對 base 層調低的上限會在下次 sync 被放大回去。
 * 本命令對 base 層的目標列會明示警告，不阻斷。
 *
 * ## 預設不寫入
 *
 * 與 bank: 家族其他寫入型命令一致（rename-bank／dedup-active／dedup-rollback／
 * converge-cross-code／restore-fk-timestamps 皆為 --execute，fix-mismatch 為 --apply）：
 * 不帶 --execute 就只輸出預檢報表。
 *
 * 收益有二，第二項才是主要的：忘記帶旗標的後果變成「什麼都沒做」而非「已經寫下去」；
 * 更重要的是**強制經過一次看報表**——點名的 id 打錯時（659 打成 65），現狀是寫完才
 * 知道，預設預演則會先把 `[調整] id=65 …` 印出來讓人當場發現。這對一支沒有自動
 * rollback 的命令特別重要。
 *
 * ⚠️ 但這道防線擋不住「跑錯站」：`bootstrap/cache/config.php` 存在時 `-e DB_CONNECTION`
 * 注入會失效，而 `mysql` 正好在 confirmExecutionEnvironment() 的放行清單內。那個洞要靠
 * 「寫入前印出實際連線與資料庫名」補（RenameBankCommand 有現成實作）。
 *
 * ## 還原
 *
 * 審計寫 bank_dedup_logs（action `set_amount_limits`），detail.was 記異動前的
 * min_amount／max_amount，還原即把 was 寫回。無自動 rollback 命令。
 *
 * 用法：
 *   php artisan bank:set-amount-limits --bank=7 --bank=9 --max=30000 --reason="收斂超出通道能力的單筆敞口"
 *   php artisan bank:set-amount-limits --bank=7 --bank=9 --max=30000 --reason="收斂超出通道能力的單筆敞口" --execute --force
 *
 * ## 退役條件
 *
 * **不設退役條件。** 調提款限額是常態營運動作，不隨銀行收斂結束而消失。
 *
 * ⚠️ 但對 **base 層**的調整非 sync-safe：`cash-flow:sync` 的 `resolveAmountLimits()` 對既有
 * > 0 的列取 `max(既有, config)` ⇒ 調低的上限會被放大回去，跑完 sync 要重跑。
 * 群組層不受影響（`AgentBankSyncService::syncToAgentGroup()` 既有 > 0 就保留）。
 */
class SetBankAmountLimitsCommand extends Command
{
    protected $signature = 'bank:set-amount-limits
                            {--bank=* : 要調整的 banks.id（可指定多個）}
                            {--min= : 新的最小提款金額（省略則不動該欄）}
                            {--max= : 新的最大提款金額（省略則不動該欄）}
                            {--reason= : 調整理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預覽）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '逐列調整指定 banks 的提款限額（任一列不合格則整批零寫入）';

    /**
     * 審計 detail 的長度上限，與 RenameBankCommand::DETAIL_MAX_LENGTH 一致。
     * 截斷的 JSON 比人話更糟——解不開又救不回，寧可整筆失敗讓人查。
     */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

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
        $minOption = $this->option('min');
        $maxOption = $this->option('max');

        if ($bankIds === []) {
            $this->error('--bank 必填：請指定要調整的 banks.id。');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason 必填：調整理由會寫入審計，供日後追溯與反演。');

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

        if ($minOption === null && $maxOption === null) {
            $this->error('--min 與 --max 至少要給一個，否則本命令沒有要寫的東西。');

            return self::FAILURE;
        }

        $newMin = $this->parseAmount($minOption, 'min');
        $newMax = $this->parseAmount($maxOption, 'max');

        if ($newMin === false || $newMax === false) {
            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers]
            = $this->classifyRows($bankIds, $newMin, $newMax);

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
            $this->info('沒有需要調整的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可調整，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($bankIds, $newMin, $newMax, $reason);

        $this->info(sprintf('調整完成：%d 列（審計見 bank_dedup_logs，action=set_amount_limits）。', $written));

        return self::SUCCESS;
    }

    /**
     * 逐列分流成「可調整／已是目標值／不合格」三類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間
     * 的窗口是分鐘級（非毫秒級），期間他人可能改動同一列的限額。
     *
     * @param  list<int>  $bankIds
     * @return array{targets: list<array{bank: Bank, min: ?string, max: ?string, result_min: string, result_max: string}>, skipped: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $bankIds, ?string $newMin, ?string $newMax, bool $lock = false): array
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

            // decimal(18,4) 讀回來是字串，入境即正規化；全程走 Common 比較，不轉 int
            $currentMin = Common::numAdd('0', (string) $bank->min_amount, 4);
            $currentMax = Common::numAdd('0', (string) $bank->max_amount, 4);
            $resultMin = $newMin ?? $currentMin;
            $resultMax = $newMax ?? $currentMax;

            // 0 是 sentinel（該側回退代理層自助提款設定），本列的數值無從驗證區間：
            // min=0 的列只給 --max 時，代理預設下限可能遠高於新上限，比出來卻是合法的。
            $zeroSide = match (true) {
                Common::numCompare($resultMin, '0', 4) === 0 => 'min',
                Common::numCompare($resultMax, '0', 4) === 0 => 'max',
                default => null,
            };

            if ($zeroSide !== null) {
                $blockers[] = sprintf(
                    'id=%d「%s」寫入後 %s 仍為 0＝回退代理層設定，無法以本列數值驗證區間；'
                    . '請同時指定 --min 與 --max',
                    $id,
                    $bank->name,
                    $zeroSide
                );

                continue;
            }

            // 結果值判定：只給 --max 時仍要與既有 min 比較，否則寫出永遠提不了款的區間
            if (Common::numCompare($resultMin, $resultMax, 4) > 0) {
                $blockers[] = sprintf(
                    'id=%d「%s」寫入後會是 min=%s > max=%s，該行將無法提款',
                    $id,
                    $bank->name,
                    $resultMin,
                    $resultMax
                );

                continue;
            }

            if (Common::numCompare($resultMin, $currentMin, 4) === 0
                && Common::numCompare($resultMax, $currentMax, 4) === 0) {
                $skipped[] = sprintf('id=%d「%s」已是目標值（min=%s max=%s）', $id, $bank->name, $currentMin, $currentMax);

                continue;
            }

            // min／max 分別保留「本次是否指定」：未指定的欄位不進 UPDATE，
            // 否則會把正規化後的既有值原樣回寫，signature 的「省略則不動該欄」就成了空話。
            $targets[] = [
                'bank' => $bank,
                'min' => $newMin,
                'max' => $newMax,
                'result_min' => $resultMin,
                'result_max' => $resultMax,
            ];
        }

        return ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers];
    }

    /**
     * @param  list<array{bank: Bank, min: ?string, max: ?string, result_min: string, result_max: string}>  $targets
     * @param  list<string>  $skipped
     */
    private function reportPlan(array $targets, array $skipped): void
    {
        foreach ($skipped as $line) {
            $this->line('  [跳過] ' . $line);
        }

        foreach ($targets as $target) {
            $bank = $target['bank'];

            // 顯示未經截斷的原值，否則 0.5→0 這種寫入在報表上看起來是「沒動」
            $this->line(sprintf(
                '  [調整] id=%d  %s  (%s)  min: %s→%s%s  max: %s→%s%s',
                $bank->id,
                $bank->name,
                $bank->code,
                Common::numAdd('0', (string) $bank->min_amount, 4),
                $target['result_min'],
                $target['min'] === null ? '（不動）' : '',
                Common::numAdd('0', (string) $bank->max_amount, 4),
                $target['result_max'],
                $target['max'] === null ? '（不動）' : ''
            ));

            if ((int) $bank->agent_group_id === 0) {
                $this->warn(sprintf(
                    '    ⚠️  id=%d 是 base 層（agent_group_id=0）：調低的上限會被 cash-flow:sync 的 max(既有, config) 放大回去',
                    $bank->id
                ));
            }
        }
    }

    /**
     * 解析金額選項；未給回傳 null，格式不合或為 0 回傳 false 並印出原因。
     *
     * 回傳正規化後的字串（非 int）：banks 的金額欄是 decimal(18,4)，
     * 全程以字串經 Common 比較，避免任何一段落回原生型別而截斷。
     */
    private function parseAmount(mixed $raw, string $label): string|null|false
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if (!preg_match('/^\d+$/', $value)) {
            $this->error(sprintf('--%s 必須是非負整數，收到「%s」。', $label, $value));

            return false;
        }

        $amount = Common::numAdd('0', $value, 4);

        if (Common::numCompare($amount, '0', 4) === 0) {
            $this->error(sprintf(
                '--%s 不接受 0：banks 的 0 在前台是「回退代理層自助提款設定」而非「不限制」，'
                . '設 0 會把該行放大到代理預設值。要放寬請給明確的大數值。',
                $label
            ));

            return false;
        }

        return $amount;
    }

    /**
     * 於交易內鎖定並重驗後寫入，回傳實際寫入列數。
     *
     * @param  list<int>  $bankIds
     */
    private function apply(array $bankIds, ?string $newMin, ?string $newMax, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $run = 'limit_' . $stamp->format('YmdHis');
        $written = 0;

        DB::transaction(function () use (
            $bankIds,
            $newMin,
            $newMax,
            $reason,
            $connection,
            $stamp,
            $run,
            &$written
        ): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間他人（代理後台／cash-flow:sync／
            // agent:add-agent-cash-flow）可能改動同一列。判定必須用鎖到的值重算，否則會用
            // 鎖前的舊值覆蓋掉對方的調整，而 min <= max 也是拿舊值驗的。
            $recheck = $this->classifyRows($bankIds, $newMin, $newMax, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $target) {
                // classifyRows 帶鎖讀出的實例，即為要寫入的那一列
                $locked = $target['bank'];
                $written++;

                // 審計是唯一的反演來源：記原始值，不轉型、不截斷
                $wasMin = (string) $locked->min_amount;
                $wasMax = (string) $locked->max_amount;

                // 只寫本次真正指定的欄位
                if ($target['min'] !== null) {
                    $locked->min_amount = $target['min'];
                }

                if ($target['max'] !== null) {
                    $locked->max_amount = $target['max'];
                }

                $locked->save();

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => (int) $locked->agent_group_id,
                    'code' => (string) $locked->code,
                    // 限額調整沒有 survivor：自我參照表示「本列即標的」，反演時只看 loser 側
                    'survivor_bank_id' => (int) $locked->id,
                    'loser_bank_id' => (int) $locked->id,
                    'action' => 'set_amount_limits',
                    'detail' => $this->encodeAuditDetail([
                        'run' => $run,
                        't' => 'banks',
                        'id' => (int) $locked->id,
                        'was' => [
                            'min_amount' => $wasMin,
                            'max_amount' => $wasMax,
                        ],
                        'aft' => [
                            'min_amount' => (string) $locked->min_amount,
                            'max_amount' => (string) $locked->max_amount,
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
     * 不可逆的寫入照樣 commit）；超長也拋——截斷的 JSON 比人話更糟，解不開又救不回。
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
     * 阻斷才是最需要核對環境的時刻，「區間顛倒」既可能是真的，也可能只是連錯站，
     * 沒有這兩行就分不出來。
     *
     * 這與 confirmExecutionEnvironment() 補的是不同的洞：後者只在 --execute 且未帶
     * --force 時才問，且對 mysql／dev／testing 直接放行不印，預演路徑一個字都沒有。
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
