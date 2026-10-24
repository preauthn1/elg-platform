<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionLogsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->tinyInteger('type')->comment('類型1:平台轉入遊戲商,2:遊戲商轉入平台');
            $table->decimal('amount', 10, 2)
                ->default(0)
                ->comment('轉點金額');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('平台餘額');
            $table->decimal('vendor_balance', 10, 2)
                ->default(0)
                ->comment('Vendor餘額');
            $table->integer('status')
                ->default(0)
                ->comment('狀態編號');
            $table->string('result')
                ->default(0)
                ->comment('結果');
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
        Schema::dropIfExists('transaction_logs');
    }
}
