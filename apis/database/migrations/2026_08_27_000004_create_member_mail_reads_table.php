<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 站內信已讀紀錄 工單4152
 *
 * 逐封記錄，而非既有平台公告的 last_read_time 單一時間戳：點開 A 信不應
 * 讓 B 信一併變成已讀，這才符合信箱該有的行為。副產物是後台可統計單封信的
 * 觸達率（本表筆數 ÷ member_mails.recipient_count）。
 *
 * 只有會員真的讀了才寫入，未讀者不佔空間。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_mail_reads', function (Blueprint $table) {
            $table->comment('站內信已讀紀錄（逐封）');
            $table->increments('id')->comment('PK流水號');
            $table->integer('member_mail_id')->comment('FK對應 member_mails.id');
            $table->integer('member_id')->comment('已讀會員ID');
            $table->timestamp('read_at')->useCurrent()->comment('已讀時間');
            // 一人一封只記一次；前台判定未讀、後台統計觸達率都走這個組合
            $table->unique(['member_mail_id', 'member_id'], 'uk_member_mail_reads_mail_member');
            // 前台計算未讀數時以會員反查其已讀的信
            $table->index('member_id', 'idx_member_mail_reads_member');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_mail_reads');
    }
};
