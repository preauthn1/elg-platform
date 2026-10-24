<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMtBetRecordsCurrency extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mt_bet_records', function (Blueprint $table) {
            $table->decimal('betAmount(CNY)', 13, 7)
                ->after('betAmount')
                ->nullable(false)
                ->comment('下注金額(CNY)');
            $table->decimal('winAmount(CNY)', 13, 7)
                ->after('winAmount')
                ->nullable(false)
                ->comment('贏得金額(CNY)');
        });
    }
}
