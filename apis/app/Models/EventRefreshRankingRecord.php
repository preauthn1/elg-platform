<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_refresh_ranking_id event_refresh_rankings.id
 * @property int $member_id members.id
 * @property numeric $bet_amount 投注金額
 * @property string|null $last_bet_time 最後投注時間
 * @property int $prize_content_id 獎項ID;prize_contents.id
 * @property int $ranking 名次
 * @property numeric $point 獎勵金額
 * @property int $type 是否塞入獎項
 * @property int $is_calc 是否計算;0:不計算(電話號碼重複),1:計算,2:後台取消
 * @property int $status 提領結果; 0:未領, 1:已領取
 * @property string|null $time 領取時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventRefreshRankingRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventRefreshRankingRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventRefreshRankingRecord query()
 *
 * @mixin \Eloquent
 */
class EventRefreshRankingRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
