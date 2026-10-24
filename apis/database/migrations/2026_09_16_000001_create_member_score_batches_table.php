<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 積分批次表（target/tickets#4233）——積分餘額的唯一真相。
 *
 * 積分（score）是活動的內部代幣，不是錢：不可提現、不抵注、無幣別。每次發放產生一列，
 * 各自帶有效期；會員可用積分＝所有 status=1 且未到期批次的 remaining_score 加總。
 * member_wallets 的 WalletEnum::CODE_SCORE 那列只是這張表的投影，對不上時以本表為準重算。
 *
 * 命名刻意用 score 不用 point：point 在本專案是「錢」的通用欄位名
 * （orders.point、promotion_wallet_logs.point 皆為金額），同名不同義會誤導維護者。
 *
 * 刻意沒有 currency_id：積分無幣別，多幣別的影響在 deposit 進活動前已被換算吸收。
 * 多幣別第五期補幣別欄位時請排除本表。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_score_batches', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('member_id')->comment('會員系統編號');
            $table->decimal('score', 18, 4)->comment('本批發放積分；業務規則限 2 位小數');
            $table->decimal('remaining_score', 18, 4)->comment('本批剩餘積分；業務規則限 2 位小數');
            $table->dateTime('expired_at')->nullable()->comment('到期時間（UTC；發放時刻+N小時後進位到下一個 10 分鐘刻度）；null=無限期，FIFO 排最後');
            $table->string('source_type', 32)->comment('來源系統 ScoreSourceTypeEnum：manual 後台人工、event_v2_user_join 活動 V2；日後任務／簽到等只加 enum case');
            $table->integer('source_id')->default(0)->comment('來源單據 id，指向的表依 source_type（event_v2_user_join=event_v2_user_joins.id）；manual 為 0');
            $table->tinyInteger('status')->default(1)->comment('1:有效 2:已用罄 3:已到期失效');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');

            // FIFO 扣點：撈某會員可用批次並依到期日排序
            $table->index(['member_id', 'status', 'expired_at'], 'idx_member_status_expired');
            // 到期排程：掃全站已過期且仍有剩餘
            $table->index(['status', 'expired_at'], 'idx_status_expired');
        });

        // SchemaMonitor 靜默跳過防護：斷言建表生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasTable('member_score_batches')) {
            throw new RuntimeException('member_score_batches 建表未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_score_batches');
    }
};
