<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id 流水號
 * @property string $launch_date Launch Date
 * @property int $member_id 玩家系統編號(members.id)
 * @property int $wallet_type_id 錢包系統編號(wallet_types.id)
 * @property int $game_id 遊戲系統編號(games.id)
 * @property int $count 登入次數
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberLaunchLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberLaunchLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberLaunchLog query()
 *
 * @mixin \Eloquent
 */
class MemberLaunchLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $attributes = [
        'wallet_type_id' => 1,
    ];
}
