<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 存放 total_bet_records 輸贏有達標的投注記錄（中獎排行榜資料來源）
 *
 * @property int $id total_bet_records 流水號
 * @property string $summary_time 時間
 * @property int $member_id 會員系統編號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $game_id 遊戲系統編號
 * @property numeric $bet 投注金額
 * @property numeric $valid_bet 有效投注金額
 * @property numeric $win_lose 輸贏金額
 * @property string $bet_time 投注時間(UTC)
 * @property string $payout_time 派彩、結算時間(UTC)
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|RankingTbrRecord newModelQuery()
 * @method static Builder<static>|RankingTbrRecord newQuery()
 * @method static Builder<static>|RankingTbrRecord query()
 *
 * @mixin \Eloquent
 */
class RankingTbrRecord extends Model
{
    protected $table = 'ranking_tbr_records';
}
