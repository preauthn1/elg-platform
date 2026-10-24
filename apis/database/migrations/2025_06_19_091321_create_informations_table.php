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
        SchemaMonitor::create('informations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->index()->comment('agents.customized_setting_group_id');
            $table->integer('type')->default(0)->comment('圖片連結類型; 0:無連結, 1:連結至遊戲類型, 2:連結至遊戲, 3.連結至遊戲商, 4:外部連結, 5:內部連結');
            $table->string('content', 191)->default(' ')->comment('連結類型內容; 1:1(vendor_id), 2:1(game_id), 3:1(game_type_id), 4:http://example.invalid/, 5:depoist/unlimit');
            $table->tinyInteger('open_window')->comment('是否另開視窗');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->comment('狀態; 0:關閉, 1:開啟');
            $table->longText('description')->nullable()->comment('顯示給前端的多語系');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('informations');
    }
};
