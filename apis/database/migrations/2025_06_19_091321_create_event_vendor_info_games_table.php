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
        SchemaMonitor::create('event_vendor_info_games', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_vendor_info_id')->default(0)->comment('event_vendor_infos.id');
            $table->integer('game_id')->default(0)->comment('games.id');
            $table->boolean('is_new')->default(false)->comment('是否為新遊戲;0:不是,1:是');
            $table->boolean('is_hot')->default(false)->comment('是否為熱門遊戲;0:不是,1:是');
            $table->integer('sort')->default(0)->comment('排序');
            $table->boolean('status')->default(false)->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('管理員編號');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_vendor_info_games');
    }
};
