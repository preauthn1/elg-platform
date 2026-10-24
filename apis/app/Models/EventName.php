<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語言系統編號
 * @property int $event_id 遊戲類型系統編號
 * @property string $title 活動標題
 * @property string $content 活動內容
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|EventName newModelQuery()
 * @method static Builder<static>|EventName newQuery()
 * @method static Builder<static>|EventName query()
 *
 * @mixin \Eloquent
 */
class EventName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
