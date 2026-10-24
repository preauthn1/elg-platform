<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 排程與 artisan 命令的執行紀錄（有沒有跑、何時跑、跑多久、成功沒）。
 *
 * **自動涵蓋、零接入**：由 ScheduleProvider／CommandProvider 統一寫入，命令本身不需做任何事；
 * queue job 不寫本表。命令層執行紀錄一律寫本表，禁止另建同語意表。
 *
 * 與 {@see QueueJobRun} 的分工（兩者不重疊，不算違反上面那條）：
 * - 本表記「**命令**跑了沒、跑多久」——命令層，自動涵蓋、零接入。
 * - queue_job_runs 記「哪個**工作單位**成功完成」——資料層，需接入且與業務寫入同一 DB 交易。
 *
 * 排查「排程有沒有跑」看本表；判斷「某格資料做完了沒、要不要補」看 queue_job_runs。
 *
 * @property int $id
 * @property Carbon $day
 * @property string $command
 * @property string $input
 * @property int $status
 * @property Carbon $start_time
 * @property Carbon|null $end_time
 * @property numeric|null $usages
 * @property string|null $cronjob
 * @property string|null $trace_id 追蹤碼，用於追蹤排程任務的所有日誌
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|CommandLog newModelQuery()
 * @method static Builder<static>|CommandLog newQuery()
 * @method static Builder<static>|CommandLog query()
 *
 * @mixin \Eloquent
 */
class CommandLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'day' => 'date',
    ];
}
