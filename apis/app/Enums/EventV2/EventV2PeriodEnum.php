<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

use Carbon\CarbonInterface;

/**
 * 活動 V2 每檔活動自己的週期，同一個值域給兩個欄位用（target/tickets#4234 紅包，目前只有紅包用）：
 * - event_v2_details.member_period 會員週期（工單「是否可重複參加」）：每人次數／金額
 *   （condition_config.member_times／member_amount）在哪個區間內累計；不勾參加即領獎、還在 JOIN 的紀錄，
 *   週期一過就由 app:init-event-v2 → AngpowPeriodResetService 重置（作廢、錢回收；紅包類型列 schedule `0 16 * * *` 觸發）。
 * - event_v2_details.total_period 全站週期（工單「包括期間」）：全站總次數 max_join／總金額 max_amount
 *   在哪個區間內累計，過了就重新開一池。只管統計不作廢。
 * 兩個各自獨立：「每日可抽 1 次、每週全站 50 次」＝member_period DAILY＋total_period WEEKLY（Frank 2026-09-30）。
 *
 * 跟 event_v2.schedule 不同：schedule 是「活動類型」一個 cron，到期把整個類型的參加紀錄重置；
 * 這兩欄是「單一活動」自己的週期。
 *
 * 換日線只有一條：日／週／月／年一律依前台時區 00:00 切（config custom.frontend.timezone），
 * 累計（bucket）與作廢（periodStart，AngpowPeriodResetService 用）都是它，與參加紀錄的 date／week／month 分桶、
 * EnforcesParticipationBlocks::resolveSchedulePeriod() 同一套；週從週一起算。
 * 必須：作廢不可改成另一條線（曾改成 UTC 00:00，會讓台灣 00:00～08:00 抽的那包算今天卻被當昨天作廢，2026-10-01 改回）。
 *
 * 必須：兩欄都只有 AngpowService 可設非 NONE，其他類型的週期由類型決定（Request 層擋）。
 * 必須：新增 case 只加不改既有值（DB 預設 0＝NONE，六站現存活動零回填）。
 */
enum EventV2PeriodEnum: int
{
    /** event_v2_details 的會員週期欄位名（工單「是否可重複參加」） */
    public const string MEMBER_FIELD = 'member_period';

    /** event_v2_details 的全站週期欄位名（工單「包括期間」） */
    public const string TOTAL_FIELD = 'total_period';

    /** 兩個週期欄位，Request／Controller 對兩欄做同樣處理時用 */
    public const array FIELDS = [self::MEMBER_FIELD, self::TOTAL_FIELD];

    case NONE = 0; // 不分週期：從活動開始累計、不作廢

    case DAILY = 1;

    case WEEKLY = 2;

    case MONTHLY = 3;

    case YEARLY = 4;

    /** 本週期起點，時區跟著傳入的時間走（一律傳前台時間）；NONE 回 null。收 CarbonInterface：AbstractEventV2 用 Carbon\Carbon、排程用 Illuminate 的子類 */
    public function periodStart(CarbonInterface $now): ?CarbonInterface
    {
        return match ($this) {
            self::NONE => null,
            self::DAILY => $now->copy()->startOfDay(),
            self::WEEKLY => $now->copy()->startOfWeek(),
            self::MONTHLY => $now->copy()->startOfMonth(),
            self::YEARLY => $now->copy()->startOfYear(),
        };
    }

    /**
     * 參加紀錄查詢裡對應的分桶欄位與本期的值（AbstractEventV2::validate() 撈 participations 時算好的
     * date／week／month／year）：[欄位, 值]；NONE 回 null＝不分桶。
     *
     * @return array{0: string, 1: string}|null
     */
    public function bucket(CarbonInterface $frontendNow): ?array
    {
        return match ($this) {
            self::NONE => null,
            self::DAILY => ['date', $frontendNow->toDateString()],
            self::WEEKLY => ['week', $frontendNow->format('o-W')],
            self::MONTHLY => ['month', $frontendNow->format('Y-m')],
            self::YEARLY => ['year', $frontendNow->format('Y')],
        };
    }

    public function isNone(): bool
    {
        return $this === self::NONE;
    }
}
