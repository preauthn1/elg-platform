<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理系統編號
 * @property string|null $title 活動標題
 * @property string|null $content 活動內文
 * @property int $first_deposit_type 首儲類別;1: 創建帳號後第一筆、2: 活動開始後第一筆
 * @property bool $ip_block 是否啟用IP封鎖
 * @property bool $mobile_block 是否啟用手機號封鎖
 * @property bool $realname_block 是否啟用實名封鎖
 * @property int $sort 前端排序
 * @property int $status 活動開關
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Agent|null $agent
 * @property-read \Illuminate\Support\Collection<int, EventProgressiveJackpotExcludeAgent> $excludeAgents
 *
 * @method static Builder<static>|EventProgressiveJackpot newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpot newQuery()
 * @method static Builder<static>|EventProgressiveJackpot query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpot extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'ip_block' => 'boolean',
        'mobile_block' => 'boolean',
        'realname_block' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function excludeAgents(): HasMany
    {
        return $this->hasMany(EventProgressiveJackpotExcludeAgent::class);
    }
}
