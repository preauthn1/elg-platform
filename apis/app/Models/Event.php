<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * @property int $id 系統編號
 * @property string $title 預設顯示活動標題
 * @property string $content 預設顯示活動內容
 * @property int $type_id 類型編號; params.type=event_type
 * @property string|null $category 活動類別組別
 * @property string|null $start_time 開始時間
 * @property string|null $end_time 結束時間
 * @property int $sort 排序
 * @property int|null $agent_id 營運商系統編號
 * @property int $status 狀態 0:停用,1:啟用
 * @property string $auto_increment 活動到期自動遞增; call ADMIN_event_automatic_increase
 * @property int $auto_payout 自動派發獎勵
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 * @property-read Collection<int, EventCode> $eventCodes
 * @property-read int|null $event_codes_count
 * @property-read EventImage|null $eventDefaultImage
 * @property-read EventDetail|null $eventDetail
 * @property-read Collection<int, EventExcludeAgent> $eventExcludeAgents
 * @property-read int|null $event_exclude_agents_count
 * @property-read EventImage|null $eventImage
 * @property-read EventName|null $eventName
 * @property-read Collection<int, EventName> $eventNames
 * @property-read int|null $event_names_count
 * @property-read mixed $jackpot_title
 * @property-read Prize|null $prize
 * @property-read Collection<int, Vendor> $vendors
 * @property-read int|null $vendors_count
 *
 * @method static \Database\Factories\EventFactory factory($count = null, $state = [])
 * @method static Builder<static>|Event newModelQuery()
 * @method static Builder<static>|Event newQuery()
 * @method static Builder<static>|Event query()
 *
 * @mixin \Eloquent
 */
class Event extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function eventCodes(): HasMany
    {
        return $this->hasMany(EventCode::class);
    }

    public function eventDetail(): HasOne
    {
        return $this->hasOne(EventDetail::class);
    }

    public function eventDefaultImage(): HasOne
    {
        return $this->hasOne(EventImage::class)
            ->where('language_id', LanguageEnum::EN->value);
    }

    public function eventImage(): HasOne
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return $this->hasOne(EventImage::class)
            ->where('language_id', $languageId);
    }

    public function eventExcludeAgents(): HasMany
    {
        return $this->hasMany(EventExcludeAgent::class);
    }

    public function eventNames(): HasMany
    {
        return $this->hasMany(EventName::class);
    }

    public function eventName(): HasOne
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return $this->hasOne(EventName::class)
            ->where('language_id', $languageId);
    }

    public function prize(): HasOne
    {
        return $this->hasOne(Prize::class);
    }

    /**
     * 透過中間表 event_promotions 取得活動關聯的 vendor
     */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'event_promotions', 'event_id', 'vendor_id');
    }

    /**
     * 修改器: jackpot_title
     *
     * @deprecated
     */
    protected function jackpotTitle(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->eventName->title ?? $this->title,
        );
    }
}
