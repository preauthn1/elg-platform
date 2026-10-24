<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 綁定的代理 ID
 * @property string $name Bot 識別名稱（如 default、brand_a）
 * @property string $api_key Bot API key（明文）
 * @property string|null $vip_url 該 bot 的 VIP 頁面 URL
 * @property bool $is_active 啟用旗標
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Agent|null $agent
 *
 * @method static Builder<static>|LivechatBot newModelQuery()
 * @method static Builder<static>|LivechatBot newQuery()
 * @method static Builder<static>|LivechatBot query()
 *
 * @mixin \Eloquent
 */
class LivechatBot extends Model
{
    protected $fillable = [
        'agent_id',
        'name',
        'api_key',
        'vip_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
