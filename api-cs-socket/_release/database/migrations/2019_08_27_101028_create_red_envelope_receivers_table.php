<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRedEnvelopeReceiversTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('red_envelope_receivers', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('red_envelope_id')->comment('紅包系統編號');
            $table->integer('member_id')->comment('接收者會員系統編號');
            $table->integer('amount')->comment('金額');
            $table->text('remark')
                ->nullable()
                ->comment('備註');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態0:未領取完畢,1:已領取');
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
        Schema::dropIfExists('red_envelope_receivers');
    }
}
