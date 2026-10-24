<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('role_id')->comment('角色系統編號');
            $table->integer('parent_id')->comment('上層系統編號');
            $table->string('username')->comment('帳號');
            $table->string('password')->comment('密碼');
            $table->string('API_USER')
                ->nullable()
                ->comment('使用API的帳號');
            $table->string('API_KEY')
                ->nullable()
                ->comment('使用API的KEY');
            $table->string('nickname')
                ->nullable()
                ->comment('暱稱');
            $table->string('thumbnail')
                ->nullable()
                ->comment('頭像');
            $table->dateTime('last_login')
                ->nullable()
                ->comment('最後登入時間');
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

        // 建立平台商帳號
        $password = [REDACTED]'123456');
        DB::table('users')->insert(array(
            'role_id' => '1',
            'parent_id' => '0',
            'username' => 'administrator',
            'password' => $password,
            'API_USER' => $password,
            'API_KEY' => $password,
            'nickname' => 'Super user',
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
        Schema::dropIfExists('users');
    }
}
