<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Models\AgentOperateGeneralLog;
use App\Models\AgentOperateLog;
use App\Services\Agent\AgentOperateLogService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 清除代理操作紀錄、請求稽核紀錄裡已存下的登入憑證（TOKEN、密碼、支付密碼、2FA 驗證碼…）。
 *
 * 剔除規則與寫入端共用 AgentOperateLogService::stripCredentials()；只改 JSON 欄位，時間等其他欄位不動。
 */
class StripAgentOperateLogCredentials extends Command
{
    protected $signature = 'fix:strip-agent-operate-log-credentials
        {--dry-run : 只統計會被清理的筆數與欄位，不寫入資料庫}
        {--table=all : agent_operate_logs、agent_operate_general_logs 或 all}
        {--from-id=1 : 起始 id（中斷後可用於續跑）}
        {--to-id= : 結束 id（預設各表最大 id）}
        {--window-size=1000 : 每個 id 視窗寬度}
        {--sleep-ms=0 : 每個視窗間休眠毫秒數，降低大表 DB 壓力}';

    protected $description = '清除 agent_operate_logs.debug_info 與 agent_operate_general_logs.content 內已存下的登入憑證';

    /** 表名 => [Model, JSON 欄位] */
    private const array TABLES = [
        'agent_operate_logs' => [AgentOperateLog::class, 'debug_info'],
        'agent_operate_general_logs' => [AgentOperateGeneralLog::class, 'content'],
    ];

    public function handle(AgentOperateLogService $service): int
    {
        $table = (string) $this->option('table');
        $tables = $table === 'all' ? array_keys(self::TABLES) : [$table];

        if (array_diff($tables, array_keys(self::TABLES)) !== []) {
            $this->error('--table 只接受 agent_operate_logs、agent_operate_general_logs 或 all');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('⚠️  DRY RUN 模式：不會修改資料庫');
        }

        foreach ($tables as $name) {
            [$modelClass, $column] = self::TABLES[$name];
            $this->processTable($service, $name, $modelClass, $column, $isDryRun);
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function processTable(AgentOperateLogService $service, string $name, string $modelClass, string $column, bool $isDryRun): void
    {
        $fromId = max(1, (int) $this->option('from-id'));
        $toIdOption = $this->option('to-id');
        $maxId = ($toIdOption === null || $toIdOption === '')
            ? (int) ($modelClass::query()->max('id') ?? 0)
            : (int) $toIdOption;
        $windowSize = max(1, (int) $this->option('window-size'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $this->info(sprintf('%s：處理 id 範圍 [%d..%d]', $name, $fromId, $maxId));

        $changed = 0;
        /** @var array<string, int> $removedKeys 只統計鍵名，不輸出值 */
        $removedKeys = [];

        for ($start = $fromId; $start <= $maxId; $start += $windowSize) {
            $end = min($start + $windowSize - 1, $maxId);

            $rows = $modelClass::query()->toBase()
                ->whereBetween('id', [$start, $end])
                ->where($column, 'like', '{%')
                ->get(['id', $column, 'updated_at']);

            $updates = [];
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->{$column}, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $stripped = $service->stripCredentials($decoded);
                if ($stripped === $decoded) {
                    continue;
                }

                foreach ($this->removedPaths($decoded, $stripped) as $path) {
                    $removedKeys[$path] = ($removedKeys[$path] ?? 0) + 1;
                }
                $updates[(int) $row->id] = [$column => (string) json_encode($stripped), 'updated_at' => $row->updated_at];
            }

            // updated_at 是 ON UPDATE CURRENT_TIMESTAMP，明寫回原值才不會被改成清理當下
            if (!$isDryRun && $updates !== []) {
                DB::transaction(function () use ($modelClass, $updates): void {
                    foreach ($updates as $id => $values) {
                        $modelClass::query()->toBase()->where('id', $id)->update($values);
                    }
                });
            }

            $changed += count($updates);
            $this->line(sprintf('  [%d..%d] 清理 %d，累計 %d', $start, $end, count($updates), $changed));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info(sprintf('%s：%s清理 %d 筆。', $name, $isDryRun ? '預計' : '已', $changed));

        ksort($removedKeys);
        $this->table(['移除的欄位', '筆數'], array_map(
            static fn (string $path, int $count): array => [$path, $count],
            array_keys($removedKeys),
            $removedKeys,
        ));
    }

    /**
     * 列出被剔除的鍵路徑（例：request_info.TOKEN），供 dry-run 估量影響。
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<string>
     */
    private function removedPaths(array $before, array $after, string $prefix = ''): array
    {
        $paths = [];
        foreach ($before as $key => $value) {
            // 清單索引併成 *，統計表才不會被 rows.0、rows.1… 灌爆
            $path = $prefix . (is_int($key) ? '*' : $key);
            if (!array_key_exists($key, $after)) {
                $paths[] = $path;
            } elseif (is_array($value) && is_array($after[$key])) {
                $paths = [...$paths, ...$this->removedPaths($value, $after[$key], $path . '.')];
            }
        }

        return $paths;
    }
}
