<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 代理銀行投影表（銀行表拆分第一期，方案 A）。
 *
 * 過渡期 banks 為權威、本表為單向投影產物（bank:sync-projection 全量重建），
 * 零消費者階段可棄可重建；權威反轉（方案 D 切換）前禁止業務寫入。
 */
class AgentBank extends Model
{
    protected $fillable = [
        'agent_group_id',
        'standard_bank_id',
        'source_bank_id',
        'min_amount',
        'max_amount',
        'status',
    ];

    public function standardBank(): BelongsTo
    {
        return $this->belongsTo(StandardBank::class);
    }
}
