<?php

declare(strict_types=1);

namespace App\Console\Commands\Copy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 抽象複製數據命令
 *
 * 此抽象類提供通用的數據複製邏輯，子類需要實現具體的表名和字段。
 */
abstract class AbstractCopyCommand extends Command
{
    /**
     * 獲取表名
     */
    abstract protected function getTableName(): string;

    /**
     * 獲取用於區分來源和目標的字段名
     */
    abstract protected function getGroupField(): string;

    /**
     * 獲取需要複製的字段列表
     */
    abstract protected function getFieldsToCopy(): array;

    /**
     * 單批插入大小，避免一次載入過多數據
     */
    protected function getInsertChunkSize(): int
    {
        return 1000;
    }

    /**
     * 執行命令的主要邏輯
     */
    public function handle(): void
    {
        $sourceGroupId = (int) $this->argument('from_group_id');
        $targetGroupId = (int) $this->argument('to_group_id');

        // 驗證輸入參數
        if (!$this->validateInputParameters($sourceGroupId, $targetGroupId)) {
            return;
        }

        $tableName = $this->getTableName();
        $groupField = $this->getGroupField();

        // 檢查來源數據是否存在
        $sourceRecordCount = DB::table($tableName)->where($groupField, $sourceGroupId)->count();
        if ($sourceRecordCount === 0) {
            $this->warn("來源群組 ID {$sourceGroupId} 沒有數據");

            return;
        }

        // 檢查目標數據並決定是否覆蓋
        $targetRecordCount = DB::table($tableName)->where($groupField, $targetGroupId)->count();
        if ($targetRecordCount > 0) {
            $this->warn("目標群組 ID {$targetGroupId} 已存在 {$targetRecordCount} 條數據");
            if (!$this->confirm('是否繼續覆蓋？')) {
                return;
            }
        }

        // 執行數據複製操作
        $this->executeDataCopy($sourceGroupId, $targetGroupId, $sourceRecordCount, $targetRecordCount > 0, $groupField);
    }

    /**
     * 驗證輸入參數的有效性
     */
    private function validateInputParameters(int $sourceGroupId, int $targetGroupId): bool
    {
        if ($sourceGroupId <= 0 || $targetGroupId <= 0) {
            $this->error('群組 ID 必須是大於 0 的整數');

            return false;
        }

        if ($sourceGroupId === $targetGroupId) {
            $this->error('來源和目標群組 ID 不能相同');

            return false;
        }

        return true;
    }

    /**
     * 執行數據複製操作
     */
    private function executeDataCopy(int $sourceGroupId, int $targetGroupId, int $sourceRecordCount, bool $shouldOverwrite, string $groupField): void
    {
        $this->info("開始複製從 {$sourceGroupId} 到 {$targetGroupId}");

        $tableName = $this->getTableName();
        $chunkSize = $this->getInsertChunkSize();

        DB::beginTransaction();

        try {
            // 如果需要覆蓋，先清除目標數據（使用全新 Builder 避免條件殘留）
            if ($shouldOverwrite) {
                DB::table($tableName)->where($groupField, $targetGroupId)->delete();
                $this->info('已刪除現有的目標數據');
            }

            // 分批讀取來源數據並準備插入（全程在交易中）
            DB::table($tableName)
                ->where($groupField, $sourceGroupId)
                ->orderBy($groupField)
                ->chunk($chunkSize, function ($sourceChunk) use ($tableName, $targetGroupId, $groupField) {
                    $dataToInsert = $this->buildInsertData($sourceChunk, $targetGroupId, $groupField);
                    if (!empty($dataToInsert)) {
                        DB::table($tableName)->insert($dataToInsert);
                    }
                });

            $isTest = (bool) $this->option('test');
            if ($isTest) {
                DB::rollBack();
                $this->info('所有資料已回滾（測試模式），未寫入資料庫。');
            } else {
                DB::commit();
                $this->info("成功複製 {$sourceRecordCount} 條數據到群組 ID {$targetGroupId}");
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('複製失敗: ' . $exception->getMessage());
        }
    }

    /**
     * 指定需覆寫的欄位與其預設值。
     * 值可為標量或可呼叫的 `callable($record, $targetGroupId): mixed`。
     */
    protected function getFieldOverrides(): array
    {
        return [];
    }

    /**
     * 構建插入數據陣列
     */
    private function buildInsertData($sourceRecords, int $targetGroupId, string $groupField): array
    {
        $dataToInsert = [];
        $fields = $this->getFieldsToCopy();
        $overrides = $this->getFieldOverrides();

        foreach ($sourceRecords as $record) {
            $row = [];
            foreach ($fields as $field) {
                if ($field === $groupField) {
                    $row[$field] = $targetGroupId;

                    continue;
                }

                if (array_key_exists($field, $overrides)) {
                    $value = $overrides[$field];
                    $row[$field] = is_callable($value)
                        ? $value($record, $targetGroupId)
                        : $value;
                } else {
                    $row[$field] = $record->{$field};
                }
            }
            $dataToInsert[] = $row;
        }

        return $dataToInsert;
    }
}
