<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static>|ShareholderMemberThreshold newModelQuery()
 * @method static Builder<static>|ShareholderMemberThreshold newQuery()
 * @method static Builder<static>|ShareholderMemberThreshold query()
 *
 * @mixin \Eloquent
 */
class ShareholderMemberThreshold extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
