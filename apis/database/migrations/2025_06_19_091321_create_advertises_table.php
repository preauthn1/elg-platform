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
        SchemaMonitor::create('advertises', function (Blueprint $table) {
            $table->increments('id');
            $table->tinyInteger('advertisement_path_id')->comment('版面位置id(advertisement_paths.id)');
            $table->integer('agent_id')->default(0)->index()->comment('代理ID(agents.id)');
            $table->dateTime('start_time')->comment('開始時間');
            $table->dateTime('end_time')->comment('結束時間');
            $table->tinyInteger('type')->default(0)->comment('圖片連結類型; 0:無連結, 1:連結至遊戲商, 2:連結至遊戲, 3.連結至遊戲類型, 8:內部連結, 9:外部連結');
            $table->text('content')->nullable()->comment('連結類型內容; 1:1(vendor_id), 2:1(game_id), 3:1(game_type_id), 8:depoist/unlimit, 9:http://example.invalid/');
            $table->boolean('open_window')->default(false)->comment('另開視窗; 0:不另開(h5:該頁面, app:webview), 1:另開(h5:分頁, app:browser)');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
            $table->string('remark', 200)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('advertises');
    }
};
