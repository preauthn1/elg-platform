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
 * @method static Builder<static>|PlatformAnnouncementIncludeAgent newModelQuery()
 * @method static Builder<static>|PlatformAnnouncementIncludeAgent newQuery()
 * @method static Builder<static>|PlatformAnnouncementIncludeAgent query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncementIncludeAgent extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
