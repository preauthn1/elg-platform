<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminOperationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id 執行者系統編號(User)
 * @property string $operation 操作代號(artisan 指令名)
 * @property array|null $params 執行參數
 * @property AdminOperationStatusEnum $status 狀態
 * @property int|null $queue_job_run_id 本次操作的執行列（queue_job_runs.id）；輸出與即時狀態看該列
 * @property Carbon|null $started_at 開始執行時間
 * @property Carbon|null $finished_at 執行結束時間
 * @property Carbon|null $cancel_requested_at 人工要求中斷的時間（null = 未要求）
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read QueueJobRun|null $queueJobRun
 *
 * @mixin \Eloquent
 */
class AdminOperationLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => AdminOperationStatusEnum::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    /**
     * 後台「中斷」旗標是否已被按下。
     *
     * 長時指令**必須**在安全點輪詢本方法自行收尾：旗標只是請求，沒有任何機制會去停進程。
     * 不輪詢的指令，後台按「中斷」只會把紀錄標成失敗、進程照跑，重新執行就變成兩個進程
     * 同時作業（見 AbstractOperationPage::cancelWarning()）；該指令的操作頁也因此**禁止**
     * 把 supportsCancelSignal() 開成 true。
     *
     * $logId 來自 Context 的 ADMIN_OPERATION_LOG_ID，命令列直接跑時為 0（無紀錄可讀，不輪詢）。
     */
    public static function isCancelRequested(int $logId): bool
    {
        return $logId > 0
            && static::query()->whereKey($logId)->value('cancel_requested_at') !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 本次操作的執行列：status（未處理→成功／失敗）與 output 都在這
     */
    public function queueJobRun(): BelongsTo
    {
        return $this->belongsTo(QueueJobRun::class, 'queue_job_run_id', 'id');
    }
}
