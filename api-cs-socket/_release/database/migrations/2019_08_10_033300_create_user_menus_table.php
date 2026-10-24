<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUserMenusTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_menus', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('code')->comment('檔案名稱');
            $table->string('name')->comment('選單功能名稱');
            $table->integer('parent')->comment('選單系統編號，0為root menu');
            $table->string('url')->comment('選單網址');
            $table->string('symbol')
                ->nullable()
                ->comment('選單符號');
            $table->string('action')->comment('功能');
            $table->integer('status')
                ->default(1)
                ->comment('狀態 0:隱藏,1:正常');
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
        Schema::dropIfExists('user_menus');
    }
}
