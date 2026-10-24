<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProcedureHash;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * php artisan db:seed --class=SyncProcedureSeeder
 */
class SyncProcedureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): int
    {
        $output = $this->command?->getOutput();

        $output?->writeln('SyncProcedureSeeder 開始執行...');

        $procedurePath = base_path('database/procedures');
        $files = File::files($procedurePath);

        // 一次查詢所有已存在的 procedure_hashes 記錄
        $existingRecords = ProcedureHash::query()->get()->keyBy('file_name');

        $updated = 0;
        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'sql') {
                continue;
            }

            $fileName = $file->getFilename();
            $fileContent = File::get($file->getPathname());
            $currentHash = md5($fileContent);
            $existingRecord = $existingRecords[$fileName] ?? null;
            $procedureName = pathinfo($fileName, PATHINFO_FILENAME);

            // 如果哈希值相同，則不需要更新
            if ($existingRecord && $existingRecord->file_hash === $currentHash) {
                continue;
            }

            // 先刪除舊的 Procedure
            DB::unprepared("DROP PROCEDURE IF EXISTS `{$procedureName}`");

            // 新增或更新 Procedure
            DB::unprepared($fileContent);

            if ($existingRecord) {
                // 更新哈希值
                ProcedureHash::query()
                    ->where('file_name', $fileName)
                    ->update([
                        'file_hash' => $currentHash,
                    ]);

                $output?->writeln("已更新 {$fileName}");
            } else {
                // 插入新記錄
                ProcedureHash::create([
                    'file_name' => $fileName,
                    'file_hash' => $currentHash,
                ]);

                $output?->writeln("新增 {$fileName}");
            }

            $updated++;
        }

        $output?->writeln("更新 {$updated} 個 Procedure");

        $output?->writeln('SyncProcedureSeeder 已執行完成。');

        return $updated;
    }
}
