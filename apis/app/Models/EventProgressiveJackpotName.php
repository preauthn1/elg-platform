<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $language_id 語言ID, 0:全語系, 1~N:參考languages.id
 * @property string|null $title 標題
 * @property string|null $content 內容
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|EventProgressiveJackpotName newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotName newQuery()
 * @method static Builder<static>|EventProgressiveJackpotName query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
