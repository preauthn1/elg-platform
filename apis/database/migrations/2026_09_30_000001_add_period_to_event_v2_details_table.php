<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'event_v2_details';

    /** 會員週期（工單「是否可重複參加」）：每人次數／金額的累計區間，也是不勾參加即領獎時的作廢週期 */
    private const string MEMBER_PERIOD = 'member_period';

    /** 全站週期（工單「包括期間」）：全站總次數 max_join／總金額 max_amount 的累計區間 */
    private const string TOTAL_PERIOD = 'total_period';

    /**
     * Run the migrations.
     *
     * 活動 V2 單一活動的兩個週期（target/tickets#4234 紅包），值域同為 EventV2PeriodEnum：0＝不分週期、1 日、2 週、3 月、4 年。
     * 兩個週期管的對象不同（Frank 2026-09-30）：member_period 管單一會員多久能再抽，total_period 管全站的池子多久重新開一池；
     * 「每日可抽 1 次、每週全站 50 次／5000 元」就是 member_period=1＋total_period=2。
     * 其他類型的週期仍由 event_v2.schedule（類型層級）決定，這兩欄維持 0。
     * 開欄位不塞 condition_config：排程要用 member_period 找該作廢的活動，值域固定，比照 withdraw_type。
     * 預設 0，六站現存活動零回填；舊後台不送此欄位亦落預設。
     */
    public function up(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedTinyInteger(self::MEMBER_PERIOD)->default(0)->after('withdraw_type')
                ->comment('會員週期（可重複參加）：0=不分週期 1=日 2=週 3=月 4=年，見 EventV2PeriodEnum；目前只有紅包用');
            $table->unsignedTinyInteger(self::TOTAL_PERIOD)->default(0)->after(self::MEMBER_PERIOD)
                ->comment('全站週期（包括期間）：0=不分週期 1=日 2=週 3=月 4=年，見 EventV2PeriodEnum；目前只有紅包用');
        });
        foreach ([self::MEMBER_PERIOD, self::TOTAL_PERIOD] as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException("event_v2_details.{$column} 欄位新增失敗");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn([self::MEMBER_PERIOD, self::TOTAL_PERIOD]);
        });
    }
};
