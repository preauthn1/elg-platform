<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('members', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('parent')->index()->comment('上層代理系統編號(User)');
            $table->string('username', 32)->unique()->comment('姓名');
            $table->string('username_bak', 45)->nullable();
            $table->string('email', 60)->nullable()->index()->comment('電子信箱');
            $table->string('nickname', 191)->nullable()->index()->comment('暱稱');
            $table->string('password', 128)->comment('密碼');
            $table->string('payment_password', 128)->nullable()->comment('支付密碼');
            $table->decimal('balance', 18, 4)->default(0)->comment('平台餘額');
            $table->decimal('balance_bak', 18, 4)->default(0);
            $table->string('invitation_code', 20)->nullable()->index()->comment('邀請碼');
            $table->text('device_id')->nullable()->comment('推播設備號');
            $table->string('device_type', 20)->nullable()->comment('設備種類');
            $table->string('image', 191)->nullable()->comment('個人頭像');
            $table->string('first_name', 100)->nullable()->index()->comment('英文名');
            $table->string('last_name', 100)->nullable()->comment('英文姓');
            $table->date('birthday')->nullable()->comment('出生年月日');
            $table->string('mobile', 32)->nullable()->index()->comment('聯繫號碼');
            $table->integer('timezone_id')->nullable()->comment('時區系統編號');
            $table->integer('country_id')->nullable()->comment('居住國家(FK_Country)');
            $table->string('zip_code', 10)->nullable()->comment('郵政編碼');
            $table->string('district', 32)->nullable()->comment('省/地區/郡');
            $table->string('city', 30)->nullable()->comment('城鎮/城市');
            $table->string('address', 191)->nullable()->comment('地址');
            $table->integer('register_country_id')->nullable()->comment('註冊國家');
            $table->bigInteger('register_ip')->nullable()->comment('註冊IP');
            $table->text('register_ip_detail')->nullable();
            $table->string('register_domain', 100)->nullable()->comment('註冊網域');
            $table->tinyInteger('type')->default(0)->comment('會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員');
            $table->tinyInteger('previous_day_type')->default(0)->comment('前一天會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員');
            $table->tinyInteger('status')->default(0)->index()->comment('帳號狀態 0:未認證,1:正常,2:鎖定(密碼輸入錯六次, 系統自動變更)');
            $table->boolean('sys_lock')->default(false)->comment('系統鎖定; 1:鎖定, 0:非解鎖');
            $table->boolean('event_mode')->default(false)->comment('是否進入活動鎖定模式0:未鎖定，1:鎖定');
            $table->boolean('change_to_single_wallet')->default(false)->comment('代理是否由多錢包轉換為單一錢包0:否，1:是');
            $table->integer('is_validate')->default(0)->index()->comment('是否通過身份驗證');
            $table->integer('mobile_validate')->default(0)->index()->comment('是否通過手機驗證');
            $table->boolean('email_validate')->default(false)->index()->comment('狀態 0:未認證,1:已認證');
            $table->dateTime('last_login')->nullable()->index()->comment('最後登入時間');
            $table->bigInteger('last_login_ip')->nullable()->default(0)->comment('最後登入IP');
            $table->dateTime('first_deposit')->nullable()->comment('首次儲值時間');
            $table->integer('total_login_fail_count')->nullable()->default(0)->comment('總登入錯誤次數');
            $table->integer('login_fail_count')->nullable()->default(0)->comment('登入錯誤次數');
            $table->integer('forget_pwd_count')->nullable()->default(0)->comment('忘記密碼次數');
            $table->string('memo', 191)->nullable()->comment('方便技術記錄用');
            $table->tinyInteger('has_2fa')->default(0)->comment('Google驗證器開關, 0:關閉, 1:開啟');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
            $table->string('vworld_id', 191)->nullable()->comment('vworld1.0的玩家編號(備用而已)');
            $table->boolean('realname_validate')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('members');
    }
};
