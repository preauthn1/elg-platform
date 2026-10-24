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
        SchemaMonitor::create('agent_vendor_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->comment('遊戲個性化設置編號');
            $table->integer('type_id')->comment('遊戲商類別編號; 目前是CJ7的代理傭金(1:slot, 2:score, 3:live, 4:sport, 5:other)');
            $table->integer('vendor_id')->comment('遊戲商編號');
            $table->integer('game_type_id')->comment('遊戲類別id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_vendor_categories');
    }
};
