<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewbieChallenge\NewbieChallengeBlockJoinStatusEnum;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $config_id newbie_challenge_configs.id
 * @property int $member_id members.id
 * @property int|null $last_block_record_id 最新一筆阻擋紀錄id
 * @property int|null $progress_id 後台放行後、玩家真的 join 成功回填的 newbie_challenge_progress.id
 * @property \Illuminate\Support\Carbon|null $allowed_time 後台放行時間
 * @property NewbieChallengeBlockJoinStatusEnum $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read NewbieChallengeConfig|null $config
 * @property-read \Illuminate\Database\Eloquent\Collection<int, NewbieChallengeBlockRecord> $blockRecords
 * @property-read NewbieChallengeBlockRecord|null $lastBlockRecord
 * @property-read Member|null $member
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockJoin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockJoin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeBlockJoin query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeBlockJoin extends Model
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'status' => NewbieChallengeBlockJoinStatusEnum::class,
        'allowed_time' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(NewbieChallengeConfig::class, 'config_id');
    }

    public function blockRecords(): HasMany
    {
        return $this->hasMany(NewbieChallengeBlockRecord::class, 'block_join_id');
    }

    public function lastBlockRecord(): HasOne
    {
        return $this->hasOne(NewbieChallengeBlockRecord::class, 'block_join_id')->latestOfMany();
    }
}
