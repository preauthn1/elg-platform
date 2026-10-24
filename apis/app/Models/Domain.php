<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $domain_type_id 網域類型系統編號;
 * @property string $domain 網址名稱
 * @property string|null $domain_name 域名名稱
 * @property string|null $logo_lm LOGO 淺色模式
 * @property string|null $logo_dm LOGO 深色模式
 * @property string|null $icon 網站 icon(favicon)
 * @property string|null $theme_color 色系(hex 色碼)
 * @property int $status 0未使用,1使用中
 * @property int $sort 排序
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DomainAgent> $agents
 * @property-read int|null $agents_count
 * @property-read DomainType|null $type
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Domain newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Domain newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Domain query()
 *
 * @mixin \Eloquent
 */
class Domain extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // 代理關聯
    public function agents(): HasMany
    {
        return $this->hasMany(DomainAgent::class, 'domain_id', 'id');
    }

    // 類型關聯
    public function type(): BelongsTo
    {
        return $this->belongsTo(DomainType::class, 'domain_type_id');
    }
}
