<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberCreditCardsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('member_credit_cards', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('user_id')->comment('帳號系統編號(User)');
            $table->string('card_number')->comment('信用卡號');
            $table->string('card_holder')->comment('持卡人姓名');
            $table->date('expire_date')->comment('過期日');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
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
        Schema::dropIfExists('member_credit_cards');
    }
}
