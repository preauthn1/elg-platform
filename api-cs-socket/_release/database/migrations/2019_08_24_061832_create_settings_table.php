<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSettingsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->comment('使用者系統編號');
            $table->string('name')
                ->nullable()
                ->comment('聊天室名稱');
            $table->string('background_image')
                ->nullable()
                ->comment('聊天室背景圖');
            $table->string('background_color')
                ->nullable()
                ->comment('聊天室背景色');
            $table->string('header_background_color')
                ->nullable()
                ->comment('Header背景色');
            $table->string('header_font_color')
                ->nullable()
                ->comment('Header文字色');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')
                ->default(0)
                ->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('settings');
    }
}
