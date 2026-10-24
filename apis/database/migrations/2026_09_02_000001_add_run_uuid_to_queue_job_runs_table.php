<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * queue_job_runs 加 run_uuid 並納入唯一鍵，支援「一次 job 執行 = 一列」
 *
 * 原唯一鍵 (job_key, work_key, bucket_time) 讓同一格的第二次執行被 upsert 併進同一列，
 * 兩次執行在表上只留一列、連 created_at／updated_at 都不動（實測），無法作為執行軌跡。
 * 加入每次執行各自產生的 UUIDv7 後，同格重跑各自成列。
 *
 * **SBR 判缺不受影響**：語意 A（SUMMARY_BET_RECORD）的 markCompleted() 同樣改為
 * 每次執行各自一列（同格重跑即重算軌跡，如關帳調帳的強制重算）；回看補洞與缺口稽核
 * （isCompleted／missingPairs）只比對原三欄、看列存在與否，不受列數影響。
 * 既有列不回填 run_uuid（default ''），與新列在四欄唯一鍵下自然共存。
 *
 * 取 UUIDv7 而非 v4：v7 前綴為時間戳、單調遞增，作為唯一鍵尾欄時插入集中在 B-tree 右端，
 * 不像 v4 隨機分佈造成頁分裂；且同一 (job_key, work_key, bucket_time) 下依 run_uuid
 * 排序即為執行先後。注意 Laravel 塞進 queue payload 的 uuid 是 Str::uuid()（v4），
 * 與本欄無關，本欄由 QueueJobRun::markRun() 自行以 Str::uuid7() 產生。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            // 預設空字串而非 nullable：唯一鍵含 NULL 時 MySQL 視每列互不相等，
            // 語意 A 的去重保證會直接失效（多列 NULL 全部可插入）
            $table->char('run_uuid', 36)
                ->collation('utf8mb4_bin')
                ->default('')
                ->after('bucket_time')
                ->comment('單次執行識別碼(UUIDv7)；空字串僅限既有列與部署預塞等外部直接寫入');

            $table->dropUnique('uk_job_work_bucket');
            $table->unique(['job_key', 'work_key', 'bucket_time', 'run_uuid'], 'uk_job_work_bucket_run');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            $table->dropUnique('uk_job_work_bucket_run');
            $table->dropColumn('run_uuid');
            $table->unique(['job_key', 'work_key', 'bucket_time'], 'uk_job_work_bucket');
        });
    }
};
