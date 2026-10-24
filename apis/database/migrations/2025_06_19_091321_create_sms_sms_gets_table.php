<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('sms_sms_gets', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('mobile', 191)->comment('手機號碼');
            $table->string('text', 191)->comment('簡訊內容');
            $table->string('stats', 191)->comment('發送成功或失敗');
            $table->string('error_code', 191)->comment('訊息代碼');
            $table->string('error_msg', 191)->comment('成功:訊息ID|使用點數|剩餘點數；錯誤:錯誤說明');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sms_sms_gets');
    }
};
