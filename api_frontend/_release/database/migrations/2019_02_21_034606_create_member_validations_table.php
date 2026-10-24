<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberValidationsTable extends Migration
{
    protected $table_name = 'member_validations';

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
                $table->integer('user_id')->comment('帳號系統編號(User)');
                $table->string('photo', 40)->comment('檔案名稱');
                $table->tinyInteger('type')->comment('類型 1:護照正面,2:護照背面,3:ID卡正面,4:ID卡背面,5:駕照正面,6:駕照背面,7:銀行帳單,8:信用卡對帳單,9:水電煤氣費帳單,10:手機電話帳單');
                $table->tinyInteger('status')->default(0)->comment('是否通過審核 0: 未通過 1:通過 2:拒絕');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
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
