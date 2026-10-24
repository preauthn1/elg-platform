<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\StatusEnum;
use App\Exceptions\BankConvergenceAbortedException;
use App\Models\Bank;
use App\Models\BankName;
use App\Models\MemberBankAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 把指定 banks 列的 `name`（會員在提款銀行清單看到的名稱）改成另一個名稱。
 *
 * 【分類】Bank — 單列改名，只動 `banks.name` 一欄。
 *
 * ## 為什麼既有命令做不到
 *
 * - `bank:fix-mismatch` 以 `Data/banks/{幣別}.php` 的標準名為準，只涵蓋有標準銀行主檔的幣別；
 *   加密貨幣（`type` 3，例如 USDT）不屬銀行主檔範疇，不在它的範圍內。
 * - `bank:rename-bank` 是把一列併進另一列（搬綁定、停用被併的列），不是改名。
 * - `bank:recode-bank` 改的是 `code`，不動名稱。
 *
 * ## base 層與支付組別層要一起改
 *
 * 建代付金流商戶時，`AgentBankSyncService::syncToAgentGroup()` 以 base 層（`agent_group_id` 0）的
 * 同碼列為模板整列覆寫支付組別層，名稱取的是 base 層的值。只改支付組別層，下一次建商戶就被
 * 蓋回，所以統一名稱必須連 base 層一起改；本命令因此不限制層別。
 *
 * base 層名稱也是 `cash-flow:sync` 找平台銀行的依據（`findPlatformBankByName()`：先逐字比對，
 * 比不到再以去掉標點與幣別字的名稱模糊比對）。改了 base 層名稱，資料檔
 * （`Data/providers/*.php`）的銀行名稱也要改成同一個，才能維持逐字命中。
 *
 * ## 預檢規則（任一配對不合格，整批零寫入）
 *
 * - 列必須存在且未標刪。`deleted_at` 有值的列已不服務，對它改名多半是 id 指錯了。
 * - 同支付組別＋同類型＋同幣別內，不得有另一個服務中的列已經叫這個名稱，同一批配對之間也
 *   不得改成同一個名稱：支付組別層會讓會員看到兩個同名選項；base 層會讓 sync 找平台銀行時
 *   命中哪一列變得不確定。同名判定不分大小寫：只差大小寫的兩個名稱在會員畫面上幾乎一樣。
 * - 現有名稱已**逐字**等於目標名稱的列列為「略過」，不寫入也不留審計，同一批配對可以安全重跑；
 *   只差大小寫的仍會改名。
 *
 * ## 不動的東西
 *
 * - 歷史提款單的 `withdrawal_orders.bank_name` 是下單當下的快照，不改寫。
 * - 多語名稱 `bank_names` 不改；該列有多語名稱時報表會提示，會員端可能優先顯示多語名稱。
 * - `code`、手續費、限額、狀態等其他欄位一律不動。
 *
 * ## 還原
 *
 * 無 rollback 命令。審計寫在 `bank_dedup_logs`（`action=set_bank_name`；`bank:dedup-rollback`
 * 不處理這個動作），detail 的 `was.name` 是原名稱，還原時用本命令把名稱改回 `was.name`。
 *
 * @knowledge rules/standard-bank-list
 */
