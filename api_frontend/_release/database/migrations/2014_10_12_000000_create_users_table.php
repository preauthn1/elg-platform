<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{
    protected $table_name = 'users';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('系統編號');
                $table->integer('parent')->comment('上層管理員系統編號(User)');
                $table->string('username', 32)->unique()->comment('姓名');
                $table->string('nickname')->nullable()->comment('暱稱');
                $table->string('email', 50)->comment('電子信箱');
                $table->string('password', 128)->comment('密碼');
                $table->string('mobile', 30)->nullable()->comment('聯繫號碼');
                $table->integer('timezone_id')->nullable()->comment('時區系統編號');
                $table->tinyInteger('status')->default(0)->comment('帳號狀態 0:未認證,1:正常,2:鎖定');
                $table->dateTime('last_login')->nullable()->comment('最後登入時間');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
                $table->integer('admin')->comment('更新者ID');
            });

            $password = [REDACTED]'123456');
            DB::table('users')->insert(array(
                'parent' => 0,
                'username' => 'Admin',
                'email' => 'user@example.invalid',
                'password' => $password,
                'mobile' => '0912345678',
                'status' => 1,
                'admin' => 1
            ));
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}
