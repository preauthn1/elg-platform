<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLanguagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('name', 32)->comment('語言名稱');
            $table->string('code', 10)->comment('語言代碼');
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

        DB::table('languages')->insert(array(
            'code' => 'en',
            'name' => '英文',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('languages')->insert(array(
            'code' => 'zh-TW',
            'name' => '繁中',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('languages')->insert(array(
            'code' => 'zh-CN',
            'name' => '簡中',
            'status' => 1,
            'admin' => 1
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('languages');
    }
}
