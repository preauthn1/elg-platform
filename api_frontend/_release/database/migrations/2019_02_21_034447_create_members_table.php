<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMembersTable extends Migration
{
    protected $table_name = 'members';
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
                $table->integer('parent')->comment('上層代理系統編號(User)');
                $table->string('username', 32)->unique()->comment('姓名');
                $table->string('nickname')->nullable()->comment('暱稱');
                $table->string('email', 50)->nullable()->comment('電子信箱');
                $table->string('password', 128)->comment('密碼');
                $table->string('payment_password', 128)->nullable()->comment('支付密碼');
                $table->decimal('balance', 18, 4)->default(0)->comment('平台餘額');
                $table->string('invitation_code', 20)->nullable()->comment('邀請碼');
                $table->string('image')->nullable()->comment('個人頭像');
                $table->integer('wallet_type_id')->default(0)->comment('當前錢包種類ID');
                $table->text('device_id')->nullable()->comment('推播設備號');
                $table->string('first_name', 50)->nullable()->comment('英文名');
                $table->string('last_name', 50)->nullable()->comment('英文姓');
                $table->date('birthday')->nullable()->comment('出生年月日');
                $table->string('mobile', 30)->nullable()->comment('聯繫號碼');
                $table->integer('timezone_id')->nullable()->comment('時區系統編號');
                $table->integer('country_id')->nullable()->comment('居住國家(FK_Country)');
                $table->string('zip_code', 8)->nullable()->comment('郵政編碼');
                $table->string('district', 30)->nullable()->comment('省/地區/郡');
                $table->string('city', 30)->nullable()->comment('城鎮/城市');
                $table->string('address', 128)->nullable()->comment('地址');
                $table->tinyInteger('status')->default(0)->comment('帳號狀態 0:未認證,1:正常,2:鎖定');
                $table->boolean('event_mode')->default(0)->comment('是否進入活動鎖定模式0:未鎖定，1:鎖定');
                $table->boolean('change_to_single_wallet')->default(0)->comment('代理是否由多錢包轉換為單一錢包0:否，1:是');
                $table->boolean('email_validate')->default(0)->comment('狀態 0:未認證,1:已認證');
                $table->integer('is_validate')->default(0)->comment('是否通過身份驗證');
                $table->integer('mobile_validate')->default(0)->comment('是否通過手機驗證');
                $table->dateTime('last_login')->nullable()->comment('最後登入時間');
                $table->datetime('first_deposit')->nullable()->comment('首次儲值時間');
                $table->string('memo')->nullable()->comment('方便技術記錄用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
                $table->string('vworld_id')->nullable()->comment('vworld1.0的玩家編號(備用而已)');
            });
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
