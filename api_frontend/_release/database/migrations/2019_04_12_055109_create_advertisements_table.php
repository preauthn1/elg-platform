<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdvertisementsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('type_id')->comment('類型');
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
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('advertisements');
    }
}
