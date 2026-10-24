<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameMtBetRecordsColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mt_bet_records', function (Blueprint $table) {
            $table->renameColumn('`betAmount(CNY)`', 'CNY_betAmount');
            $table->renameColumn('`winAmount(CNY)`', 'CNY_winAmount');
        });
    }

}
