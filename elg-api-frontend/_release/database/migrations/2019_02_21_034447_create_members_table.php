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
            $table->increments('id')->comment('系統編號');
            $table->integer('parent')->comment('上層代理系統編號(User)');
            $table->string('username', 32)
                ->unique()
                ->comment('姓名');
            $table->string('email', 50)->comment('電子信箱');
            $table->string('password', 128)->comment('密碼');
            $table->string('first_name', 50)->comment('英文名');
            $table->string('last_name', 50)->comment('英文姓');
            $table->date('birthday')->comment('出生年月日');
            $table->string('mobile', 30)->comment('聯繫號碼');
            $table->integer('country_id')->comment('居住國家(FK_Country)');
            $table->string('zip_code', 8)->comment('郵政編碼');
            $table->string('district', 30)->comment('省/地區/郡');
            $table->string('city', 30)->comment('城鎮/城市');
            $table->string('address', 128)->comment('地址');
            $table->tinyInteger('status')
                ->default(0)
                ->comment('帳號狀態 0:未認證,1:正常,2:鎖定');
            $table->dateTime('last_login')
                ->nullable()
                ->comment('最後登入時間');
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
        Schema::dropIfExists('members');
    }
}
