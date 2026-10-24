<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewbieChallenge\NewbieChallengeOperatorTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $progress_id newbie_challenge_progress.id
 * @property int $operator_type 操作者類型，見 NewbieChallengeOperatorTypeEnum
 * @property int|null $operator_id 操作者ID，系統操作為null
 * @property string $action 動作
 * @property int|null $from_stage
 * @property int|null $to_stage
 * @property string|null $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|NewbieChallengeOperationLog newModelQuery()
 * @method static Builder<static>|NewbieChallengeOperationLog newQuery()
 * @method static Builder<static>|NewbieChallengeOperationLog query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeOperationLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'operator_type' => NewbieChallengeOperatorTypeEnum::class,
    ];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(NewbieChallengeProgress::class, 'progress_id');
    }
}
