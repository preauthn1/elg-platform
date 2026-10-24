<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberSettingsTable extends Migration
{
    protected $table_name = 'member_settings';

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
                $table->integer('member_id')->comment('帳號系統編號(User)');
                //$table->integer('language_id')->comment('語系系統編號(Language)');
                //$table->integer('timezone_id')->comment('時區系統編號(timezone)');
                $table->integer('activity_limit_time')->comment('最長非活動狀態時間(minute)');
                $table->tinyInteger('push_notify')->comment('接受優惠通知-推送通知');
                $table->tinyInteger('sms_notify')->comment('接受優惠通知-短信提示');
                $table->tinyInteger('email_notify')->comment('接受優惠通知-電子郵件');
                $table->tinyInteger('message_box_notify')->comment('站內優惠通知-信息收件箱');
                $table->tinyInteger('message_popup_notify')->comment('站內優惠通知-優惠彈出信息');
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
