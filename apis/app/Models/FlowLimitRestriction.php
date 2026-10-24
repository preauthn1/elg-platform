<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $flow_limit_id 稽核流水編號
 * @property int $vendor_id 遊戲商編號
 * @property int $type_id 遊戲類別編號
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|FlowLimitRestriction newModelQuery()
 * @method static Builder<static>|FlowLimitRestriction newQuery()
 * @method static Builder<static>|FlowLimitRestriction query()
 *
 * @mixin \Eloquent
 */
class FlowLimitRestriction extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
