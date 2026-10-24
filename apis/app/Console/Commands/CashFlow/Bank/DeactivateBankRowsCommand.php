<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\BankConvergenceAbortedException;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlowBank;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 停用指定的 banks 列（錯置幣別、廢棄、誤建等一次性清理的共用出口）。
 *
 * 【分類】Bank — 可複用維運工具，長期保留。
 *
 * ## 為什麼一定要同時寫 deleted_at
 *
 * 光設 `status=0` 會被 sync 打回：`AgentBankSyncService::syncToAgentGroup()` 挑 target
 * 只濾 `whereNull('deleted_at')`、不看 status，選中後直接把它改回 `status=1`
 * （TPower AGRO 776/1300 實案，見 RenameBankCommand 的同段註記）。故本命令兩欄並寫。
 *
 * `Bank` model 無 SoftDeletes，`deleted_at` 在此只是清理標記；碼佔用查詢
 * （`SyncCashFlowsCommand` 的 $usedCodes）不帶該過濾，停用列的碼不會被回收重用。
 *
 * ## 引用預檢，任一不過整批零寫入
 *
 * 1. 會員綁定（`member_bank_accounts.bank_id`）── 停用後該會員的綁定銀行變成孤兒。
 * 2. `available_banks` 舊碼 ── 代付舊路徑仍以 target 碼查 entry，停用等於該行選不到。
 * 3. `cash_flow_banks`（`bank_id` 或 `bank_code`）── 代付查表要求 `status=1`，
 *    停用的結果是斷付而非降級。
 * 4. 未終態提款單（`withdrawal_orders.bank_id`）── 只看未終態；歷史終態單必然永遠
 *    存在，全數計數會讓任何用過的銀行都停不掉。
 * 5. `SATELLITE_BANK_ID_TABLES` 三張 ── 實名驗證申請與代理銀行帳戶，各站 schema
 *    未必一致故先 `Schema::hasTable()`。
 *
 * `banks.id` 的完整引用面以 `BankRepairService::FK_TABLES` 為單一來源；`order_images`
 * 刻意不在其中（其 `bank_id` 指 `bank_accounts.id`）。
 *
 * ## `--scope-refs-to-group`：別的群組的同碼引用不阻斷
 *
 * 預設的第 2、3 條是全站範圍：任何群組、任何狀態、未標刪的 ACF，只要清單裡有這個碼就擋；
 * `cash_flow_banks` 也是按碼比對、不分群組。對基底層模板列這是對的，但對代理群組層的列會
 * 誤擋：代付查的是**訂單所屬群組**的 ACF，別的群組清單裡的同碼項目，以及本群組根本沒有
 * 接的金流商的 cfb 列，都不會因為停用本列而查不到。
 *
 * 實例（2026-09-15 VWorld 代理群組 93，Wintoto 測試組）：12 列 SGD 銀行零綁定、零提款單，
 * 正式組（代理群組 70）的同碼列早已停用；但每個碼都還被其他群組停用中的舊 AllPay 清單或
 * 代理群組 99 的清單引用，其中 4 個碼另有 cf 76／110／118 的 cfb 列，預設模式一列都停不掉。
 * `bank:remove-acf-bank-entry` 又要求群組內已無服務中的同碼列，兩支互相卡住。
 *
 * 帶上本旗標後：
 * - `available_banks` 只計入**本列所屬代理群組、幣別相同或 ACF 幣別為空、`status=1`**
 *   的 ACF。停用中的 ACF 不送單，裡面的項目是死引用；日後重新啟用，會員也選不到已停用的
 *   銀行。幣別為空的 ACF 仍計入：舊資料有未回填 `currency_id` 的 ACF
 *   （見 `FixAgentCashFlowsCurrencyId`），排除它們會把真的在送單的清單當成不存在。
 * - `cash_flow_banks` 以 `bank_id` 指向本列的仍一律計入；以碼比對的，只計入「本群組有
 *   服務中 ACF 的 `cash_flow_id`」且幣別相同的列。代付查表以
 *   `(cash_flow_id, currency_id, bank_code)` 為鍵，只有本群組接了的金流商才會拿本群組
 *   訂單的碼去查。
 * - 基底層列（`agent_group_id=0`）與幣別為空的列一律阻斷：前者是 `AgentBankSyncService`
 *   複製到各群組的模板，引用面本來就是全站；後者無從判定同幣別範圍。
 * - 會員綁定、未終態提款單、附屬表三條不受影響；審計 detail 另記 `refs_scope=group`，
 *   事後能分辨這批是用哪種判準放行的。
 *
 * ## 判定在鎖內重跑
 *
 * 預檢到人工確認之間的窗口是分鐘級，期間可能有人新增綁定或啟用引用。故交易內以
 * `lockForUpdate()` 重跑整組分類，結果不合格就整批 rollback——鎖若只服務寫入、
 * 判定仍沿用鎖前的值，等於只做了序列化、沒關掉 TOCTOU 窗口。
 *
 * 沿用 rename／dedup 家族的慣例：阻斷一律零寫入、報表轉人工，不做「跳過壞的、寫好的」
 * 部分成功——批次的列多半同源，一列有引用通常代表整批的判斷前提有誤。
 *
 * ## 預設不寫入
 *
 * 與 bank: 家族其他寫入型命令一致（rename-bank／dedup-active／dedup-rollback／
 * converge-cross-code／restore-fk-timestamps 皆為 --execute，fix-mismatch 為 --apply）：
 * 不帶 --execute 就只輸出預檢報表。
 *
 * 收益有二，第二項才是主要的：忘記帶旗標的後果變成「什麼都沒做」而非「已經寫下去」；
 * 更重要的是**強制經過一次看報表**——點名的 id 打錯時（659 打成 65），現狀是寫完才
 * 知道，預設預演則會先把 `[停用] id=65 …` 印出來讓人當場發現。這對一支沒有自動
 * rollback 的命令特別重要。
 *
 * ⚠️ 但這道防線擋不住「跑錯站」：`bootstrap/cache/config.php` 存在時 `-e DB_CONNECTION`
 * 注入會失效，而 `mysql` 正好在 confirmExecutionEnvironment() 的放行清單內。那個洞要靠
 * 「寫入前印出實際連線與資料庫名」補（RenameBankCommand 有現成實作）。
 *
 * ## 還原
 *
 * 審計寫 `bank_dedup_logs`（action `deactivate_row`），`detail.was` 記異動前的
 * status／deleted_at，還原即把 was 寫回。無自動 rollback 命令。
 *
 * 用法：
 *   php artisan bank:deactivate-rows --bank=533 --bank=747 --reason="VND 幣別錯置的印尼銀行"
 *   php artisan bank:deactivate-rows --bank=533 --bank=747 --reason="VND 幣別錯置的印尼銀行" --execute --force
 *   php artisan bank:deactivate-rows --bank=241 --bank=216 --scope-refs-to-group --reason="測試組已無通道的舊銀行列"
 *
 * ## 退役條件
 *
 * **不設退役條件。** 「停用一列銀行」是常態營運動作（錯置幣別、廢棄、誤建），
 * 不隨銀行收斂結束而消失；本命令是這件事的共用出口，散在各處自己寫 update 才是問題。
 */
