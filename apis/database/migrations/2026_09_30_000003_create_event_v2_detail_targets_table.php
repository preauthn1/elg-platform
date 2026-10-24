<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'event_v2_detail_targets';

    /**
     * Run the migrations.
     *
     * 活動 V2 目標用戶（target/tickets#4234，所有活動類型共用）：一檔活動只開放給指定會員或指定會員群組。
     * 結構比照風控會員限制 member_restriction_rules 的 target_type／target_id（morph alias member／member_group），
     * 一列一個勾選；一檔活動沒有任何一列＝不限制，六站現存活動零回填、行為不變。
     * 只存勾選的 id、不展開群組成員：參加當下才比對會員 id 與其 member_group_id，群組調整即時生效。
     * index(target_type, target_id) 給前台「這個會員看得到哪些活動」反查用。
     */
    public function up(): void
    {
        SchemaMonitor::create(self::TABLE, function (Blueprint $table) {
            $table->comment('活動 V2 目標用戶：指定會員或會員群組（無列＝不限制）');
            $table->increments('id');
            $table->unsignedInteger('event_v2_detail_id')->comment('event_v2_details.id');
            $table->string('target_type', 20)->comment('目標型別 morph alias，member:會員 member_group:會員群組');
            $table->unsignedInteger('target_id')->comment('members.id 或 member_groups.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->unique(['event_v2_detail_id', 'target_type', 'target_id'], 'event_v2_detail_targets_detail_target_unique');
            $table->index(['target_type', 'target_id'], 'event_v2_detail_targets_target_index');
        });
        if (!Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('event_v2_detail_targets 資料表建立失敗');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists(self::TABLE);
    }
};
