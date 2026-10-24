<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $language_id 語言ID, 0:全語系, 1~N:參考languages.id
 * @property string|null $image 圖片
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|EventInviteImage newModelQuery()
 * @method static Builder<static>|EventInviteImage newQuery()
 * @method static Builder<static>|EventInviteImage query()
 *
 * @mixin \Eloquent
 */
class EventInviteImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
