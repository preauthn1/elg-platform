<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $title 登入活動名稱
 * @property string $content 登入活動內容
 * @property int $agent_id 代理商編號
 * @property int $type 可參與活動的條件，1:活動開始後創立之新帳號、2:舊帳號, 且幾日內無開洗分紀錄
 * @property int $how_days 幾日內無開洗分紀錄
 * @property int $point 參與活動贈送的點數
 * @property int $times 解鎖條件：達到贈送點數的幾倍
 * @property int $number_of_people 可參與的人數(0代表無上限)
 * @property int $number_of_participants 已參與的人數
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property int $repeat_hour 重複參與時間間隔(最少1小時，0代表不可重複參加)
 * @property string|null $vendor_id 試玩廠商
 * @property int $status 狀態，0:停用、1:正常
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|LoginEvent newModelQuery()
 * @method static Builder<static>|LoginEvent newQuery()
 * @method static Builder<static>|LoginEvent query()
 *
 * @mixin \Eloquent
 */
class LoginEvent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
