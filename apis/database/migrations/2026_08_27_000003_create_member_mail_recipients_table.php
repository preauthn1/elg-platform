<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 站內信收件人 工單4152
 *
 * 只有 send_type=1（指定會員）與 2（會員群組）會寫入本表：存檔當下把對象
 * 展開成具體會員，收件人自此固定。這讓「會員從 A 群組轉到 B 群組」時，
 * 先前寄給 A 群組的信仍留在他信箱裡——信寄出就屬於收件人，不因群組異動而消失。
 *
 * send_type=3（全平臺）與 4（代理及子代理）刻意不寫入：涵蓋數十萬會員，
 * 每封信展開一份完整名冊會使資料量無法收斂，改於查詢時動態比對組織／代理，
 * 並以註冊時間排除發信後才加入的會員。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_mail_recipients', function (Blueprint $table) {
            $table->comment('站內信收件人（僅指定會員與會員群組類型展開存放）');
            $table->increments('id')->comment('PK流水號');
            $table->integer('member_mail_id')->comment('FK對應 member_mails.id');
            $table->integer('member_id')->comment('收件會員ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            // 前台以會員反查有哪些信，帶上 mail_id 讓查詢由索引覆蓋不必回表
            $table->index(['member_id', 'member_mail_id'], 'idx_member_mail_recipients_member');
            // 後台反查單封信的收件人（觸達率分母），並防同一封信重複寫入同一會員
            $table->unique(['member_mail_id', 'member_id'], 'uk_member_mail_recipients_mail_member');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_mail_recipients');
    }
};
