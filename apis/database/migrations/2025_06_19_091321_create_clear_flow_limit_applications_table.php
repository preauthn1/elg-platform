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
        SchemaMonitor::create('clear_flow_limit_applications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('flow_limit_id')->default(0)->comment('flow_limits.id');
            $table->integer('status')->default(0)->comment('狀態; 0: 待審核, 1: 成功, 2:拒絕');
            $table->string('remark', 191)->nullable()->comment('備注');
            $table->integer('operator')->default(0)->index()->comment('agents.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('clear_flow_limit_applications');
    }
};
