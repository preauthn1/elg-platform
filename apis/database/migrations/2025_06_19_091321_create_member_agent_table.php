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
        SchemaMonitor::create('member_agent', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->nullable()->unique()->comment('玩家編號');
            $table->unsignedInteger('parent_id')->nullable()->index()->comment('上層代理系統編號(User)');
            $table->unsignedInteger('topper_member_id')->nullable()->default(0)->index()->comment('上層玩家編號。紀錄上層玩家編號，如果該欄位為null代表為最上層');
            $table->tinyInteger('agent_mode')->nullable()->default(1)->comment('代理模式類型。 1:無限代、2:淨利代....');
            $table->unsignedTinyInteger('agent_level')->nullable()->default(1)->index()->comment('會員層級');
            $table->text('agent_relation')->nullable()->comment('代理鏈結，格式:id_id_id(包含自己)');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_agent');
    }
};
