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
        SchemaMonitor::create('vendor_customized_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理組別vendor_customize_group_id');
            $table->tinyInteger('item_type')->default(0)->comment('入口類型: 0 : 遊戲商, 1 : 遊戲, 2 : 類型, 3 : 站內連結');
            $table->integer('vendor_id')->default(0)->index()->comment('遊戲商ID');
            $table->integer('game_id')->default(0)->comment('遊戲ID');
            $table->integer('type_id')->default(0)->comment('遊戲類型ID');
            $table->string('path', 191)->default('')->comment('連結');
            $table->string('name', 191)->nullable()->comment('遊戲商名稱');
            $table->string('image', 191)->nullable()->comment('遊戲商圖檔路徑');
            $table->string('front_page_image', 191)->nullable()->comment('遊戲商前置頁圖片');
            $table->string('app_icon', 191)->nullable()->comment('遊戲商圖檔路徑');
            $table->tinyInteger('is_new')->nullable()->comment('新遊戲標記');
            $table->tinyInteger('is_hot')->nullable()->comment('熱門遊戲標記');
            $table->tinyInteger('only_one_game')->nullable()->comment('獨立入口');
            $table->tinyInteger('status')->nullable()->comment('前台顯示狀態 0: 關閉, 1: 正常, 2: coming soon');
            $table->tinyInteger('sorts')->default(0)->index()->comment('排序權重');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');

            $table->unique(['agent_group_id', 'item_type', 'vendor_id', 'game_id', 'type_id', 'path'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_customized_items');
    }
};
