<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('admins')) {
            return;
        }
        Schema::create('admins', function (Blueprint $table) {
            $table->increments('id');                 // 主鍵
            $table->string('username')->unique();     // 登入用帳號，最好唯一
            $table->string('password');               // 密碼
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用,1:啟用');
            $table->string('email')->nullable()->unique();      // 可選，email 登入
            $table->timestamp('email_verified_at')->nullable(); // 如果有驗證 email
            $table->rememberToken();                  // remember me token
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('admins');
    }
}
