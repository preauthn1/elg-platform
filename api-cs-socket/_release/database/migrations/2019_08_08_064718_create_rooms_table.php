<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRoomsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('type')->comment('類型1:1對1聊天，2:群組聊天室');
            $table->string('code')->comment('聊天室編號');
            $table->string('name')
                ->nullable()
                ->comment('聊天室名稱');
            $table->string('thumbnail')
                ->nullable()
                ->comment('縮圖');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態0:禁用,1:使用中');
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
        Schema::dropIfExists('rooms');
    }
}
