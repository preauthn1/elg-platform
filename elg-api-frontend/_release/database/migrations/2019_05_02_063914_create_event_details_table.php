<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEventDetailsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('event_details', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('action_id')->comment('行為編號');
            $table->integer('order_type_id')
                ->nullable()
                ->comment('訂單類型編號');
            $table->integer('vendor_id')
                ->nullable()
                ->comment('遊戲商系統編號');
            $table->integer('game_id')
                ->nullable()
                ->comment('遊戲系統編號');
            $table->decimal('rate', 10, 2)->comment('匯率');
            $table->integer('point')->comment('達標點數');
            $table->dateTime('start_time')->comment('開始時間');
            $table->dateTime('end_time')->comment('結束時間');
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
        Schema::dropIfExists('event_details');
    }
}