class DeactivateBankRowsCommand extends Command
{
    protected $signature = 'bank:deactivate-rows
                            {--bank=* : 要停用的 banks.id（可指定多個）}
                            {--reason= : 停用理由，寫入審計供日後追溯（必填）}
                            {--allow-inactive-cfb : 放行「僅被停用中的 cash_flow_banks 引用」的列（服務中的引用仍一律阻斷）}
                            {--scope-refs-to-group : 代付清單與金流商銀行對應的引用，只計入本列所屬代理群組中服務中的代付通道（別的群組的同碼引用不阻斷；基底層列不可用）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '停用指定的 banks 列（status=0 與 deleted_at 並寫；有任何引用則整批零寫入）';

    /**
     * banks.id 的其餘引用表（引用面的單一來源＝BankRepairService::FK_TABLES）。
     *
     * ⚠️ 不含 order_images：其 bank_id 指 bank_accounts.id、非 banks.id。
     * 各站 schema 未必一致，查之前先 Schema::hasTable()。
     *
     * @var array<string, string>
     */
    private const SATELLITE_BANK_ID_TABLES = [
        'realname_validation_requests' => '會員實名驗證申請',
        'agent_bank_accounts' => '代理銀行帳戶',
        'agent_real_name_validation_requests' => '代理實名驗證申請',
    ];

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

        if ($bankIds === []) {
            $this->error('--bank 必填：請指定要停用的 banks.id。');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason 必填：停用理由會寫入審計，供日後追溯與反演。');

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

        $allowInactiveCfb = (bool) $this->option('allow-inactive-cfb');
        $scopeToGroup = (bool) $this->option('scope-refs-to-group');

        $this->discloseWriteTarget();

        if ($scopeToGroup) {
            $this->warn('引用範圍：代付清單與金流商銀行對應只計入本列所屬代理群組中服務中的代付通道（--scope-refs-to-group）。');
        }

        ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers]
            = $this->classifyRows(bankIds: $bankIds, allowInactiveCfb: $allowInactiveCfb, scopeToGroup: $scopeToGroup);

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
            $this->info('沒有需要停用的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可停用，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->deactivate(
            bankIds: $bankIds,
            allowInactiveCfb: $allowInactiveCfb,
            scopeToGroup: $scopeToGroup,
            reason: $reason,
        );

        $this->info(sprintf('停用完成：%d 列（審計見 bank_dedup_logs，action=deactivate_row）。', $written));

        return self::SUCCESS;
    }

