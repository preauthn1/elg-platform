<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $event_id 活動編號
 * @property int $action_id 行為編號; params.type=event_action
 * @property int|null $order_type_id 訂單類型編號
 * @property int|null $vendor_id 遊戲商系統編號
 * @property int|null $game_id 遊戲系統編號
 * @property int $game_join_event_used 是否使用games的join_bet_event / join_payout_event
 * @property int|null $bind_unlimited 邀請時是否綁定全民代理
 * @property numeric $rate 匯率
 * @property int|null $game_type_id 遊戲分類
 * @property int $point 達標點數
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property string $draw_time 派獎時間
 * @property int $expire_hour 獎勵領取期限(小時)
 * @property int $ip_block 限制IP
 * @property int $global_ip_block 全站限制IP 0:不阻擋 1:阻擋
 * @property int $phone_block 限制電話
 * @property int $global_phone_block 全站限制電話 0:不阻擋 1:阻擋
 * @property int $browser_block 限制瀏覽器序號
 * @property int $account_block 限制真實姓名
 * @property int $global_account_block 全站限制真實姓名 0:不阻擋 1:阻擋
 * @property int $realname_block 檢查實名認證
 * @property int $tgid_block 檢查telegram user id
 * @property int $vendor_lock 是否鎖定遊戲商入口
 * @property int $status 狀態0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Event|null $event
 *
 * @method static \Database\Factories\EventDetailFactory factory($count = null, $state = [])
 * @method static Builder<static>|EventDetail newModelQuery()
 * @method static Builder<static>|EventDetail newQuery()
 * @method static Builder<static>|EventDetail query()
 *
 * @mixin \Eloquent
 */
class EventDetail extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
