<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RebuildAdvertisementTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('advertisements');
        Schema::dropIfExists('advertisement_paths');
        Schema::dropIfExists('advertisement_types');

        Schema::create('advertisement_paths', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('name')->comment('類別名稱');
            $table->integer('column')->comment('一列幾欄');
            $table->integer('width')->comment('圖片寬度');
            $table->integer('height')->comment('圖片高度');
            $table->tinyInteger('status')
                ->default(0)
                ->comment('狀態 0:停用,1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        Schema::create('advertisements', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('path_id')->comment('版位系統編號');
            $table->integer('language_id')->comment('語系系統編號');
            $table->integer('country_id')
                ->nullable()
                ->default(0)
                ->comment('國家系統編號');
            $table->integer('agent_id')
                ->nullable()
                ->default(0)
                ->comment('代理系統編號');
            $table->string('image')
                ->nullable()
                ->comment('圖片, 如果為空則取遊戲圖片 ');
            $table->string('url')
                ->nullable()
                ->default(0)
                ->comment('連結網址');
            $table->integer('game_id')
                ->nullable()
                ->default(0)
                ->comment('遊戲編號');
            $table->integer('sort')->comment('排序');
            $table->dateTime('start_time')->comment('開始日期');
            $table->dateTime('end_time')->comment('結束日期');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}
