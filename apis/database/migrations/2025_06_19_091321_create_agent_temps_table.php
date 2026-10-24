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
        SchemaMonitor::create('agent_temps', function (Blueprint $table) {
            $table->comment('代理; ID:3~100預設給系統使用, 如System, Auto, unlimit, ....');
            $table->increments('id')->comment('系統編號');
            $table->integer('general_agent_id')->default(0)->comment('總代理');
            $table->integer('parent')->index()->comment('上層代理系統編號(Agent)');
            $table->string('username', 32)->unique()->comment('姓名');
            $table->string('hierarchy_path', 191)->nullable()->index()->comment('新版hierarchy結構，存成路徑式的數值');
            $table->tinyInteger('level')->default(0)->index();
            $table->integer('customized_setting_group_id')->nullable()->default(1)->index()->comment('個性化設置_組別ID (agent_groups 資料表)');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_temps');
    }
};