class SetBankNameCommand extends Command
{
    protected $signature = 'bank:set-name
                            {--pair=* : 改名配對，格式 <banks.id>:<新名稱>（可指定多個；名稱含空白時整個參數加引號）}
                            {--reason= : 改名理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '把指定 banks 列的 name 改成另一個名稱（任一配對不合格則整批零寫入）';

    /** 審計動作名稱；不可與 bank:dedup-rollback 會回放的動作重名。 */
    private const AUDIT_ACTION = 'set_bank_name';

    /** 與 banks.name 欄位長度 varchar(100) 一致。 */
    private const NAME_MAX_LENGTH = 100;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    public function handle(): int
    {
        $pairs = $this->parsePairs();

        if ($pairs === null) {
            return self::FAILURE;
        }

        $reason = $this->parseReason();

        if ($reason === null) {
            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers] = $this->classifyRows($pairs);

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
            $this->info('沒有需要改名的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 列可改名，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($pairs, $reason);

        $this->info(sprintf('改名完成：%d 列（審計見 bank_dedup_logs，action=%s）。', $written, self::AUDIT_ACTION));

        return self::SUCCESS;
    }

    /**
     * 解析 --pair；格式不合、名稱為空或過長、同一列重複指定時印錯並回 null。
     *
     * 以第一個冒號切開，名稱本身可以含冒號、空白與連字號。
     *
     * @return list<array{id: int, name: string}>|null
     */
    private function parsePairs(): ?array
    {
        $rawPairs = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('pair'));

        if ($rawPairs === []) {
            $this->error('--pair 必填：格式 <banks.id>:<新名稱>，可重複指定（--pair="1369:USDT - TRC20" --pair="485:USDT - TRC20"）。');

            return null;
        }

        $pairs = [];
        $errors = [];

        foreach ($rawPairs as $raw) {
            if (preg_match('/^(\d+):(.+)$/su', $raw, $m) !== 1) {
                $errors[] = sprintf('「%s」格式須為 <banks.id>:<新名稱>', $raw);

                continue;
            }

            $id = (int) $m[1];
            $name = trim($m[2]);

            if ($name === '') {
                $errors[] = sprintf('id=%d 的新名稱是空白', $id);

                continue;
            }

            if (!mb_check_encoding($name, 'UTF-8')) {
                $errors[] = sprintf('id=%d 的新名稱含無效的 UTF-8 位元組', $id);

                continue;
            }

            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $errors[] = sprintf('id=%d 的新名稱超過 %d 字元', $id, self::NAME_MAX_LENGTH);

                continue;
            }

            if (isset($pairs[$id])) {
                $errors[] = sprintf('id=%d 重複指定', $id);

                continue;
            }

            $pairs[$id] = ['id' => $id, 'name' => $name];
        }

        if ($errors !== []) {
            $this->error('--pair 不合格：' . implode('；', $errors) . '。');

            return null;
        }

        return array_values($pairs);
    }

    private function parseReason(): ?string
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason 必填：改名理由會寫入審計，供日後追溯與還原。');

            return null;
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            $this->error('--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入不可逆——寧可先擋下。');

