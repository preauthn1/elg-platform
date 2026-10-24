<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberBankAccountTable extends Migration
{
    protected $table_name = 'member_bank_accounts';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('member_bank_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('PK流水號');
            $table->integer('member_id')->comment('member_id');
            $table->integer('bank_id')->comment('關聯的銀行');
            $table->string('account_name' ,50)->comment('帳號用戶名');
            $table->string('account_no', 50)->comment('帳號');
            $table->integer('operator_cs')->comment('修改人(客服後台)');
            $table->timestamp('created_at')->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');      
        });                
        DB::statement("ALTER TABLE `$this->table_name` comment '玩家銀行帳戶'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('member_bank_accounts');
    }
}