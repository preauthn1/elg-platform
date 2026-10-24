<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropCreateMemberCodeTable extends Migration
{
	/**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	$this->down();
        Schema::create('member_codes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')
                ->nullable()
                ->comment('會員ID');
            $table->string('agent_code', 10)
                ->comment('會員推廣碼');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('member_codes');
    }
}