    /**
     * 逐列分流成「可停用／已處理過／不合格」三類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間
     * 的窗口是分鐘級（非毫秒級），期間他人可能新增綁定或啟用引用。
     *
     * @param  list<int>  $bankIds
     * @return array{targets: list<Bank>, skipped: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $bankIds, bool $allowInactiveCfb, bool $scopeToGroup, bool $lock = false): array
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

            // 已停用且已標刪＝先前處理過，跳過並保住原始收斂時間（不重寫 deleted_at）
            if ((int) $bank->status === 0 && $bank->deleted_at !== null) {
                $skipped[] = sprintf('id=%d「%s」已停用且已標刪', $id, $bank->name);

                continue;
            }

            $reasons = $this->referenceBlockers($bank, $allowInactiveCfb, $scopeToGroup);

            if ($reasons !== []) {
                $blockers[] = sprintf('id=%d「%s」%s', $id, $bank->name, implode('、', $reasons));

                continue;
            }

            $targets[] = $bank;
        }

        return ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers];
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
            $this->line(sprintf('  [停用] id=%d  %s  (%s)', $bank->id, $bank->name, $bank->code));
        }
    }

    /**
     * 引用預檢，回傳阻斷原因（空陣列＝可安全停用）。涵蓋的表見類別 docblock。
     *
     * @return list<string>
     */
    private function referenceBlockers(Bank $bank, bool $allowInactiveCfb = false, bool $scopeToGroup = false): array
    {
        $reasons = [];

        $bindings = DB::table('member_bank_accounts')->where('bank_id', $bank->id)->count();

        if ($bindings > 0) {
            $reasons[] = sprintf('尚有 %d 筆會員綁定', $bindings);
        }

        // 未終態提款單：停用後該單在後續流程查無而卡單。
        // 只看未終態——歷史終態單必然永遠存在，全數計數會讓任何用過的銀行都停不掉。
        $unsettledOrders = DB::table('withdrawal_orders')
            ->where('bank_id', $bank->id)
            ->whereNotIn('status', self::terminalWithdrawalStatuses())
            ->count();

        if ($unsettledOrders > 0) {
            $reasons[] = sprintf('尚有 %d 筆未終態提款單', $unsettledOrders);
        }

        foreach (self::SATELLITE_BANK_ID_TABLES as $table => $label) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $refs = DB::table($table)->where('bank_id', $bank->id)->count();

            if ($refs > 0) {
                $reasons[] = sprintf('尚有 %d 筆%s引用', $refs, $label);
            }
        }

