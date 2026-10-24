<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $brand_id brands.id
 * @property int $agent_id agents.id
 * @property int $sort
 * @property string|null $info
 * @property string|null $lang_content 語言內容
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 * @property-read Brand|null $brand
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAgent query()
 *
 * @mixin \Eloquent
 */
class BrandAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
