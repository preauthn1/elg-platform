<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_invite_setting_id event_invite_settings.id
 * @property int $agent_id agents.id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EventInviteSetting|null $eventInviteSetting
 * @property-read Agent|null $agent
 *
 * @method static Builder<static>|EventInviteExcludeAgent newModelQuery()
 * @method static Builder<static>|EventInviteExcludeAgent newQuery()
 * @method static Builder<static>|EventInviteExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class EventInviteExcludeAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function eventInviteSetting(): BelongsTo
    {
        return $this->belongsTo(EventInviteSetting::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
