<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $prize_content_id prize_contents.id
 * @property int $vendor_id vendors.id
 * @property int $game_type_id game_types.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|PrizeContentFlowLimit newModelQuery()
 * @method static Builder<static>|PrizeContentFlowLimit newQuery()
 * @method static Builder<static>|PrizeContentFlowLimit query()
 *
 * @mixin \Eloquent
 */
class PrizeContentFlowLimit extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
