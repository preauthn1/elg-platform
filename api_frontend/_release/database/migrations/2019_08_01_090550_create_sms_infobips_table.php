<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsInfobipsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_infobips', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('玩家帳號');
            $table->string('mobile')->comment('電話號碼');
            $table->string('text')->comment('訊息內容');
            $table->integer('smsCount')
                ->nullable()
                ->comment('訊息分成幾則');
            $table->string('message_id')
                ->nullable()
                ->comment('訊息編號');
            $table->dateTime('send_at')
                ->nullable()
                ->comment('發送時間');
            $table->dateTime('done_at')
                ->nullable()
                ->comment('完成時間');
            $table->decimal('price', 10, 2)
                ->nullable()
                ->comment('費用');
            $table->string('currency', 10)
                ->nullable()
                ->comment('幣種');
            $table->integer('status_group_id')
                ->nullable()
                ->comment('群組識別碼');
            $table->string('status_group_name')
                ->nullable()
                ->comment('群組名稱');
            $table->integer('status_id')
                ->nullable()
                ->comment('識別碼');
            $table->string('status_name')
                ->nullable()
                ->comment('名稱');
            $table->string('status_description')
                ->nullable()
                ->comment('說明');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')
                ->default(0)
                ->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sms_infobips');
    }
}
