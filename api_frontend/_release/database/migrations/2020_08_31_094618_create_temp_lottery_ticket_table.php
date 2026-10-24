<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTempLotteryTicketTable extends Migration
{
    protected $table_name = 'temp_lottery_tickets';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->commit('流水号');
                $table->integer('ticket_number')->commit('抽獎券編號');
                $table->decimal('sort', 16, 16)->commit('排序');
                $table->tinyinteger('status')->default(0)->commit('狀態');
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
