<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberRestrictionTargetEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 活動 V2 目標用戶（target/tickets#4234）：一檔活動開放給哪些會員或會員群組，一列一個勾選。
 *
 * 一檔活動沒有任何一列＝不限制。target_type 存 morph alias（member／member_group，見 AppServiceProvider 的
 * morphMap），與風控會員限制 member_restriction_rules 同一套，型別列舉沿用 MemberRestrictionTargetEnum。
 * 必須：一檔活動只能有一種 target_type（後台 ValidatesEventTargets 擋混用）。
 *
 * @property int $id
 * @property int $event_v2_detail_id
 * @property string $target_type
 * @property int $target_id
 * @property-read Member|MemberGroup|null $target
 */
class EventV2DetailTarget extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_v2_detail_id',
        'target_type',
        'target_id',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }

    public function targetEnum(): MemberRestrictionTargetEnum
    {
        return MemberRestrictionTargetEnum::fromMorphAlias($this->target_type);
    }
}
