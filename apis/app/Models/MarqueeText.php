<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\General\ContextKeyEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * @property int $id
 * @property int $agent_id 代理id
 * @property int $exclude_agent_id 排除代理id
 * @property int $location 跑馬燈位置;1:大廳、2:聊天室
 * @property int $type 跑馬燈類別，1:一般 2:重要
 * @property int $status
 * @property string $start_time 起始時間
 * @property string $end_time 結束時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read string $content
 * @property-read MarqueeTextContent|null $marqueeTextContent
 * @property-read Collection<int, MarqueeTextContent> $marqueeTextContents
 * @property-read int|null $marquee_text_contents_count
 *
 * @method static Builder<static>|MarqueeText newModelQuery()
 * @method static Builder<static>|MarqueeText newQuery()
 * @method static Builder<static>|MarqueeText query()
 *
 * @mixin \Eloquent
 */
class MarqueeText extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function marqueeTextContents(): HasMany
    {
        return $this->hasMany(MarqueeTextContent::class, 'marquee_text_id');
    }

    public function marqueeTextContent(): HasOne
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return $this->hasOne(MarqueeTextContent::class)
            ->where('language_id', $languageId);
    }

    /**
     * 修改器: jackpot_title
     */
    public function getContentAttribute(): string
    {
        return $this->marqueeTextContent?->content ?? '';
    }
}