            return null;
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $this->error(sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH));

            return null;
        }

        return $reason;
    }

    /**
     * 逐配對分流成「可改名／已是目標名稱／不合格」三類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間的
     * 窗口是分鐘級，期間他人可能改了名稱或建立同名的服務中列。
     *
     * @param  list<array{id: int, name: string}>  $pairs
     * @return array{
     *     targets: list<array{bank: Bank, name: string, bindings: int, localized_names: int}>,
     *     skipped: list<Bank>,
     *     blockers: list<string>
     * }
     */
    private function classifyRows(array $pairs, bool $lock = false): array
    {
        $ids = array_map(static fn (array $p): int => $p['id'], $pairs);
        $newNames = array_values(array_unique(array_map(static fn (array $p): string => $p['name'], $pairs)));

        $query = Bank::query()->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        $banks = $query->get()->keyBy('id');

        // 同名候選一次撈齊：whereIn 走不分大小寫的 collation；範疇與大小寫的判定在下方 PHP 端做
        $namesakes = Bank::query()
            ->whereIn('name', $newNames)
            ->where('status', StatusEnum::ENABLE->value)
            ->whereNull('deleted_at')
            ->get(['id', 'agent_group_id', 'type', 'currency_id', 'name']);

        $bindings = MemberBankAccount::query()
            ->whereIn('bank_id', $ids)
            ->selectRaw('bank_id, COUNT(*) AS total')
            ->groupBy('bank_id')
            ->pluck('total', 'bank_id');

        $localizedNames = BankName::query()
            ->whereIn('bank_id', $ids)
            ->selectRaw('bank_id, COUNT(*) AS total')
            ->groupBy('bank_id')
            ->pluck('total', 'bank_id');

        $targets = [];
        $skipped = [];
        $blockers = [];

        foreach ($pairs as $pair) {
            $bank = $banks->get($pair['id']);

            if ($bank === null) {
                $blockers[] = sprintf('id=%d 不存在', $pair['id']);

                continue;
            }

            if ($bank->deleted_at !== null) {
                $blockers[] = sprintf('id=%d「%s」已標刪（deleted_at=%s），不再服務', $pair['id'], (string) $bank->name, (string) $bank->deleted_at);

                continue;
            }

            if ((string) $bank->name === $pair['name']) {
                $skipped[] = $bank;

                continue;
            }

            $collision = $namesakes->first(static fn (Bank $row): bool => (int) $row->id !== (int) $bank->id
                && (int) $row->agent_group_id === (int) $bank->agent_group_id
                && (int) $row->type === (int) $bank->type
                && (int) $row->currency_id === (int) $bank->currency_id
                && self::sameName((string) $row->name, $pair['name']));

            if ($collision !== null) {
                $blockers[] = sprintf(
                    'id=%d 改名為「%s」會與服務中的 id=%d 同名（同支付組別＋類型＋幣別）',
                    $pair['id'],
                    $pair['name'],
                    (int) $collision->id
                );

                continue;
            }

            $targets[] = [
                'bank' => $bank,
                'name' => $pair['name'],
                'bindings' => (int) ($bindings[$bank->id] ?? 0),
                'localized_names' => (int) ($localizedNames[$bank->id] ?? 0),
            ];
        }

        return [
            'targets' => $targets,
            'skipped' => $skipped,
            'blockers' => [...$blockers, ...$this->findBatchCollisions($targets)],
        ];
    }

    /**
     * 同一批配對之間改成同一個名稱（同支付組別＋類型＋幣別）也算撞名。
     *
     * @param  list<array{bank: Bank, name: string, bindings: int, localized_names: int}>  $targets
     * @return list<string>
     */
    private function findBatchCollisions(array $targets): array
    {
        $groups = [];

        foreach ($targets as $target) {
            $bank = $target['bank'];
            $key = implode('|', [(int) $bank->agent_group_id, (int) $bank->type, (int) $bank->currency_id, mb_strtolower($target['name'])]);
            $groups[$key]['name'] ??= $target['name'];
            $groups[$key]['ids'][] = (int) $bank->id;
        }

        $blockers = [];

        foreach ($groups as $group) {
            if (count($group['ids']) > 1) {
                $blockers[] = sprintf(
                    'id=%s 在同支付組別＋類型＋幣別內都要改成「%s」，改完會同名',
                    implode(', ', $group['ids']),
                    $group['name']
                );
            }
        }

        return $blockers;
    }

    /** 同名判定不分大小寫（理由見類別註解「預檢規則」）。 */
    private static function sameName(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }

    /**
     * @param  list<array{bank: Bank, name: string, bindings: int, localized_names: int}>  $targets
     * @param  list<Bank>  $skipped
     */
    private function reportPlan(array $targets, array $skipped): void
    {
        foreach ($targets as $target) {
            $bank = $target['bank'];
            $this->line(sprintf(
                '  [改名] id=%d 支付組別 %d  類型 %d  幣別 %d  code=%s  狀態 %d  綁定 %d  「%s」→「%s」',
                (int) $bank->id,
                (int) $bank->agent_group_id,
                (int) $bank->type,
                (int) $bank->currency_id,
                (string) $bank->code,
                (int) $bank->status,
                $target['bindings'],
                (string) $bank->name,
                $target['name']
            ));

            if ($target['localized_names'] > 0) {
                $this->warn(sprintf(
                    '         ⚠️  本列有 %d 筆多語名稱（bank_names），本命令不改；會員端可能優先顯示多語名稱',
                    $target['localized_names']
                ));
            }
        }

        foreach ($skipped as $bank) {
            $this->line(sprintf('  [略過] id=%d 名稱已是「%s」', (int) $bank->id, (string) $bank->name));
        }
    }

    /**
     * @param  list<array{id: int, name: string}>  $pairs
     */
    private function apply(array $pairs, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $run = 'set_name_' . $stamp->format('YmdHis');
        $written = 0;

        DB::transaction(function () use ($pairs, $reason, $connection, $stamp, $run, &$written): void {
            // 鎖後重驗：判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉預檢到寫入之間的窗口
            $recheck = $this->classifyRows($pairs, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $target) {
                $bank = $target['bank'];
                $oldName = (string) $bank->name;

                $bank->update(['name' => $target['name']]);
                $written++;

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => (int) $bank->agent_group_id,
                    'code' => (string) $bank->code,
                    // 單列改名沒有 survivor／loser 之分：自我參照表示「本列即標的」
                    'survivor_bank_id' => (int) $bank->id,
                    'loser_bank_id' => (int) $bank->id,
                    'action' => self::AUDIT_ACTION,
                    'detail' => $this->encodeAuditDetail([
                        'run' => $run,
                        't' => 'banks',
                        'id' => (int) $bank->id,
                        'was' => ['name' => $oldName],
                        'aft' => ['name' => $target['name']],
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 `banks.id` 區間重疊
     * （TPower 與 VWorld 庫同一個 id 可能是完全不同的銀行），連錯站時預檢照樣可能全綠。
     * 呼叫點必須排在所有業務判定之前，預檢阻斷時也看得到目標。
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
