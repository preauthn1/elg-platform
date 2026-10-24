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
        SchemaMonitor::create('agent_group_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->comment('遊戲商群組ID(vendor_customize_group.id)');
            $table->string('tag_name', 191)->comment('玩家帳號');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:使用中');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_group_tags');
    }
};
