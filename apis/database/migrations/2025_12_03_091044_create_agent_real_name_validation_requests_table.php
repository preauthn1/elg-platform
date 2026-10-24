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
        SchemaMonitor::create('agent_real_name_validation_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('代理ID');
            $table->integer('validator_id')->nullable()->comment('審核人員ID');
            $table->string('real_name', 100)->comment('真實姓名');
            $table->integer('bank_id')->comment('銀行ID');
            $table->string('bank_account', 100)->comment('銀行帳號');
            $table->tinyInteger('status')->comment('狀態, 0: 待審核, 1: 通過, 2: 拒絕');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_real_name_validation_requests');
    }
};
