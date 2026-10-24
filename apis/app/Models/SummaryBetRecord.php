<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $summary_time 時間
 * @property string $data_time 數據時間
 * @property int $member_id 會員系統編號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $game_id 遊戲系統編號
 * @property numeric $bet 投注金額
 * @property numeric $win_lose 贏得金額
 * @property numeric $rolling_chip 禮碼
 * @property numeric|null $commission 佣金
 * @property numeric $jackpot 彩金
 * @property int $record_count 資料計數
 * @property numeric $bak 備用
 * @property numeric $bak1 備用
 * @property numeric|null $percentage 分成百分比
 * @property numeric|null $share_amount 分成金額
 * @property string|null $share_range 分成區間
 * @property numeric $v_jackpot v_jackpot
 * @property numeric $cost 成本
 * @property int $correction 改正標示
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Game|null $game
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorCustomizedItem> $vendorCustomizedItems
 * @property-read int|null $vendor_customized_items_count
 *
 * @method static Builder<static>|SummaryBetRecord newModelQuery()
 * @method static Builder<static>|SummaryBetRecord newQuery()
 * @method static Builder<static>|SummaryBetRecord query()
 *
 * @mixin \Eloquent
 */
class SummaryBetRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 報表排除的遊戲商 id（config `custom.report.excluded_vendor_ids`，源自 env `SR11_NO_PRESELECTION`）。
     *
     * 代理／站點看板凡是聚合本表都要套 `->whereNotIn('<alias>.vendor_id', self::reportExcludedVendorIds())`，
     * 讓看板與 SR11 平台對帳表預設不勾選這批遊戲商時的數字一致。空清單時 whereNotIn 編譯為恆真，等同不過濾。
     *
     * @return array<int, int>
     */
    public static function reportExcludedVendorIds(): array
    {
        return config('custom.report.excluded_vendor_ids', []);
    }

    /**
     * `win_lose` 存的是派彩（不是淨輸贏）的遊戲商：4D 類的 HAOLONG（4）、BOLAKK4D（104）、NEW4D2026（181）。
     *
     * 一般遊戲商的 `win_lose` 是會員淨輸贏：派彩 ＝ bet ＋ win_lose、玩家輸贏 ＝ win_lose。
     * 這三家的 `win_lose` 記的是中獎金額（沒中就是 0），所以派彩 ＝ win_lose、玩家輸贏 ＝ win_lose − bet
     * （使用者 2026-10-02 指定，各家寫入端的出處見 AgentGameDetailService 的 class docblock）。
     *
     * 報表要算派彩或玩家輸贏時一律用 `betMetricSelects()`，不要各自寫 CASE。
     *
     * @return array<int, int>
     */
    public static function winLoseIsPayoutVendorIds(): array
    {
        return [
            VendorIdEnum::HAOLONG->value,
            VendorIdEnum::BOLAKK4D->value,
            VendorIdEnum::NEW4D2026->value,
        ];
    }

    /**
     * 注單統計五欄的 SELECT 片段（SR29 代理遊戲明細表、SR28 代理營運彙總表共用，口徑只有一份）。
     *
     * - `bet_count` ＝ SUM(record_count)
     * - `bet_amount`、`valid_bet` 皆為 SUM(bet)：本表的 `bet` 寫入時就是有效下注
     *   （SummaryBetRecordService 以 `SUM(valid_bet) as bet` 彙總），沒有原始下注額，兩欄恆等。
     * - `payout`、`member_win_lose` 依 `winLoseIsPayoutVendorIds()` 逐列判斷，所以跨遊戲商分組、
     *   鎖定單一遊戲商、整體合計三種情境共用同一段 SQL 都正確。
     *
     * @param  string  $alias  summary_bet_records 在查詢中的別名
     * @return array{0: string, 1: array<int, int>} [SELECT 片段, 依出現順序的綁定值]
     */
    public static function betMetricSelects(string $alias = 'sbr'): array
    {
        $special = self::winLoseIsPayoutVendorIds();
        $placeholders = implode(', ', array_fill(0, count($special), '?'));

        $sql = implode(', ', [
            "SUM({$alias}.record_count) as bet_count",
            "SUM({$alias}.bet) as bet_amount",
            "SUM({$alias}.bet) as valid_bet",
            "SUM(CASE WHEN {$alias}.vendor_id IN ({$placeholders})
                      THEN {$alias}.win_lose
                      ELSE {$alias}.bet + {$alias}.win_lose END) as payout",
            "SUM(CASE WHEN {$alias}.vendor_id IN ({$placeholders})
                      THEN {$alias}.win_lose - {$alias}.bet
                      ELSE {$alias}.win_lose END) as member_win_lose",
        ]);

        // 兩段 CASE 各用一組 IN 佔位符，綁定值要給兩次（依 SQL 中出現的順序）
        return [$sql, [...$special, ...$special]];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function vendorCustomizedItems(): HasMany
    {
        return $this->hasMany(VendorCustomizedItem::class, 'vendor_id', 'vendor_id');
    }
}
