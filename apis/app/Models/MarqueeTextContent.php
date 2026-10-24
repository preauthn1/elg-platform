<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $marquee_text_id 文字跑馬燈id
 * @property int $language_id 語系ID
 * @property string $content 跑馬燈內容
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MarqueeTextContent newModelQuery()
 * @method static Builder<static>|MarqueeTextContent newQuery()
 * @method static Builder<static>|MarqueeTextContent query()
 *
 * @mixin \Eloquent
 */
class MarqueeTextContent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
