<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMembersTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('members', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('user_id')->comment('營運商系統編號');
            $table->string('third_party_id')->comment('第三方系統編號，0為客服編號');
            $table->string('username')
                ->nullable()
                ->comment('好友ID');
            $table->string('nickname')
                ->nullable()
                ->comment('暱稱');
            $table->string('thumbnail')
                ->nullable()
                ->comment('頭像');
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
        Schema::dropIfExists('members');
    }
}
