<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $type_id 遊戲類型
 * @property int $join_bet_event 是否可參加投注額活動，0:不可，1:可以
 * @property int $join_win_event 是否可參加贏分活動，0:不可，1:可以
 * @property string $game_type 遊戲代碼
 * @property string $name 名稱
 * @property numeric|null $lottery_pool 彩池金額
 * @property string|null $image 遊戲圖片
 * @property int|null $is_new 是否為新遊戲(0:否,1:是)
 * @property int|null $is_hot 是否為熱門遊戲(0:否,1:是)
 * @property int $open_window 是否另開視窗; 1:另開, 0:不另開
 * @property int $status 狀態：0停用，1啟用
 * @property numeric|null $rtp RTP
 * @property int|null $sort 排序
 * @property string|null $info info
 * @property string|null $remark 備註
 * @property string|null $memo 內部人員備註用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property numeric|null $valid_bet_rate 有效投注轉換比例
 * @property int|null $calc_flow_limit 是否計算稽核流水
 * @property-read Collection<int, AgentGame> $agentGames
 * @property-read int|null $agent_games_count
 * @property-read Collection<int, AgentGroupGameTag> $agentGroupGameTags
 * @property-read int|null $agent_group_game_tags_count
 * @property-read Collection<int, FavouriteGame> $favouriteGames
 * @property-read int|null $favourite_games_count
 * @property-read Collection<int, GameName> $gameNames
 * @property-read int|null $game_names_count
 * @property-read GameType|null $gameType
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorInfoGame> $vendorInfoGames
 * @property-read int|null $vendor_info_games_count
 *
 * @method static \Database\Factories\GameFactory factory($count = null, $state = [])
 * @method static Builder<static>|Game newModelQuery()
 * @method static Builder<static>|Game newQuery()
 * @method static Builder<static>|Game query()
 *
 * @mixin \Eloquent
 */
class Game extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的代理遊戲
     */
    public function agentGames(): HasMany
    {
        return $this->hasMany(AgentGame::class, 'game_id', 'id');
    }

    /**
     * 關聯的遊戲商環境遊戲
     */
    public function vendorInfoGames(): HasMany
    {
        return $this->hasMany(VendorInfoGame::class, 'game_id', 'id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function gameNames(): HasMany
    {
        return $this->hasMany(GameName::class, 'game_id', 'id');
    }

    public function agentGroupGameTags(): HasMany
    {
        return $this->hasMany(AgentGroupGameTag::class, 'game_id', 'id');
    }

    public function gameType(): BelongsTo
    {
        return $this->belongsTo(GameType::class);
    }

    public function favouriteGames(): HasMany
    {
        return $this->hasMany(FavouriteGame::class, 'game_id', 'id');
    }
}
