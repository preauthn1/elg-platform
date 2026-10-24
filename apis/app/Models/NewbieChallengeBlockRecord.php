<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewbieChallenge\NewbieChallengeBlockTypeEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $block_join_id newbie_challenge_block_joins.id
 * @property NewbieChallengeBlockTypeEnum $block_type 阻擋維度(IP/手機/真實姓名)
 * @property string $message 繁體中文錯誤訊息快照
 * @property string $ip 玩家當下IP
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockRecord query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeBlockRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'block_type' => NewbieChallengeBlockTypeEnum::class,
    ];
}
