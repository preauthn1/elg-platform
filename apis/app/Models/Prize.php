<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $event_id 活動編號
 * @property int $type_id 獎勵遊戲類型編號
 * @property string $name 獎勵活動名稱
 * @property string $background 背景圖
 * @property int $number 獎勵遊戲獲得次數
 * @property int|null $is_repeatedly 可否重覆獲獎0:不可,1:可以
 * @property int $flow_limit_multiple 稽核流水倍數
 * @property numeric $max_point 最大中獎金額
 * @property int $withdrawal_cancel_reward 發紅包與洗分取消領獎限制
 * @property int $cancel_other_reward 領獎後取消其他同訂單活動獎勵
 * @property string|null $info 其他相關設定
 * @property string $expire_date 獎勵遊戲次數到期日
 * @property string|null $draw_date 摸彩卷的開獎日期
 * @property int $status 狀態0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, PrizeContent> $prizeContents
 * @property-read int|null $prize_contents_count
 *
 * @method static Builder<static>|Prize newModelQuery()
 * @method static Builder<static>|Prize newQuery()
 * @method static Builder<static>|Prize query()
 *
 * @mixin \Eloquent
 */
class Prize extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function prizeContents(): HasMany
    {
        return $this->hasMany(PrizeContent::class);
    }
}
