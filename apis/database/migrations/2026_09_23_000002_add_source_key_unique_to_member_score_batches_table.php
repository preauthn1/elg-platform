<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'member_score_batches';

    private const string COLUMN = 'source_key';

    private const string INDEX = 'uniq_source_key';

    /**
     * Run the migrations.
     *
     * 同一張來源單據只能發一批積分（target/tickets#4231 第二輪審查）：活動 V2 積分型的參加紀錄一出生就是 COMPLETE，
     * 原本「同時只能有一筆 JOIN」的互斥網對它失效，防重複參加只剩 Redis 鎖（ttl 10 秒）——交易拖過 10 秒就可能
     * 對同一筆 event_v2_user_joins 發兩批。這裡在 DB 層補最後一道防線：
     * source_key 為虛擬生成欄「source_type:source_id」，source_id=0（人工調整，無單據）為 NULL 不受唯一鍵約束，
     * 其餘來源重複發放直接撞唯一鍵、整筆交易回滾。日後新來源（任務、簽到）帶自己的單據 id 即自動受保護。
     * 表尚未上正式站（六站零列），DDL 秒級。
     */
    public function up(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->string(self::COLUMN, 48)->nullable()
                ->virtualAs("IF(source_id > 0, CONCAT(source_type, ':', source_id), NULL)")
                ->after('source_id')
                ->comment('來源唯一鍵 source_type:source_id（虛擬欄）；source_id=0 為 NULL 不約束');
            $table->unique(self::COLUMN, self::INDEX);
        });

        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            throw new RuntimeException('member_score_batches.source_key 欄位新增失敗');
        }

        if (!Schema::hasIndex(self::TABLE, self::INDEX)) {
            throw new RuntimeException('member_score_batches.uniq_source_key 索引新增失敗');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
            $table->dropColumn(self::COLUMN);
        });
    }
};
