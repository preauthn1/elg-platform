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
        SchemaMonitor::create('vendor_images', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->comment('代理組別編號');
            $table->string('vendor_id', 191)->index()->comment('遊戲商編號');
            $table->string('name', 191)->index()->comment('遊戲商名稱');
            $table->string('image', 191)->comment('遊戲商圖片');
            $table->string('app_icon', 191)->comment('遊戲商app的圖示');
            $table->decimal('valid_bet_rate', 4)->nullable()->comment('有效投注轉換比例');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_images');
    }
};
