<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use Illuminate\Database\Eloquent\Model;

class MemberStatusLog extends Model
{
    protected $fillable = [
        'member_id',
        'agent_id',
        'type',
        'before_status',
        'after_status',
        'operator',
    ];

    protected $casts = [
        'type' => MemberStatusLogTypeEnum::class,
        'before_status' => MemberStatusEnum::class,
        'after_status' => MemberStatusEnum::class,
    ];
}
