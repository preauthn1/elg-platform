<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 積分異動紀錄表（target/tickets#4233）——只增不改，是 #4232 積分紀錄頁的唯一資料源。
 *
 * 四種來源（活動獎勵發放／活動消耗／人工調整／到期失效）全部寫進同一張表，
 * 不可分別去活動表、調整紀錄各撈一份再合併（#4232 明文要求）。
 * 一筆 log 恆對應一個批次（batch_id 必填）：一次扣除跨兩批就拆成兩筆，
 * 否則對不回各批的到期日（#4232 測試 C-T13）。
 *
 * 積分帳本只有本表，不另寫 wallet_transaction_logs——比照禮碼有自己的 rolling_chip_logs。
 *
 * ⚠️ 本表為只增不減的日誌型表。列數成長到 SchemaMonitor 門檻（預設 50 萬）時，
 * 須加進 config/custom.php 的 schema_monitor.row_count_exempt_tables，
 * 否則正式站的 ALTER 會被靜默跳過。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_score_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('batch_id')->comment('對應批次 member_score_batches.id');
            $table->tinyInteger('type')->comment('ScoreLogTypeEnum 做了什麼：1 系統發放 2 系統消耗 3 人工調整 4 到期失效；哪個系統看 reference_type');
            $table->tinyInteger('direction')->comment('1:增加 2:扣除');
            $table->decimal('score', 18, 4)->comment('本筆異動積分（恆正，方向看 direction）');
            $table->decimal('before_score', 18, 4)->comment('異動前該會員可用積分總額');
            $table->decimal('after_score', 18, 4)->comment('異動後該會員可用積分總額');
            $table->dateTime('expired_at')->nullable()->comment('該批到期日（UTC）；扣除類留空');
            $table->string('reference_type', 32)->default('')->comment('參照系統 ScoreSourceTypeEnum：空=無（人工／到期）、event_v2_user_join=event_v2_user_joins.id；日後 event_v3／任務等只加 enum case');
            $table->integer('reference_id')->default(0)->comment('參照單據 id，指向的表依 reference_type；無參照為 0');
            $table->string('operator', 191)->nullable()->comment('操作者帳號；系統到期失效為空');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');

            // #4232 明細彈窗：某會員某期間的異動
            $table->index(['member_id', 'created_at'], 'idx_member_created');
            // #4232 主表彙總：累計獲得／已過期
            $table->index(['member_id', 'type'], 'idx_member_type');
            // 反查：某張單（某次活動參加、日後某任務）產生的所有積分異動
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
        });

        // SchemaMonitor 靜默跳過防護：斷言建表生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasTable('member_score_logs')) {
            throw new RuntimeException('member_score_logs 建表未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_score_logs');
    }
};
