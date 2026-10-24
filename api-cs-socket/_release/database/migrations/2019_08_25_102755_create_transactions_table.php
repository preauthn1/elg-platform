<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('user_id')->comment('User系統編號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('type')->comment('類型1:轉入，2:轉出');
            $table->integer('amount')->comment('金額');
            $table->text('remark')
                ->nullable()
                ->comment('備註');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態0:失敗,1:成功');
            $table->text('third_party_result')
                ->nullable()
                ->comment('第三方回應結果');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
