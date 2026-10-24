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
        SchemaMonitor::create('agents', function (Blueprint $table) {
            $table->comment('代理; ID:3~100預設給系統使用, 如System, Auto, unlimit, ....');
            $table->increments('id')->comment('系統編號');
            $table->integer('host_id')->default(0)->index()->comment('主帳號的系統編號(不等於0就是子帳號)');
            $table->integer('parent')->index()->comment('上層代理系統編號(Agent)');
            $table->tinyInteger('role_id')->default(0)->index()->comment('roles.id');
            $table->text('hierarchy')->nullable()->comment('階層樹(json型態)');
            $table->string('hierarchy_path', 191)->nullable()->index()->comment('新版hierarchy結構，存成路徑式的數值');
            $table->string('code', 10)->comment('邀請碼');
            $table->string('username', 32)->unique()->comment('姓名');
            $table->string('nickname', 191)->nullable()->comment('暱稱');
            $table->decimal('balance', 18, 4)->default(0)->comment('餘額');
            $table->tinyInteger('is_balance_unlimited')->default(0)->comment('錢包是否為無限額');
            $table->tinyInteger('withdraw_confirm')->default(1)->comment('洗分是否需要同意，0:不需要、1:需要');
            $table->string('email', 50)->comment('電子信箱');
            $table->string('password', 128)->comment('密碼');
            $table->string('payment_password')->nullable()->comment('代理支付密碼');
            $table->boolean('cs_sync_flag')->default(false)->comment('需同步資料的帳號flag為1');
            $table->string('im_certify', 191)->nullable()->comment('vworld影子帳號密碼');
            $table->string('mobile', 30)->nullable()->comment('聯繫號碼');
            $table->decimal('percentage', 10)->nullable()->comment('百分比');
            $table->string('CS_API_USER', 191)->nullable()->comment('與客服系統串接的帳號');
            $table->string('CS_API_KEY', 191)->nullable()->comment('與客服系統串接的KEY');
            $table->integer('timezone_id')->nullable()->comment('時區系統編號');
            $table->integer('country_id')->nullable()->comment('居住國家(FK_Country)');
            $table->string('zip_code', 30)->nullable()->comment('郵政編碼');
            $table->string('district', 30)->nullable()->comment('省/地區/郡');
            $table->string('city', 30)->nullable()->comment('城鎮/城市');
            $table->string('address', 128)->nullable()->comment('地址');
            $table->boolean('frontend_register')->nullable()->default(false)->comment('前端是否可以註冊(0:不可註冊,1:可註冊)');
            $table->boolean('has_chat')->default(false)->comment('是否開放聊天室');
            $table->boolean('has_pc')->default(false)->comment('是否有PC版');
            $table->boolean('has_mobile')->default(false)->comment('是否有手機版');
            $table->boolean('has_online_payment')->default(false)->comment('是否有線上儲值');
            $table->boolean('has_app')->default(false)->comment('是否有APP');
            $table->integer('has_apk')->default(0)->comment('APP顯示開關(android)');
            $table->integer('has_ipa')->default(0)->comment('APP顯示開關(ios)');
            $table->tinyInteger('has_2fa')->default(0)->index()->comment('Google驗證器開關, 0:關閉, 1:開啟');
            $table->boolean('transaction_all')->default(false)->comment('進入遊戲時是否將所有錢轉入，0:否1:是');
            $table->boolean('agent_backend_transaction')->default(false)->comment('是否可以在代理後台開洗分，0:否1:是');
            $table->boolean('email_validate')->default(false)->comment('電子郵件是否需要認證0:不需要,1:需要');
            $table->boolean('mobile_validate')->default(false)->comment('電子郵件是否需要認證0:不需要,1:需要');
            $table->boolean('mobile_bind')->default(false)->comment('電話號碼是否綁定');
            $table->boolean('need_username')->default(false)->comment('忘記密碼是否需要填寫帳號0:不需要,1:需要');
            $table->tinyInteger('status')->default(1)->index()->comment('帳號狀態 0:鎖定,1:正常');
            $table->boolean('sys_lock')->default(false)->comment('系統鎖定; 1:鎖定, 0:非解鎖');
            $table->dateTime('last_login')->nullable()->comment('最後登入時間');
            $table->bigInteger('last_login_ip')->nullable()->default(0)->comment('最後登入IP');
            $table->string('proportion', 20)->default('0')->comment('代理占成');
            $table->string('memo', 191)->nullable()->comment('方便技術記錄用');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->comment('更新者ID');
            $table->integer('created_by')->default(0)->comment('創建代理的操作人');
            $table->tinyInteger('agent_mode')->default(0)->comment('代理模式類型。1:全民代, 2:三層代理(計算含代理),3:三層代理(計算限定會員)');
            $table->integer('customized_setting_group_id')->nullable()->default(1)->index()->comment('個性化設置_組別ID (agent_groups 資料表)');
            $table->integer('cash_flow_group_id')->nullable()->default(3)->index()->comment('群組編號');
            $table->integer('sms_gateway_group_id')->default(0)->index()->comment('簡訊群組ID');
            $table->integer('chat_group_id')->default(0)->index()->comment('聊天群組ID');
            $table->integer('vendor_customize_group_id')->default(0)->index()->comment('遊戲商個性化設置');
            $table->integer('organization_id')->default(1)->index()->comment('所屬組織');
            $table->timestamp('last_reset_password')->nullable()->comment('最後更新密碼時間');

            $table->index(['id', 'hierarchy_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agents');
    }
};
