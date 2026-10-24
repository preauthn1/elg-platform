<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 站內信多語系內容 工單4152
 *
 * 形狀比照 platform_announcement_languages（最新消息）：一封信對多筆語系列，
 * 前台以當前 language_id 取對應內容。
 *
 * 與最新消息的差異：本表未填的語系會 fallback 到英文（英文為必填），
 * 避免外語會員收到空白信；最新消息則是取不到就空。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_mail_languages', function (Blueprint $table) {
            $table->comment('站內信多語系內容');
            $table->increments('id')->comment('PK流水號');
            $table->integer('member_mail_id')->comment('FK對應 member_mails.id');
            $table->integer('language_id')->comment('語系ID(參考 languages.id)');
            $table->string('title', 200)->comment('標題');
            $table->text('content')->comment('內容');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            // 一封信的同一語系只會有一筆，查詢也固定以此組合命中
            $table->unique(['member_mail_id', 'language_id'], 'uk_member_mail_languages_mail_language');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_mail_languages');
    }
};
