<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $summary_time
 * @property int $member_id
 * @property int $mission_id
 * @property numeric $progress
 * @property int $is_claimed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Mission|null $mission
 *
 * @method static Builder<static>|MemberMission newModelQuery()
 * @method static Builder<static>|MemberMission newQuery()
 * @method static Builder<static>|MemberMission query()
 *
 * @mixin \Eloquent
 */
class MemberMission extends Model
{
    protected $table = 'member_missions';

    protected $primaryKey = 'id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id', 'id');
    }
}
