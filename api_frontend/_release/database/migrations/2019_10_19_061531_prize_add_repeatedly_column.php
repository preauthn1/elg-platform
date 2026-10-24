<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PrizeAddRepeatedlyColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->boolean('is_repeatedly')
                ->after('number')
                ->nullable()
                ->default(0)
                ->comment('可否重覆獲獎0:不可,1:可以');
        });

        Schema::table('event_processes', function (Blueprint $table) {
            $table->integer('received')
                ->after('point')
                ->nullable()
                ->default(0)
                ->comment('已兌換過的金額');
        });
    }
}