        $groupId = (int) $bank->agent_group_id;
        $hasCurrency = $bank->currency_id !== null;

        if ($scopeToGroup && $groupId === 0) {
            $reasons[] = '基底層列（agent_group_id=0）是各群組複製的模板，引用面本來就是全站，不可縮小引用範圍';
        }

        if ($scopeToGroup && !$hasCurrency) {
            $reasons[] = '幣別為空，無法限定同幣別的引用範圍';
        }

        // 基底層或幣別為空時退回全站範圍計數：阻斷原因已記上，引用數照實回報
        $scoped = $scopeToGroup && $groupId > 0 && $hasCurrency;
        $currencyId = (int) $bank->currency_id;

        $acfRefs = AgentCashFlow::query()
            ->whereNull('deleted_at')
            ->when($scoped, fn (Builder $query): Builder => $this->limitToGroupActiveAcfs($query, $groupId, $currencyId))
            ->whereRaw(
                "JSON_SEARCH(JSON_EXTRACT(info, '$.available_banks[*].BankCode_ELG'), 'one', ?) IS NOT NULL",
                [$bank->code]
            )
            ->count();

        if ($acfRefs > 0) {
            $reasons[] = sprintf(
                '碼「%s」仍被 %d 個%sACF 的 available_banks 引用',
                $bank->code,
                $acfRefs,
                $scoped ? '本群組服務中的 ' : ' '
            );
        }

        $cfbQuery = CashFlowBank::query()
            ->where(function ($q) use ($bank, $scoped, $groupId, $currencyId): void {
                $q->where('bank_id', $bank->id);

                if (!$scoped) {
                    $q->orWhere('bank_code', $bank->code);

                    return;
                }

                // 以碼比對的 cfb 只算本群組真的會查到的：代付查表以
                // (cash_flow_id, currency_id, bank_code) 為鍵，本群組沒接的金流商不會拿本群組訂單的碼去查
                $q->orWhere(function ($byCode) use ($bank, $groupId, $currencyId): void {
                    $byCode->where('bank_code', $bank->code)
                        ->where('currency_id', $currencyId)
                        ->whereIn(
                            'cash_flow_id',
                            $this->limitToGroupActiveAcfs(
                                AgentCashFlow::query()->whereNull('deleted_at'),
                                $groupId,
                                $currencyId
                            )->select('cash_flow_id')
                        );
                });
            });

        // 停用中的 cfb 是死引用：代付查表要求 status=1（見 RenameBankCommand 的死槽阻斷），
        // 那一列本來就查不到，停用 banks 列不改變任何行為。但要明示放行才略過，
        // 預設仍一律阻斷——「引用存在」與「引用有效」是兩件事，不該由命令自行推定。
        if ($allowInactiveCfb) {
            $cfbQuery->where('status', 1);
        }

        $cfbRefs = $cfbQuery->count();

        if ($cfbRefs > 0) {
            $reasons[] = sprintf(
                '尚有 %d 筆%s%scash_flow_banks 引用',
                $cfbRefs,
                $scoped ? '本群組會查到的 ' : '',
                $allowInactiveCfb ? '服務中的 ' : ''
            );
        }

