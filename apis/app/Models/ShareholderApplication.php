<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static>|ShareholderApplication newModelQuery()
 * @method static Builder<static>|ShareholderApplication newQuery()
 * @method static Builder<static>|ShareholderApplication query()
 *
 * @mixin \Eloquent
 */
class ShareholderApplication extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
