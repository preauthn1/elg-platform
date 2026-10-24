<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeAgBetColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ag_bet_records', function ($table) {
            $table->decimal('net_amount', 11, 3)->change();
            $table->decimal('bet_amount', 11, 3)->change();
            $table->decimal('valid_bet_amount', 11, 3)->change();
            $table->dateTime('utc_bet_time')
                ->after('bet_time')
                ->nullable()
                ->comment('時區+0的投注時間(AG原始的時區為-4)');
        });
    }
}