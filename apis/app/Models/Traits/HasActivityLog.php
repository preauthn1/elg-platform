<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Activity;

/**
 * 取某訂單的活動紀錄（被操作的）
 * $order->activitiesAsSubject()->latest()->get();
 *
 * 取某使用者作為操作者的活動紀錄
 * $member->activitiesAsCauser()->where('log_name', 'order-created')->get();
 *
 * 從活動反查關聯
 * Activity::with(['subject', 'causer'])->latest()->first();
 */
trait HasActivityLog
{
    // 以本模型作為被操作對象(subject) 的活動紀錄
    public function activitiesAsSubject(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    // 以本模型作為操作者(causer) 的活動紀錄
    public function activitiesAsCauser(): MorphMany
    {
        return $this->morphMany(Activity::class, 'causer');
    }
}
