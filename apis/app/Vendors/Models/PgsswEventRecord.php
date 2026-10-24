<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property string $game_code 用來顯示報表遊戲類別
 * @property string $username 玩家帳號
 * @property string $pay_time 派獎時間(utc)
 * @property numeric $prize_amount 獎勵金額
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswEventRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswEventRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswEventRecord query()
 *
 * @mixin \Eloquent
 */
class PgsswEventRecord extends Model
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
