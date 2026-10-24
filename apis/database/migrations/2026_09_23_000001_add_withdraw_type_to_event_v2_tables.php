<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string DETAILS = 'event_v2_details';

    private const string USER_JOINS = 'event_v2_user_joins';

    private const string COLUMN = 'withdraw_type';

    /**
     * Run the migrations.
     *
     * 活動 V2 領取去向（target/tickets#4231）：領取活動獎金時進主錢包（既有）或換成積分。
     * - event_v2_details.withdraw_type：運營設定。
     * - event_v2_user_joins.withdraw_type：參加當下自 details 凍結，領取時以此為準——
     *   與 reward／三個 limit 同理，運營中途改活動設定不得影響已參加的紀錄。
     * 有效期等型態專屬參數留在 withdraw_config JSON（expire），不另開欄位。
     * 兩欄預設 1＝主錢包（既有行為），六站現存活動與參加紀錄零回填；舊後台不送此欄位亦落預設。
     */
    public function up(): void
    {
        SchemaMonitor::table(self::DETAILS, function (Blueprint $table) {
            $table->unsignedTinyInteger(self::COLUMN)->default(1)->after('withdraw_config')
                ->comment('領取去向：1=主錢包（預設）2=積分，見 EventV2WithdrawTypeEnum');
        });

        if (!Schema::hasColumn(self::DETAILS, self::COLUMN)) {
            throw new RuntimeException('event_v2_details.withdraw_type 欄位新增失敗');
        }

        SchemaMonitor::table(self::USER_JOINS, function (Blueprint $table) {
            $table->unsignedTinyInteger(self::COLUMN)->default(1)->after('withdraw_limit')
                ->comment('參加當下凍結自 event_v2_details.withdraw_type，領取時以此為準');
        });

        if (!Schema::hasColumn(self::USER_JOINS, self::COLUMN)) {
            throw new RuntimeException('event_v2_user_joins.withdraw_type 欄位新增失敗');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table(self::USER_JOINS, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });

        SchemaMonitor::table(self::DETAILS, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
