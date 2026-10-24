<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 驗證碼郵件樣板 工單4286
 *
 * 形狀比照 sms_texts：一個組別 × 樣板類型 × 語系一筆；差別是簡訊以國家分、郵件以語系分。
 * language_id=0 為「全語系」預設列，查不到指定語系時退回這一列（慣例同 advertise_languages）。
 * 樣板為純文字，佔位符只支援 {code}（驗證碼）與 {email}（收件信箱），見 App\Services\EmailTextService。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('email_texts', function (Blueprint $table) {
            $table->comment('驗證碼郵件樣板');
            $table->increments('id')->comment('流水號');
            $table->integer('email_gateway_group_id')->comment('電子郵件設置組別ID(agent_groups.id, agent_group_type=7)');
            $table->tinyInteger('type_id')->comment('樣板類型; 對齊 params.type=sms_text_type, 見 EmailTextTypeEnum');
            $table->integer('language_id')->default(0)->comment('語系ID(languages.id); 0=全語系預設列');
            $table->string('subject', 200)->comment('郵件主旨');
            $table->text('content')->comment('郵件內文(純文字)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID(agents.id)');
            // 查詢固定以「組別＋類型＋語系」命中；insertOrIgnore 補預設列也靠它擋重複
            $table->unique(['email_gateway_group_id', 'type_id', 'language_id'], 'uk_email_texts_group_type_language');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('email_texts');
    }
};
