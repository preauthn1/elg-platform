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
        SchemaMonitor::create('agent_status_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('target_type')->index()->comment('1: 代理商, 2: 會員');
            $table->integer('target_id')->index()->comment('target_type = 1: agents.id, target_type = 2: members.id');
            $table->tinyInteger('before_status')->default(0)->comment('更改前狀態;0: 停用, 1: 啟用');
            $table->tinyInteger('after_status')->default(0)->comment('更改後狀態;0: 停用, 1: 啟用');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->default(0)->comment('管理員編號');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_status_logs');
    }
};
