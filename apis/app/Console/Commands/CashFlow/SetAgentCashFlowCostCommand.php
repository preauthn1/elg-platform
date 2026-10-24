<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\Support\DatabaseEnvironment;
use App\Enums\CashFlowTypeEnum;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 設定金流商戶的成本費率（`agent_cash_flows.cost`），代收與代付商戶都適用。
 *
 * 【分類】CashFlow — 單欄設定，只動 `agent_cash_flows.cost` 一欄。
 *
 * ## cost 是什麼、改了會影響什麼
 *
 * cost 是我方付給金流商的手續費費率（0.005 = 0.5%），不是向會員收的手續費（那是 tax）。
 * 它決定兩個數字，改之前必須知道：
 *
 * - 訂單的支付商手續費（`orders.payment_cost`、`withdrawal_orders.payment_cost`）：入帳或派款成功那一刻以
 *   「站點主幣別金額 × cost」寫入並凍結（`AgentCashFlow::paymentCostFor()`）。代理數據看板的
 *   「支付手續費」「提款手續費」加總的就是這個凍結值。
 * - 分潤（佔成）的支付成本：`SP_calc_profit_share_payment_cost` 每天以「點數加總 × 當下的 cost」現算，
 *   不讀訂單上的凍結值。
 *
 * 所以改 cost 只影響**之後**入帳的訂單；已入帳訂單的 `payment_cost` 不會變（要補走 `order:backfill-payment-cost`，
 * 它只補值為 0 的列）。分潤則從下一次計算起就用新費率，重算歷史日期時也是用新費率。
 *
 * ## 為什麼既有命令做不到
 *
 * `agent:add-agent-cash-flow` 只在「新建商戶」與「單筆商戶遷移成拆分形式」時寫 cost；既有的拆分商戶重跑時
 * 刻意保留營運值不覆寫，單筆模式與代付商戶則完全不寫 cost。
 *
 * ## 預檢規則（任一配對不合格，整批零寫入）
 *
 * - 商戶必須存在且未軟刪除。已刪除的商戶不再服務，對它設 cost 多半是 id 指錯了。
 * - 費率必須是 0 到 1 之間的數字，最多 4 位小數（欄位是 decimal(10,4)，多的位數會被資料庫截掉）。
 * - 費率大於 0.1（10%）視為疑似填錯小數點，必須另加 `--allow-high-cost` 才放行。
 * - 現值已等於目標值的商戶列為「略過」，不寫入也不備份，同一批配對可以安全重跑。
 *
 * ## 備份與還原
 *
 * 寫入前必須先以 TableBackupService 備份會被改到的商戶列（`agent_cash_flows_bak_<時間戳>`），備份失敗即中止、
 * 不寫入。理由（--reason）記在備份的 manifest 與 log。還原用：
 *
 *   php artisan db:backup-rollback --stamp=<時間戳> --table=agent_cash_flows --ids=<商戶 id>
 *
 * 寫入目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各站的商戶 id 區間重疊；報表第一行會印出
 * 連線名與資料庫名，執行前必須核對。
 *
 * 使用範例：
 *   php artisan cash-flow:set-cost --pair=450:0.005 --reason="CCPayment 代收服務費 0.5%"
 *   php artisan cash-flow:set-cost --pair=450:0.005 --pair=446:0.005 --reason="..." --execute
 *
 * @knowledge rules/payment-channel-management
 */
class SetAgentCashFlowCostCommand extends Command
{
    protected $signature = 'cash-flow:set-cost
                            {--pair=* : 設定配對，格式 <agent_cash_flows.id>:<費率>（可指定多個；0.005 = 0.5%）}
                            {--reason= : 設定理由，寫入備份 manifest 與 log 供日後追溯（必填）}
                            {--allow-high-cost : 放行大於 0.1（10%）的費率}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '設定金流商戶的成本費率 cost（任一配對不合格則整批零寫入；寫入前先備份）';

    /** 與 agent_cash_flows.cost 的 decimal(10,4) 一致 */
    private const COST_SCALE = 4;

    /** 費率上限：1 = 100% */
    private const COST_MAX = '1';

    /** 超過這個費率視為疑似填錯，須加 --allow-high-cost */
    private const HIGH_COST_THRESHOLD = '0.1';

    private const REASON_MAX_LENGTH = 500;

    private const TABLE = 'agent_cash_flows';

