<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 代理組別編號
 * @property string $vendor_id 遊戲商編號
 * @property string $name 遊戲商名稱
 * @property string $image 遊戲商圖片
 * @property string $app_icon 遊戲商app的圖示
 * @property numeric|null $valid_bet_rate 有效投注轉換比例
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read AgentGroup|null $agentGroup
 *
 * @method static Builder<static>|VendorImage newModelQuery()
 * @method static Builder<static>|VendorImage newQuery()
 * @method static Builder<static>|VendorImage query()
 *
 * @mixin \Eloquent
 */
class VendorImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }
}
