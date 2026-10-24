<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 風控會員限制規則（風控管理 > 會員限制）。
 *
 * 注意：與舊風險標記表 member_restrictions（account_status/risk，舊 API_Agent 使用）無關，
 * 本 model 不可改名為 MemberRestriction，否則 Eloquent 預設表名會對到舊表。
 */
class MemberRestrictionRule extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** 限制目標（Member 或 MemberGroup，morph alias 見 AppServiceProvider） */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** 目標所屬代理 */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** 最後編輯此規則的代理 */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