    public function handle(TableBackupService $backup): int
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
            $this->info('沒有需要設定的商戶。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 個商戶可設定，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $stamp = $this->captureBackup($backup, $targets, $reason);

        if ($stamp === null) {
            return self::FAILURE;
        }

        try {
            $written = $this->apply($pairs);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::info('cash-flow:set-cost applied', [
            'connection' => (string) config('database.default'),
            'backup_stamp' => $stamp,
            'reason' => $reason,
            'changes' => $written,
        ]);

        $this->info(sprintf('設定完成：%d 個商戶。', count($written)));
        $this->line(sprintf(
            '還原：php artisan db:backup-rollback --stamp=%s --table=%s --ids=%s',
            $stamp,
            self::TABLE,
            implode(',', array_column($written, 'id'))
        ));

        return self::SUCCESS;
    }

    /**
     * 解析 --pair；格式不合、費率超出範圍、同一商戶重複指定時印錯並回 null。
     *
     * @return list<array{id: int, cost: string}>|null cost 已正規化成 4 位小數的字串
     */
    private function parsePairs(): ?array
    {
        $rawPairs = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('pair'));

        if ($rawPairs === []) {
            $this->error('--pair 必填：格式 <agent_cash_flows.id>:<費率>，可重複指定（--pair=450:0.005 --pair=446:0.005）。');

            return null;
        }

        $allowHigh = (bool) $this->option('allow-high-cost');
        $pairs = [];
        $errors = [];

        foreach ($rawPairs as $raw) {
            // 費率只收純十進位寫法：科學記號、正負號、超過 4 位小數都不收，避免被資料庫靜默截斷或誤解
            if (preg_match('/^(\d+):(\d+(?:\.\d{1,' . self::COST_SCALE . '})?)$/', $raw, $m) !== 1) {
                $errors[] = sprintf('「%s」格式須為 <agent_cash_flows.id>:<費率>，費率最多 %d 位小數', $raw, self::COST_SCALE);

                continue;
            }

            $id = (int) $m[1];
            $cost = Common::numAdd('0', $m[2], self::COST_SCALE);

            if (Common::numCompare($cost, self::COST_MAX, self::COST_SCALE) > 0) {
                $errors[] = sprintf('id=%d 的費率 %s 超過 %s（費率 1 = 100%%）', $id, $cost, self::COST_MAX);

                continue;
            }

            if (!$allowHigh && Common::numCompare($cost, self::HIGH_COST_THRESHOLD, self::COST_SCALE) > 0) {
                $errors[] = sprintf(
                    'id=%d 的費率 %s 大於 %s，疑似填錯小數點；確定無誤請加 --allow-high-cost',
                    $id,
                    $cost,
                    self::HIGH_COST_THRESHOLD
                );

                continue;
            }

            if (isset($pairs[$id])) {
                $errors[] = sprintf('id=%d 重複指定', $id);

                continue;
            }

            $pairs[$id] = ['id' => $id, 'cost' => $cost];
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
            $this->error('--reason 必填：設定理由會寫入備份 manifest 與 log，供日後追溯。');

            return null;
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            $this->error('--reason 含無效的 UTF-8 位元組，備份 manifest 無法編碼。');

            return null;
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $this->error(sprintf('--reason 超過 %d 字元，請精簡。', self::REASON_MAX_LENGTH));

            return null;
        }

        return $reason;
    }

    /**
     * 逐配對分流成「可設定／已是目標值／不合格」三類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間的窗口是分鐘級，
     * 期間他人可能改了 cost 或刪了商戶。
     *
     * @param  list<array{id: int, cost: string}>  $pairs
     * @return array{
     *     targets: list<array{acf: AgentCashFlow, was: string, cost: string}>,
     *     skipped: list<AgentCashFlow>,
     *     blockers: list<string>
     * }
     */
    private function classifyRows(array $pairs, bool $lock = false): array
    {
        $ids = array_map(static fn (array $p): int => $p['id'], $pairs);

        // 含已軟刪除的列：要能分辨「不存在」與「已刪除」
        $query = AgentCashFlow::withTrashed()->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get()->keyBy('id');

        $targets = [];
        $skipped = [];
        $blockers = [];

        foreach ($pairs as $pair) {
            $acf = $rows->get($pair['id']);

            if ($acf === null) {
                $blockers[] = sprintf('id=%d 不存在', $pair['id']);

                continue;
            }

            if ($acf->deleted_at !== null) {
                $blockers[] = sprintf('id=%d「%s」已軟刪除（deleted_at=%s），不再服務', $pair['id'], (string) $acf->name, (string) $acf->deleted_at);

                continue;
            }

            $was = Common::numAdd('0', (string) ($acf->cost ?? '0'), self::COST_SCALE);

            if (Common::numCompare($was, $pair['cost'], self::COST_SCALE) === 0) {
                $skipped[] = $acf;

                continue;
            }

            $targets[] = ['acf' => $acf, 'was' => $was, 'cost' => $pair['cost']];
        }

        return ['targets' => $targets, 'skipped' => $skipped, 'blockers' => $blockers];
    }

