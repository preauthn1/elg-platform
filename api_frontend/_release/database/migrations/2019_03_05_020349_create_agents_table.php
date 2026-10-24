<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgentsTable extends Migration
{
    protected $table_name = 'agents';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('host_id')->default(0)->comment('主帳號的系統編號(不等於0就是子帳號)');
            $table->integer('parent')->comment('上層代理系統編號(Agent)');
            $table->tinyInteger('role_id')->default(0)->comment('roles.id');
            $table->text('hierarchy')->nullable()->comment('階層樹(json型態)');
            $table->string('hierarchy_path' , 191)->nullable()->index()->comment('新版hierarchy結構，存成路徑式的數值');
            $table->string('code', 10)->comment('邀請碼');
            $table->string('username', 32)->unique()->comment('姓名');
            $table->string('nickname')->nullable()->comment('暱稱');
            $table->decimal('balance', 18, 4)->default(0)->comment('餘額');
            $table->string('email', 50)->nullable()->comment('電子信箱');
            $table->string('password', 128)->comment('密碼');
            $table->string('im_certify')->nullable()->comment('vworld影子帳號密碼');
            $table->string('mobile', 30)->nullable()->comment('聯繫號碼');
            $table->decimal('percentage', 10, 2)->nullable()->comment('百分比');
            $table->boolean('cs_sync_flag')->default(0)->comment('需同步資料的帳號flag為1');
            $table->string('CS_API_USER')->nullable()->comment('與客服系統串接的帳號');
            $table->string('CS_API_KEY')->nullable()->comment('與客服系統串接的KEY');
            $table->integer('timezone_id')->nullable()->comment('時區系統編號');
            $table->integer('country_id')->nullable()->comment('居住國家(FK_Country)');
            $table->string('zip_code', 30)->nullable()->comment('郵政編碼');
            $table->string('district', 30)->nullable()->comment('省/地區/郡');
            $table->string('city', 30)->nullable()->comment('城鎮/城市');
            $table->string('address', 128)->nullable()->comment('地址');
            $table->boolean('frontend_register')->nullable()->default(0)->comment('前端是否可以註冊(0:不可註冊,1:可註冊)');
            $table->boolean('email_validate')->default(0)->comment('電子郵件是否需要認證0:不需要,1:需要');
            $table->boolean('mobile_validate')->default(0)->comment('電子郵件是否需要認證0:不需要,1:需要');
            $table->boolean('need_username')->default(0)->comment('忘記密碼是否需要填寫帳號0:不需要,1:需要');
            $table->boolean('has_chat')->default(0)->comment('是否開放聊天室');
            $table->boolean('has_pc')->default(0)->comment('是否有PC版');
            $table->boolean('has_mobile')->default(0)->comment('是否有手機版');
            $table->boolean('has_online_payment')->default(0)->comment('是否有線上儲值');
            $table->boolean('has_app')->default(0)->comment('是否有APP');
            $table->integer('has_apk')->default(0)->nullable(false)->comment('APP顯示開關(android)');
            $table->integer('has_ipa')->default(0)->nullable(false)->comment('APP顯示開關(ios)');
            $table->integer('has_2fa')->default(0)->comment('2FA開關');
            $table->boolean('transaction_all')->default(0)->comment('進入遊戲時是否將所有錢轉入，0:否1:是');
            $table->boolean('agent_backend_transaction')->default(0)->comment('是否可以在代理後台開洗分，0:否1:是');
            $table->tinyInteger('agent_mode')->default('0')->comment('代理模式類型。1:無限代');
            $table->tinyInteger('status')->default(1)->comment('帳號狀態 0:鎖定,1:正常');
            $table->integer('customized_setting_group_id')->default(0)->comment('個性化設置_組別ID (agent_groups 資料表)');
            $table->integer('cash_flow_group_id')->nullable(false)->default(0)->comment('群組編號');
            $table->tinyInteger('sms_gateway_group_id')->default(0)->comment('簡訊群組ID');
            $table->tinyInteger('chat_group_id')->default(0)->comment('聊天群組ID');
            $table->integer('vendor_customize_group_id')->nullable()->defaut(0)->comment('遊戲商個性化設置');
            $table->integer('organization_id')->default(1)->comment('所屬組織');
            $table->dateTime('last_login')->nullable()->comment('最後登入時間');
            $table->string('memo')->nullable()->comment('方便技術記錄用');
            $table->timestamp('last_reset_password')->nullable()->comment('最後更新密碼時間');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
            $table->integer('admin')->comment('更新者ID');
            $table->integer('created_by')->default(0)->comment('創建代理的操作人');
        });

        $password = [REDACTED]'123456');
        DB::table($this->table_name)->insert(array(
            'id' => 1,
            'parent' => 0,
            'code' => '',
            'username' => 'admin',
            'nickname' => 'Admin',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,1,-2]',
            'hierarchy_path' => '/1',
            'status' => 1,
            'admin' => 1
        ));

        $password = [REDACTED]'terpro123!@#');
        DB::table($this->table_name)->insert(array(
            'id' => 2,
            'parent' => 1,
            'code' => '',
            'username' => 'rd',
            'nickname' => 'Rd',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,2,1,-2]',
            'hierarchy_path' => '/1/2',
            'status' => 1,
            'admin' => 1
        ));
        DB::table($this->table_name)->insert(array(
            'id' => 51,
            'parent' => 1,
            'code' => '',
            'username' => 'system',
            'nickname' => 'System',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,51,1,-2]',
            'hierarchy_path' => '/1/51',
            'status' => 1,
            'admin' => 1
        ));
        DB::table($this->table_name)->insert(array(
            'id' => 52,
            'parent' => 1,
            'code' => '',
            'username' => 'auto',
            'nickname' => 'Auto',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,52,1,-2]',
            'hierarchy_path' => '/1/52',
            'status' => 1,
            'admin' => 1
        ));
        DB::table($this->table_name)->insert(array(
            'id' => 53,
            'parent' => 1,
            'code' => '',
            'username' => 'unlimit',
            'nickname' => 'Unlimit',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,53,1,-2]',
            'hierarchy_path' => '/1/53',
            'status' => 1,
            'admin' => 1
        ));
        DB::table($this->table_name)->insert(array(
            'id' => 100,
            'parent' => 1,
            'code' => '',
            'username' => 'dev',
            'nickname' => 'Developer',
            'email' => 'user@example.invalid',
            'password' => $password,
            'hierarchy' => '[-1,100,1,-2]',
            'hierarchy_path' => '/1/100',
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
        Schema::dropIfExists($this->table_name);
    }
}
