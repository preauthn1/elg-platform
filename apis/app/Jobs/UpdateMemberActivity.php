<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SafelyDispatchable;
use App\Models\MemberStatistic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 更新會員最後活動時間到 member_statistics，以 type 區分欄位：
 *   TYPE_REQUEST → last_activity_at（最後請求 API 時間，由 TrackMemberActivity middleware 緩存降頻後派發）
 *   TYPE_BET     → last_bet_at（最後投注時間，由 TotalBetRecordService 每筆投注派發，不節流）
 *
 * 時間欄位只前進：僅在新值較新（或原為 null）時更新，避免多 worker 亂序處理把時間蓋成較舊值。
 *
 * ⚠️ 別跟 `member_activities` / `member_activities_latest` 混淆：那是**另一條軌**，由獨立部署的
 * RD-Studio-01 排程寫入（舊代理後台「會員活動紀錄」選單讀它），本 job 一列都不會寫進去。
 * 兩軌並存、都還在運作，查「會員最後活動時間」前先確認問題來自舊後台還是 APIs。
 */
class UpdateMemberActivity implements ShouldQueue
{
    use Queueable;
    use SafelyDispatchable;

    public const string TYPE_REQUEST = 'request';

    public const string TYPE_BET = 'bet';

    public function __construct(
        public int $memberId,
        public string $type,
        public string $occurredAt,
    ) {}

    public function handle(): void
    {
        Log::info('UpdateMemberActivity handling', [
            'member_id' => $this->memberId,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
        ]);

        $column = match ($this->type) {
            self::TYPE_REQUEST => 'last_activity_at',
            self::TYPE_BET => 'last_bet_at',
        };

        // 並發安全建行（INSERT IGNORE），重複不報錯
        MemberStatistic::insertOrIgnore([
            'member_id' => $this->memberId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 只前進：null 或舊值才更新，防亂序覆蓋
        MemberStatistic::query()
            ->where('member_id', $this->memberId)
            ->where(function ($query) use ($column) {
                $query->whereNull($column)
                    ->orWhere($column, '<', $this->occurredAt);
            })
            ->update([$column => $this->occurredAt]);
    }
}
