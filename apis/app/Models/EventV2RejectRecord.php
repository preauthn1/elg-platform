<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventV2\EventV2ValidateCodeEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_v2_reject_join_id event_v2_reject_joins.id
 * @property EventV2ValidateCodeEnum $validate_code 驗證code，請參照 EventV2ValidateCodeEnum
 * @property string $message 繁體中文錯誤訊息
 * @property string $ip 玩家當下IP
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectRecord query()
 *
 * @mixin \Eloquent
 */
class EventV2RejectRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'validate_code' => EventV2ValidateCodeEnum::class,
    ];
}
