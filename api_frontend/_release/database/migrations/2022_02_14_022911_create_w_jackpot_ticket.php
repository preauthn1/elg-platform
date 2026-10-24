<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWJackpotTicket extends Migration
{
    protected $table_name = 'w_jackpot_tickets';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水号');
                $table->integer('w_jackpot_id')->comment('w_jackpot.id');
                $table->integer('member_id')->comment('玩家ID');
                $table->string('ticket_number', 20)->default('')->comment('抽獎券號碼');
                $table->integer('reward_item_id')->default(0)->comment('獎項ID');
                $table->string('reward_item_ids', 50)->nullable()->comment('多獎項ID');
                $table->json('info', 50)->comment('info');
                $table->decimal('point', 18, 4)->nullable()->comment('獎勵金額');
                $table->integer('type')->default(0)->comment('中獎結果; 0:未中 , 1:中獎');
                $table->integer('status')->default(0)->comment('提領結果; 0:未領 , 1:已領取');
                $table->dateTime('time')->nullable()->comment('領取時間');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment 'wjackpot抽獎券'");
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
