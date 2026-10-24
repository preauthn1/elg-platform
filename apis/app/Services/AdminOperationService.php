<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Jobs\RunAdminOperationJob;
use App\Models\AdminOperationLog;
use App\Models\QueueJobRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 後台「系統操作」的派發服務：建立稽核紀錄並把白名單 artisan 指令排入佇列。
 *
 * 派發同時以 QueueJobRun::createPending() 建執行列（job_key=ADMIN_OPERATION、
 * work_key=操作紀錄 id），操作紀錄以 queue_job_run_id 關聯——執行輸出與
 * 即時狀態由該列承載（admin_operation_logs.output 已廢），頁面據此輪詢顯示。
 */
readonly class AdminOperationService
{
    /**
     * 派發操作；同一操作已有未完成執行時擋下並回傳該筆紀錄。
     *
     * $scopeParams 把「同一件事」的判定縮小到指定的指令參數上：轉回遊戲商餘額傳
     * ['--vendorId' => '3'] 就只擋同一個遊戲商，不同遊戲商可並行（三方的每秒配額本來就是各商獨立）。
     * 留空（預設）＝同一個操作同時只能有一筆未完成，與舊行為相同。
     *
     * @param  array<string, mixed>  $params  artisan 指令參數
     * @param  array<string, mixed>  $scopeParams  防重複的範圍鍵，取自 $params 的子集
     * @return array{log: ?AdminOperationLog, blocking: ?AdminOperationLog}
     */
    public function dispatch(AdminOperationEnum $operation, array $params, int $userId, array $scopeParams = []): array
    {
        DB::beginTransaction();

        try {
            // 同一操作已有待執行 / 執行中紀錄則擋下；超過 staleSeconds 視為殭屍（worker 被砍等異常）放行
            $blocking = AdminOperationLog::where('operation', $operation->value)
                ->whereIn('status', [AdminOperationStatusEnum::PENDING->value, AdminOperationStatusEnum::RUNNING->value])
                ->where('created_at', '>=', now()->subSeconds($operation->staleSeconds()))
                // 比對存在 params JSON 的範圍鍵：量小（後台操作紀錄）且前面三個條件已走 (operation, status, created_at) 索引，
                // 不為此另立欄位與索引
                ->when($scopeParams !== [], function ($query) use ($scopeParams): void {
                    foreach ($scopeParams as $key => $value) {
                        $query->where('params->' . $key, (string) $value);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($blocking !== null) {
                DB::commit();

                return ['log' => null, 'blocking' => $blocking];
            }

            $log = AdminOperationLog::create([
                'user_id' => $userId,
                'operation' => $operation->value,
                'params' => $params ?: null,
                'status' => AdminOperationStatusEnum::PENDING->value,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // 派發即建執行列（status=未處理）：RunAdminOperationJob 收尾 update 同列
        $log->queue_job_run_id = QueueJobRun::createPending(
            jobKey: QueueJobKeyEnum::ADMIN_OPERATION,
            workKey: $log->id,
            bucketTime: now()->format('Y-m-d H:00:00'),
            source: QueueJobRunSourceEnum::MANUAL,
        );
        $log->save();

        dispatch(new RunAdminOperationJob($log->id));

        return ['log' => $log, 'blocking' => null];
    }

    /**
     * 人工中斷一次執行。依進程死活走兩條路：
     *
     * - $graceful = true（進程還活著、且該指令會輪詢中斷旗標）：只寫 cancel_requested_at，
     *   狀態留給指令自己跑到安全點收尾（轉回餘額這類逐人轉帳的指令，硬停會留下半途的帳）。
     *   註記不寫進 output：指令還在跑，QueueJobRunStreamOutput 會一路覆寫，寫了也留不住。
     * - $graceful = false（進程已死）：直接標為失敗，解除同操作的重複執行封鎖。
     *   worker 被硬砍時（k8s rollout、OOM、Horizon 縮編強殺）紀錄會永遠停在 RUNNING：
     *   job 沒有機會跑 failed()，要等 queue 連線的 retry_after 到期重新派發才會被判
     *   MaxAttemptsExceeded，期間 dispatch() 的防重複會一路擋住新的執行（見該處 staleSeconds）。
     *
     * 已收尾（SUCCESS / FAILED）的紀錄不動，回傳 false。
     */
    public function cancel(AdminOperationLog $log, int $userId, bool $graceful = false): bool
    {
        DB::beginTransaction();

        try {
            $fresh = AdminOperationLog::where('id', $log->id)->lockForUpdate()->first();

            if ($fresh === null || in_array($fresh->status, [AdminOperationStatusEnum::SUCCESS, AdminOperationStatusEnum::FAILED], true)) {
                DB::commit();

                return false;
            }

            if ($graceful) {
                // 重按只沿用第一次的時間：旗標本身沒有次數語意，覆寫只會讓「什麼時候要求的」失真
                $fresh->cancel_requested_at ??= now();
                $fresh->save();

                DB::commit();

                return true;
            }

            $fresh->status = AdminOperationStatusEnum::FAILED;
            $fresh->finished_at = now();
            $fresh->save();

            // 保留已回寫的進度，人工註記接在後面：轉回餘額這類指令靠最後一行進度決定續跑起點
            $streamed = QueueJobRun::query()->where('id', $fresh->queue_job_run_id)->value('output');
            $operator = User::query()->whereKey($userId)->value('username') ?? ('user#' . $userId);
            $note = sprintf('（%s 於 %s 手動中斷，標記為失敗）', $operator, now()->toFrontendTime()->toDateTimeString());

            QueueJobRun::finishRun($fresh->queue_job_run_id, success: false, output: trim(($streamed ?? '') . "\n" . $note));

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * 稽核型紀錄：動作完成當下直接落一筆 SUCCESS（無執行期，不經 queue）。
     *
     * 供後台非 artisan 動作（新增帳號、修改密碼等）統一寫入操作紀錄；
     * 呼叫端若有同事務需求，自行以 DB::transaction 包住動作與本方法。
     *
     * @param  array<string, mixed>  $params
     */
    public function audit(AdminOperationEnum $operation, int $userId, array $params, string $output): AdminOperationLog
    {
        $now = now();

        $log = AdminOperationLog::create([
            'user_id' => $userId,
            'operation' => $operation->value,
            'params' => $params ?: null,
            'status' => AdminOperationStatusEnum::SUCCESS->value,
            'started_at' => $now,
            'finished_at' => $now,
        ]);

        // 稽核輸出與 artisan 路徑一致，統一由 queue_job_runs.output 承載（無執行期，直接成功列）
        $runId = QueueJobRun::createPending(
            jobKey: QueueJobKeyEnum::ADMIN_OPERATION,
            workKey: $log->id,
            bucketTime: $now->format('Y-m-d H:00:00'),
            source: QueueJobRunSourceEnum::MANUAL,
        );
        QueueJobRun::finishRun($runId, success: true, output: $output);
        $log->queue_job_run_id = $runId;
        $log->save();

        return $log;
    }
}
