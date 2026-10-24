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
        SchemaMonitor::create('agent_migrate_logs', function (Blueprint $table) {
            $table->comment('代理; ID:3~100預設給系統使用, 如System, Auto, unlimit, ....');
            $table->increments('id')->comment('系統編號');
            $table->dateTime('migrate_datetime')->nullable()->index()->comment('轉移日期');
            $table->tinyInteger('type')->default(0)->index()->comment('身份; 1:代理, 2:會員');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('orig_agent_id')->default(0)->index()->comment('原代理系統編號');
            $table->text('orig_hierarchy_path')->nullable()->comment('原代理階層');
            $table->text('memo')->nullable()->comment('方便技術記錄用');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_migrate_logs');
    }
};
