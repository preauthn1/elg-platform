<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_progressive_jackpot_id event_progressive_jackpots.id
 * @property int $agent_id agents.id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EventProgressiveJackpot|null $eventProgressiveJackpot
 * @property-read Agent|null $agent
 *
 * @method static Builder<static>|EventProgressiveJackpotExcludeAgent newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotExcludeAgent newQuery()
 * @method static Builder<static>|EventProgressiveJackpotExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotExcludeAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function eventProgressiveJackpot(): BelongsTo
    {
        return $this->belongsTo(EventProgressiveJackpot::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
