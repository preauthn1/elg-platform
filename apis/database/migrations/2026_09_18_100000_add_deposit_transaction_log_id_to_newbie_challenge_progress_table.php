<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'newbie_challenge_progress';

    private const string COLUMN = 'deposit_transaction_log_id';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 獨立欄位、不重用 deposit_order_id：該欄位語意固定是「觸發彩金核銷的儲值訂單 orders.id」，
        // 後台開分沒有 orders 列可指，混用會讓兩種 ID 空間互相污染（跟 event_v2_user_joins 同一慣例）。
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger(self::COLUMN)->nullable()->default(null)->after('deposit_order_id')
                ->comment('觸發彩金核銷的後台開分 transaction_logs.id（target/tickets#4352，僅代理組別設置 AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT 開啟時可能非 null）');
        });

        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            throw new RuntimeException('newbie_challenge_progress.deposit_transaction_log_id 欄位新增失敗');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
