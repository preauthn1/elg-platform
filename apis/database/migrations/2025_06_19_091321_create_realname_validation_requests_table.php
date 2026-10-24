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
        SchemaMonitor::create('realname_validation_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 100)->index()->comment('會員帳號');
            $table->integer('validator_id')->nullable()->comment('審核人員ID');
            $table->string('realname', 100)->comment('真實姓名');
            $table->string('bank_name', 100)->comment('銀行名稱');
            $table->string('bank_account', 100)->comment('銀行帳號');
            $table->tinyInteger('status')->comment('狀態, 0: 待審核, 1: 通過, 2: 拒絕');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('realname_validation_requests');
    }
};
