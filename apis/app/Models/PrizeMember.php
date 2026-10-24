<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $member_id 玩家系統編號
 * @property string $prize_id 獎勵遊戲系統編號
 * @property string|null $content_id 獲得獎項系統編號
 * @property int|null $event_detail_id event_details.id
 * @property string|null $expired_time 領獎期限
 * @property string|null $lottery_number 摸彩卷號碼
 * @property numeric|null $lottery_point 獎勵金額
 * @property string $ip 領獎時的ip位置
 * @property int $status 狀態;
 *                       投注活動; 0:未使用,1:已使用,3:取消
 *                       V-jackpot; 0:未開始,1:中獎,2:未中獎,3:取消
 *                       W-jackpot; 0:未開始,1:中獎,2:未中獎,3:取消,9:派發中
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|PrizeMember newModelQuery()
 * @method static Builder<static>|PrizeMember newQuery()
 * @method static Builder<static>|PrizeMember query()
 *
 * @mixin \Eloquent
 */
class PrizeMember extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
