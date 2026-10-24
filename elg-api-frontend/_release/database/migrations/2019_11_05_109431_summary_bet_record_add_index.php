<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class SummaryBetRecordAddIndex extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('summary_bet_records', function ($table) {
            $table->index('member_id');
            $table->index('summary_time');
            $table->index('vendor_id');
            $table->index('game_id');
        });
    }
}