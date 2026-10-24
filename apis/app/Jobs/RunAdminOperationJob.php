<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Output\QueueJobRunStreamOutput;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Models\AdminOperationLog;
use App\Models\QueueJobRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 執行後台「系統操作」頁面觸發的 artisan 白名單指令（AdminOperationEnum）。
 *
 * 生命週期：pending（頁面建立紀錄）→ running（本 job 起跑）→ success / failed，
 * 全程狀態與輸出回寫 admin_operation_logs 供頁面輪詢顯示與稽核。
 */
class RunAdminOperationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /** 補跑會重複執行業務指令，失敗一律人工確認後重新觸發 */
    public int $tries = 1;

    public function __construct(public int $logId)
    {
        // 走長時 job 專用連線：本 job timeout=3600，預設 redis 連線 retry_after=90，
        // 跑超過 90 秒即被重新派發、tries=1 直接誤判 MaxAttemptsExceeded
        // （2026-08-27 整天補拉 PP 注單實際踩到，執行 90 秒整被判死）
        $this->onConnection('redis-long-running');
    }

    public function handle(): void
    {
        DB::beginTransaction();

        try {
            $log = AdminOperationLog::where('id', $this->logId)->lockForUpdate()->first();

            // 只接手 pending 紀錄，避免重送或人工介入後重複執行
            if ($log === null || $log->status !== AdminOperationStatusEnum::PENDING) {
                DB::commit();

                return;
            }

            $log->status = AdminOperationStatusEnum::RUNNING;
            $log->started_at = now();
            $log->save();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // 輸出邊跑邊回寫 queue_job_runs.output，長時指令（逐人轉帳等）中途就看得到進度
        $output = new QueueJobRunStreamOutput($log->queue_job_run_id);

        // 長時指令據此讀回自己的操作紀錄，輪詢後台「中斷」旗標（admin_operation_logs.cancel_requested_at）；
        // Context 在每個 job 起跑時由框架重新 hydrate，不會外溢到下一個 job
        Context::addHidden(ContextKeyEnum::ADMIN_OPERATION_LOG_ID->value, $log->id);

        try {
            $exitCode = Artisan::call($log->operation, $log->params ?? [], $output);

            $this->finish(
                $exitCode === 0 ? AdminOperationStatusEnum::SUCCESS : AdminOperationStatusEnum::FAILED,
                $output->fetch(),
            );
        } catch (Throwable $e) {
            Log::error('RunAdminOperationJob failed', [
                'log_id' => $this->logId,
                'operation' => $log->operation,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $this->finish(AdminOperationStatusEnum::FAILED, rtrim($output->fetch() . "\n" . $e->getMessage()));
        }
    }

    /**
     * timeout、worker 異常等框架層失敗的兜底：把殘留的 pending / running 紀錄標為失敗
     */
    public function failed(?Throwable $exception): void
    {
        DB::beginTransaction();

        try {
            $log = AdminOperationLog::where('id', $this->logId)->lockForUpdate()->first();

            if ($log !== null && !in_array($log->status, [AdminOperationStatusEnum::SUCCESS, AdminOperationStatusEnum::FAILED], true)) {
                $log->status = AdminOperationStatusEnum::FAILED;
                $log->finished_at = now();
                $log->save();
                // 保留 QueueJobRunStreamOutput 已回寫的進度，錯誤訊息接在後面：
                // 超時被砍時這段進度就是唯一能看出「跑到哪、誰失敗」的地方，也是操作頁接續的依據
                $streamed = QueueJobRun::query()->where('id', $log->queue_job_run_id)->value('output');
                $message = $exception?->getMessage() ?? 'job failed';
                QueueJobRun::finishRun($log->queue_job_run_id, success: false, output: trim(($streamed ?? '') . "\n" . $message));
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * 收尾：操作紀錄記狀態與時間，執行輸出寫進派發時建立的執行列（queue_job_runs.output）
     */
    private function finish(AdminOperationStatusEnum $status, ?string $output): void
    {
        DB::beginTransaction();

        try {
            $fresh = AdminOperationLog::where('id', $this->logId)->lockForUpdate()->first();

            if ($fresh !== null) {
                $fresh->status = $status;
                $fresh->finished_at = now();
                $fresh->save();
                QueueJobRun::finishRun($fresh->queue_job_run_id, success: $status === AdminOperationStatusEnum::SUCCESS, output: $output);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }
}
