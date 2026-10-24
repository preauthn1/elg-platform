<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string USER_JOINS_TABLE = 'event_v2_user_joins';

    private const string DEPOSIT_LOG_COLUMN = 'deposit_transaction_log_id';

    private const string DEPOSIT_LOG_INDEX = 'event_v2_user_joins_deposit_transaction_log_id_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 獨立欄位、不重用 order_id：order_id 語意固定是「觸發活動的儲值訂單 ID」，
        // 後台開分沒有 orders 列可指，混進同一欄位會讓兩種 ID 空間互相污染
        // （任何用 order_id join orders 表的地方都會被後台開分的 transaction_logs.id 弄錯）。
        SchemaMonitor::table(self::USER_JOINS_TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger(self::DEPOSIT_LOG_COLUMN)->nullable()->default(null)->after('order_id')
                ->comment('觸發活動的後台開分 transaction_logs.id（target/tickets#4352，僅代理組別設置 AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT 開啟時可能非 null）');
        });

        if (!Schema::hasColumn(self::USER_JOINS_TABLE, self::DEPOSIT_LOG_COLUMN)) {
            throw new RuntimeException('event_v2_user_joins.deposit_transaction_log_id 欄位新增失敗');
        }

        // 與同表 order_id 的索引定位對稱（建表 migration 2025_06_19_092000 對 order_id 也是各自獨立 index）：
        // ChecksDepositAndPeriodQualification::getTodayBackendDeposits() 以
        // `member_id + deposit_transaction_log_id IS NOT NULL` 子查詢排除已用過的後台開分，跟著建 index。
        // 欄位／索引分兩次呼叫：同一 Blueprint 內先加欄位再對它建索引，部分 driver 會因欄位尚未存在而失敗。
        if (!SchemaMonitor::hasIndex(self::USER_JOINS_TABLE, self::DEPOSIT_LOG_INDEX)) {
            SchemaMonitor::table(self::USER_JOINS_TABLE, function (Blueprint $table) {
                $table->index([self::DEPOSIT_LOG_COLUMN], self::DEPOSIT_LOG_INDEX);
            });
        }

        if (!SchemaMonitor::hasIndex(self::USER_JOINS_TABLE, [self::DEPOSIT_LOG_COLUMN])) {
            throw new RuntimeException('event_v2_user_joins.deposit_transaction_log_id 索引新增失敗');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table(self::USER_JOINS_TABLE, function (Blueprint $table) {
            if (SchemaMonitor::hasIndex(self::USER_JOINS_TABLE, self::DEPOSIT_LOG_INDEX)) {
                $table->dropIndex(self::DEPOSIT_LOG_INDEX);
            }

            $table->dropColumn(self::DEPOSIT_LOG_COLUMN);
        });
    }
};
