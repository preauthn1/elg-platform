<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PrizeAddDrawDateColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->dateTime('draw_date')
                ->after('expire_date')
                ->nullable()
                ->comment('摸彩卷的開獎日期');
        });
    }
}