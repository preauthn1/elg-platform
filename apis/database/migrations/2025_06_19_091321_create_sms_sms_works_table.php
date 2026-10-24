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
        SchemaMonitor::create('sms_sms_works', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('mobile', 191)->comment('手機號碼');
            $table->string('text', 191)->comment('簡訊內容');
            $table->string('status', 191)->comment('發送成功或失敗,1:成功');
            $table->string('transation_id', 191)->comment('交易編號');
            $table->string('response_message', 191)->comment('成功:狀態|交易編號；錯誤:錯誤說明');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sms_sms_works');
    }
};
