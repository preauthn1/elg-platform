<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SchemaOperationService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * 資料表結構維運（純調度殼，業務邏輯在 SchemaOperationService）
 *
 * 加索引一律建 {table}_mirror 鏡像表、不分表大小（直接 ALTER 在 primary 不可中斷、在 replica 整句重跑造成
 * 分鐘級落後，見 SchemaOperationService 類別說明）：trigger 同步新資料、EVENT 每 5 秒分段回填舊資料；
 * 全量回填完成（done=1、min_id=0）後以 --action=rename 原子換表轉正。加欄位走 ALGORITHM=INSTANT，離峰執行。
 *
 * php artisan schema_operation --action=index --table=fumasw_bet_records --column=payout_time --dry-run
 * php artisan schema_operation --action=index --table=fumasw_bet_records --column=payout_time --min-id=85000000
 * php artisan schema_operation --action=column --table=fumasw_bet_records --name=remark --type="VARCHAR(100)" --nullable
 * php artisan schema_operation --action=rename --table=fumasw_bet_records
 * php artisan schema_operation --action=status
 *
 * 人工執行版 runbook（含拆除與逐步驗證）：docs/reconcile/payout-time-鏡像表建置.md
 */
class SchemaOperationCommand extends Command
{
    protected $signature = 'schema_operation
        {--action= : index＝加索引（建鏡像表）、column＝加欄位（INSTANT；metadata 瞬間但大 buffer pool 站台實測全庫停頓 20 秒級，離峰執行）、rename＝全量回填後原子換表轉正、cancel＝取消回填並拆除（EVENT／trigger／鏡像表）、drop_retired＝刪除換表後留下的退場表 {原表}_YYYYMMDD、status＝查看回填進度}
        {--table= : 目標資料表（status 可留空＝全部）}
        {--column= : action=index：要建索引的欄位}
        {--min-id=0 : action=index：回填下限 id（不含），0=補整張表}
        {--chunk=20000 : action=index：每段回填筆數（胖列建議 8000）}
        {--name= : action=column：新欄位名稱}
        {--type= : action=column：欄位型別，如 VARCHAR(100)、INT UNSIGNED、DECIMAL(18,4)、DATETIME、JSON}
        {--nullable : action=column：允許 NULL}
        {--default= : action=column：預設值（字面值）}
        {--comment= : action=column：欄位註解}
        {--dry-run : 只列出將執行的 SQL，不執行}';

    protected $description = '資料表結構維運：加索引（建 {table}_mirror 鏡像表＋trigger＋EVENT 回填）或加欄位（ALGORITHM=INSTANT，離峰執行）';

    public function __construct(private readonly SchemaOperationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->option('action');
        $table = (string) $this->option('table');

        if ($action === 'status') {
            try {
                foreach ($this->service->statusReport($table !== '' ? $table : null) as $line) {
                    $this->line($line);
                }
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        try {
            $statements = match ($action) {
                'index' => $this->service->planMirrorIndex(
                    table: $table,
                    column: (string) $this->option('column'),
                    minId: (int) $this->option('min-id'),
                    chunk: (int) $this->option('chunk'),
                ),
                'column' => $this->service->planAddColumn(
                    table: $table,
                    name: (string) $this->option('name'),
                    type: (string) $this->option('type'),
                    nullable: (bool) $this->option('nullable'),
                    default: $this->stringOptionOrNull('default'),
                    comment: $this->stringOptionOrNull('comment'),
                ),
                'rename' => $this->service->planRename($table),
                'cancel' => $this->service->planCancel($table),
                'drop_retired' => $this->service->planDropRetired($table),
                default => throw new InvalidArgumentException('--action 必須是 index、column、rename、cancel、drop_retired 或 status'),
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($action === 'rename') {
            $notice = $this->service->partialBackfillNotice($table);

            if ($notice !== null) {
                $this->warn('⚠ ' . $notice);
            }
        }

        // 帶級聯動作或被指向的外鍵已在 plan 階段硬擋；剩下 RESTRICT／NO ACTION 的對外外鍵
        // 會因 LIKE 建的鏡像表不帶外鍵而在換表後消失（刻意，但要讓操作者知道），建鏡像與換表都提醒
        if ($action === 'rename' || $action === 'index') {
            $foreignKeys = $this->service->outgoingForeignKeys($table);

            if ($foreignKeys !== []) {
                $this->warn('⚠ 換表後原表的外鍵不會存在於新表：' . implode(', ', $foreignKeys));
            }
        }

        $this->info(sprintf('目標：%s／動作：%s，共 %d 句 SQL：', $table, $action, count($statements)));

        foreach ($statements as $sql) {
            $this->line($sql . ';');
            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->info('乾跑模式：未執行任何 SQL。確認無誤後拿掉 --dry-run 執行。');

            return self::SUCCESS;
        }

        try {
            $done = 0;
            $this->service->execute($statements, function (string $sql, float $seconds, ?array $stages) use (&$done, $statements): void {
                $this->reportExecuted(++$done, count($statements), $seconds, $stages);
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printFollowUp($action, $table, $statements);

        return self::SUCCESS;
    }

    /**
     * 每句執行完的進度：秒數 ＋ 10ms 以上的階段（最多 8 個），時間花在哪一眼看完。
     *
     * @param  list<array{stage: string, seconds: float}>|null  $stages  null＝連線帳號無 performance_schema 權限
     */
    private function reportExecuted(int $done, int $total, float $seconds, ?array $stages): void
    {
        $this->info(sprintf('已執行 %d / %d（%.2f 秒）', $done, $total, $seconds));

        if ($stages === null) {
            $this->line('  （連線帳號無 performance_schema 權限，未取得階段耗時）');

            return;
        }

        $visible = array_values(array_filter($stages, static fn (array $s): bool => $s['seconds'] >= 0.01));

        foreach (array_slice($visible, 0, 8) as $stage) {
            $this->line(sprintf('  ├ %-60s %8.3f 秒', $stage['stage'], $stage['seconds']));
        }
    }

    /**
     * 執行成功後依動作提示後續步驟。
     *
     * @param  list<string>  $statements
     */
    private function printFollowUp(string $action, string $table, array $statements): void
    {
        if ($action === 'index') {
            $this->newLine();
            $this->info('鏡像表建置完成，EVENT 已開始每 5 秒回填。後續：');
            $this->line(sprintf('1. 監看進度：schema_operation --action=status --table=%s', $table));
            $this->line('2. done = 1 前鏡像表資料不完整，先別拿來對帳。');
            $this->line('3. done = 1 後跑 --action=rename 原子換表轉正。部分回填（min_id > 0）也可轉正，但 id <= min_id 的舊資料只會留在退場表，乾跑時會列出筆數。');
        }

        if ($action === 'cancel') {
            $this->newLine();
            $this->info(sprintf('%s 的鏡像建置已拆除（EVENT／trigger／鏡像表／進度列），已回填資料一併刪除，可重新發起。', $table));
        }

        if ($action === 'rename') {
            $this->newLine();
            $this->info(sprintf('已原子換表：%s 現在就是帶索引的新表，查詢照舊。', $table));
            $this->line(sprintf('原表退場為 %s_%s（EVENT／trigger／進度列已一併清除），觀察穩定後以 --action=drop_retired 刪除。', $table, now()->format('Ymd')));
        }

        if ($action === 'drop_retired') {
            $this->newLine();
            $this->info(sprintf('退場表 %s 已刪除。', $table));
        }
    }

    private function stringOptionOrNull(string $key): ?string
    {
        $value = $this->option($key);

        return $value === null ? null : (string) $value;
    }
}