    /**
     * @param  list<array{acf: AgentCashFlow, was: string, cost: string}>  $targets
     * @param  list<AgentCashFlow>  $skipped
     */
    private function reportPlan(array $targets, array $skipped): void
    {
        foreach ($targets as $target) {
            $acf = $target['acf'];
            $this->line(sprintf(
                '  [設定] id=%d  %s  支付組別 %d  幣別 %d  狀態 %d  「%s」  cost %s → %s',
                (int) $acf->id,
                $this->typeLabel($acf),
                (int) $acf->agent_group_id,
                (int) $acf->currency_id,
                (int) $acf->status,
                (string) $acf->name,
                $target['was'],
                $target['cost']
            ));
        }

        foreach ($skipped as $acf) {
            $this->line(sprintf(
                '  [略過] id=%d  %s  「%s」  cost 已是 %s',
                (int) $acf->id,
                $this->typeLabel($acf),
                (string) $acf->name,
                Common::numAdd('0', (string) ($acf->cost ?? '0'), self::COST_SCALE)
            ));
        }
    }

    /** 支付類別的顯示名稱；cash_flow_type 尚未同步（NULL）或未知值時原樣標出，不猜。 */
    private function typeLabel(AgentCashFlow $acf): string
    {
        return match (CashFlowTypeEnum::tryFrom((int) $acf->cash_flow_type)) {
            CashFlowTypeEnum::DEPOSIT => '代收',
            CashFlowTypeEnum::AUTO_DEPOSIT => '自助開分',
            CashFlowTypeEnum::WITHDRAW => '代付',
            CashFlowTypeEnum::AUTO_WITHDRAW => '自助洗分',
            default => sprintf('類別未知(%s)', $acf->cash_flow_type === null ? 'NULL' : (string) $acf->cash_flow_type),
        };
    }

    /**
     * 寫入前備份會被改到的商戶列；備份失敗印錯並回 null，呼叫端必須中止。
     *
     * 備份是 DDL（CREATE TABLE），會隱式 commit，所以排在寫入交易之外、之前。
     *
     * @param  list<array{acf: AgentCashFlow, was: string, cost: string}>  $targets
     */
    private function captureBackup(TableBackupService $backup, array $targets, string $reason): ?string
    {
        $ids = array_map(static fn (array $t): int => (int) $t['acf']->id, $targets);

        try {
            $captured = $backup->capture(
                fullTables: [],
                scopedTables: [self::TABLE => ['column' => 'id', 'ids' => $ids]],
                meta: [
                    'caller' => 'cash-flow:set-cost',
                    'reason' => $reason,
                    'changes' => array_map(static fn (array $t): array => [
                        'id' => (int) $t['acf']->id,
                        'was' => $t['was'],
                        'cost' => $t['cost'],
                    ], $targets),
                ],
            );
        } catch (Throwable $e) {
            $this->error('備份失敗，未寫入任何資料：' . $e->getMessage());

            return null;
        }

        $rows = (int) ($captured['tables'][self::TABLE]['rows'] ?? 0);

        if ($rows !== count($ids)) {
            $this->error(sprintf('備份列數 %d 與預計設定的商戶數 %d 不符，未寫入任何資料。', $rows, count($ids)));

            return null;
        }

        $this->line(sprintf('已備份 %d 列到 %s_bak_%s', $rows, self::TABLE, $captured['stamp']));

        return $captured['stamp'];
    }

    /**
     * 交易內鎖定讀重驗後寫入，只更新 cost 一欄。
     *
     * @param  list<array{id: int, cost: string}>  $pairs
     * @return list<array{id: int, was: string, cost: string}>
     *
     * @throws RuntimeException 鎖定後重驗未通過（整批回滾）
     */
    private function apply(array $pairs): array
    {
        return DB::transaction(function () use ($pairs): array {
            // 鎖後重驗：判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉預檢到寫入之間的窗口
            $recheck = $this->classifyRows($pairs, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new RuntimeException('鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers']));
            }

            $written = [];

            foreach ($recheck['targets'] as $target) {
                $target['acf']->update(['cost' => $target['cost']]);
                $written[] = ['id' => (int) $target['acf']->id, 'was' => $target['was'], 'cost' => $target['cost']];
            }

            return $written;
        });
    }

    /** 印出本次的寫入目標連線與資料庫名；呼叫點必須排在所有業務判定之前，預檢阻斷時也看得到目標。 */
    private function discloseWriteTarget(): void
    {
        $this->line(sprintf('寫入目標 connection：%s', (string) config('database.default')));
        $this->line(sprintf('寫入目標 database：%s', (string) DB::connection()->getDatabaseName()));
    }

    private function confirmExecutionEnvironment(): bool
    {
        if (!DatabaseEnvironment::isProductionConnection()) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', (string) config('database.default')));

        return $this->confirm('確定要在此環境執行？', false);
    }
}
