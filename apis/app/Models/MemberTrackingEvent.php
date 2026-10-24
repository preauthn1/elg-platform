<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property string $type 類型
 * @property array<array-key, mixed>|null $data 資料
 * @property Carbon|null $sent_at 發送時間
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTrackingEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTrackingEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTrackingEvent query()
 *
 * @mixin \Eloquent
 */
class MemberTrackingEvent extends Model
{
    //
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'data' => 'array',
    ];
}
