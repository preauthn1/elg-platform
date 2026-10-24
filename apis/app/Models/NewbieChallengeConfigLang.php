<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $config_id newbie_challenge_configs.id
 * @property int $language_id languages.id
 * @property string $name 活動名稱
 * @property string|null $image 活動圖片
 * @property string $content 活動說明與規則文字
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|NewbieChallengeConfigLang newModelQuery()
 * @method static Builder<static>|NewbieChallengeConfigLang newQuery()
 * @method static Builder<static>|NewbieChallengeConfigLang query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeConfigLang extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(NewbieChallengeConfig::class, 'config_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }
}