        return $reasons;
    }

    /**
     * 限縮到「本群組、幣別相同或 ACF 幣別為空、服務中」的 ACF（--scope-refs-to-group 的判準）。
     *
     * @param  Builder<AgentCashFlow>  $query
     * @return Builder<AgentCashFlow>
     */
    private function limitToGroupActiveAcfs(Builder $query, int $groupId, int $currencyId): Builder
    {
        return $query
            ->where('agent_group_id', $groupId)
            ->where(static function ($q) use ($currencyId): void {
                $q->where('currency_id', $currencyId)->orWhereNull('currency_id');
            })
            ->where('status', 1);
    }

    /**
     * 於交易內鎖定並重驗後停用，回傳實際寫入列數。
     *
     * @param  list<int>  $bankIds
     */
    private function deactivate(array $bankIds, bool $allowInactiveCfb, bool $scopeToGroup, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $run = 'deact_' . $stamp->format('YmdHis');
        $written = 0;

        DB::transaction(function () use (
            $bankIds,
            $allowInactiveCfb,
            $scopeToGroup,
            $reason,
            $connection,
            $stamp,
            $run,
            &$written
        ): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間可能有人新增綁定或啟用引用。
            // 判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉 TOCTOU 窗口。
            $recheck = $this->classifyRows(
                bankIds: $bankIds,
                allowInactiveCfb: $allowInactiveCfb,
                scopeToGroup: $scopeToGroup,
                lock: true,
            );

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

                // 批次寫入用 query builder：Bank model 無 SoftDeletes，deleted_at 需逐欄精確控制
                DB::table('banks')->where('id', $bank->id)->update([
                    'status' => 0,
                    'updated_at' => $stamp,
                ]);

                // 分開下 deleted_at 並限 whereNull：`status=1` 但 `deleted_at` 非空的矛盾列
                // 是本專案真實存在的形態（VWorld 實測），它不符合上面「已停用且已標刪」的
                // 跳過條件，會一路走到這裡。無條件覆寫會把原始標刪時間刷成當下，讓審計時序
                // 與 updated_at 回推式的誤傷稽核一併失真。沿用 RenameBankCommand 的處置。
                DB::table('banks')
                    ->where('id', $bank->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $stamp]);

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => (int) $bank->agent_group_id,
                    'code' => (string) $bank->code,
                    // 純停用沒有 survivor：自我參照表示「本列即標的」，反演時只看 loser 側
                    'survivor_bank_id' => (int) $bank->id,
                    'loser_bank_id' => (int) $bank->id,
                    'action' => 'deactivate_row',
                    'detail' => $this->encodeAuditDetail([
                        'run' => $run,
                        't' => 'banks',
                        'id' => (int) $bank->id,
                        'was' => [
                            'status' => (int) $before->status,
                            'deleted_at' => $before->deleted_at,
                        ],
                        // deleted_at 只在原本為空時才被寫成本次時戳，非空則保留原值；
                        // 審計的事後值必須等於庫裡的實值，否則反演會把時間寫錯。
                        'aft' => [
                            'status' => 0,
                            'deleted_at' => $before->deleted_at ?? $stamp->toDateTimeString(),
                        ],
                        'reason' => $reason,
                        // 縮小引用範圍等於放寬預檢：審計要能分辨這批是用哪種判準放行的
                        ...($scopeToGroup ? ['refs_scope' => 'group'] : []),
                    ]),
                    'created_at' => $stamp,
                ]);
            }
        });

        return $written;
    }

    /**
     * 提款單終態：這些狀態的單不再流動，指向已停用的銀行列不影響任何流程。
     *
     * ⚠️ 29（三方失敗）刻意不列入——它非終態、可重發。
     *
     * @return list<int>
     */
    private static function terminalWithdrawalStatuses(): array
    {
        return [
            WithdrawalOrderStatusEnum::FAILED->value,
            WithdrawalOrderStatusEnum::TRANSFERRED->value,
            WithdrawalOrderStatusEnum::REFUND->value,
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
        ];
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
     * 阻斷才是最需要核對環境的時刻，「有引用」既可能是真的，也可能只是連錯站，
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
