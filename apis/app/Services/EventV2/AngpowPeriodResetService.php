<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 紅包週期到期重置（target/tickets#4234）：把「上一期參加、到現在還停在 JOIN」的紀錄作廢——
 * 活動錢包歸零回收、已扣積分不退，動作與其他類型的 app:init-event-v2 完全相同（都呼叫 EventV2Service::initEventV2()），
 * 只差挑紀錄的方式。
 *
 * 為什麼不直接用「整個類型的 JOIN 全清」：紅包的週期是每檔活動自己的 event_v2_details.member_period
 * （可重複參加：日／週／月／年／不分），同一個紅包類型底下可以同時有每日紅包和每週紅包，
 * 所以只能逐檔看週期、只清 created_at 早於「本期起點」的紀錄。
 *
 * 觸發：走既有的 V2 機制——紅包類型列 event_v2.schedule 填 `0 16 * * *`（UTC 16:00＝台灣 00:00，
 * 與實際營運每日活動的站一致），check:event_v2_schedule 到點呼叫 app:init-event-v2，InitEventV2 看到紅包就轉到這裡。
 * 本期起點依前台時區 00:00 切（EventV2PeriodEnum::periodStart），與「今天抽過沒」同一條換日線；
 * 每天觸發一次就夠：每週紅包只有週一那次會撈到上週的紀錄，其他天「本週起點」之後的紀錄都不符合。
 * 冪等：只挑 status=JOIN 且 created_at 早於本期起點的紀錄，重跑不重複。
 *
 * 必須：交易內以 lockForUpdate() 重取該筆且確認仍是 JOIN 才動（與前台領取 EventV2Controller::withdraw() 對稱鎖同一列）。
 * 交易外撈到的清單是舊照片：會員在週期邊界那一秒剛領完（COMPLETE），拿舊資料改寫會把它蓋成作廢、多寫一筆假回收。
 * 單筆失敗只記錯誤、繼續下一筆。
 */
final class AngpowPeriodResetService
{
    public function __construct(
        private readonly EventV2Service $eventV2Service,
    ) {}

    /**
     * @param  int|null  $eventV2Id  只處理這個類型底下的活動（InitEventV2 帶入）；null＝所有設了會員週期的活動
     * @param  callable(string): void|null  $dryRunLine  dry-run 時每筆會重置的紀錄回報一行
     * @return array{reset: int, skipped: int, failed: int}
     */
    public function run(bool $dryRun = false, ?int $eventV2Id = null, ?callable $dryRunLine = null): array
    {
        $frontendNow = Carbon::now()->toFrontendTime();

        $details = EventV2Detail::query()
            ->where('member_period', '!=', EventV2PeriodEnum::NONE->value)
            ->when($eventV2Id !== null, fn ($query) => $query->where('event_v2_id', $eventV2Id))
            ->get(['id', 'member_period']);

        $reset = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($details as $detail) {
            $start = $detail->member_period->periodStart($frontendNow);
            if ($start === null) {
                continue;
            }

            $joins = EventV2UserJoin::query()
                ->where('event_v2_detail_id', $detail->id)
                ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
                ->where('created_at', '<', $start->copy()->toSystemTime()->toDateTimeString())
                ->get(['id', 'event_v2_detail_id', 'member_id', 'status', 'withdraw', 'created_at']);

            foreach ($joins as $join) {
                if ($dryRun) {
                    if ($dryRunLine !== null) {
                        $dryRunLine("[dry-run] detail {$detail->id} member_period {$detail->member_period->name} join {$join->id} member {$join->member_id} joined {$join->created_at}");
                    }
                    $reset++;

                    continue;
                }

                try {
                    $this->resetOne($join) ? $reset++ : $skipped++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('紅包週期重置失敗', [
                        'event_v2_detail_id' => $detail->id,
                        'user_join_id' => $join->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['reset' => $reset, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** 回 false＝鎖定讀重取時已不是 JOIN（領取先一步完成），這筆跳過 */
    private function resetOne(EventV2UserJoin $join): bool
    {
        return DB::transaction(function () use ($join): bool {
            $locked = EventV2UserJoin::query()
                ->whereKey($join->id)
                ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return false;
            }

            $member = Member::findOrFail($locked->member_id);
            $locked->withdraw = 0;
            $locked->status = EventV2UserJoinStatusEnum::SYSTEM_INIT->value;
            $this->eventV2Service->initEventV2($member, $locked);

            return true;
        });
    }
}
