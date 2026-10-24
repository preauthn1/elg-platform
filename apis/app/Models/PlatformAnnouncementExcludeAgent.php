<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $platform_announcement_id
 * @property int $agent_id
 *
 * @method static Builder<static>|PlatformAnnouncementExcludeAgent newModelQuery()
 * @method static Builder<static>|PlatformAnnouncementExcludeAgent newQuery()
 * @method static Builder<static>|PlatformAnnouncementExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncementExcludeAgent extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
