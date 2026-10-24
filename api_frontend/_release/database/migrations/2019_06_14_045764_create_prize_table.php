<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePrizeTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('prize_types', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('name')->comment('類型名稱');
            $table->boolean('status')->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        Schema::create('prizes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('event_id')->comment('活動編號');
            $table->integer('type_id')->comment('獎勵遊戲類型編號');
            $table->string('name')->comment('獎勵活動名稱');
            $table->string('background')->comment('背景圖');
            $table->integer('number')->comment('獎勵遊戲獲得次數');
            $table->datetime('expire_date')->comment('獎勵遊戲次數到期日');
            $table->boolean('status')->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        Schema::create('prize_contents', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('prize_id')->comment('獎勵系統編號');
            $table->string('name')->comment('獎項名稱');
            $table->integer('number')->comment('獎項數量');
            $table->integer('percentage')->comment('中獎機率');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        Schema::create('prize_members', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('玩家系統編號');
            $table->string('prize_id')->comment('獎勵遊戲系統編號');
            $table->string('content_id')
                ->nullable()
                ->comment('獲得獎項系統編號');
            $table->boolean('status')->comment('狀態0:未使用,1:已使用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}
