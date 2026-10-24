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
        SchemaMonitor::create('validate_tokens', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->integer('member_id')->index()->comment('會員系統編號(members)');
            $table->tinyInteger('type_id')->default(0)->comment('類型(十位數-1:資料驗證, 2:忘記密碼, 3:找回密碼, 4:洗分驗證, 5:支付密碼, 6:忘記支付密碼, 7:洗分驗證, 8:一頁註冊,9:變更手機或email，個位數-1:手機2:Email)');
            $table->string('info', 191)->nullable()->comment('驗證的資料');
            $table->string('info_2', 128)->nullable()->comment('其他資訊');
            $table->string('token', 80)->index()->comment('Token');
            $table->dateTime('expire_date')->comment('過期日期');
            $table->boolean('status')->comment('狀態');
            $table->integer('times')->default(1)->comment('簡訊發送次數');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('validate_tokens');
    }
};
