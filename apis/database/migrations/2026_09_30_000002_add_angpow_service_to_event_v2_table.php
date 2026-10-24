<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string HANDLER = 'AngpowService';

    private const int ID = 16;

    /** UTC 16:00＝台灣 00:00，與 vplus33 每日登入／每日儲值組合相同 */
    private const string SCHEDULE = '0 16 * * *';

    /**
     * Run the migrations.
     *
     * 活動 V2 新增「紅包」活動類型（target/tickets#4234）：花積分抽一次亂數現金，進主錢包。
     * schedule 填 `0 16 * * *`（cron 按 UTC 算＝台灣 00:00，與實際營運每日活動的站一致）：走既有 V2 機制，
     * 到點 check:event_v2_schedule → app:init-event-v2，InitEventV2 看到紅包類型就交給 AngpowPeriodResetService
     * 依每檔活動自己的 member_period 只清上一期的 JOIN（不是整個類型全清）。每天觸發一次即可，每週／每月紅包
     * 只有週一／1 號那次撈得到上一期的紀錄。前台時區不是 +8 的站要跟著改這個值。
     * 以 handler_class 判斷是否已存在（dev 可能有人先手動加過），比照 2026_08_03 首次儲值的做法。
     */
    public function up(): void
    {
        if (!DB::table('event_v2')->where('handler_class', self::HANDLER)->exists()) {
            DB::table('event_v2')->insert([
                'id' => self::ID,
                'name' => '紅包',
                'schedule' => self::SCHEDULE,
                'handler_class' => self::HANDLER,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('event_v2')->where('handler_class', self::HANDLER)->delete();
    }
};
