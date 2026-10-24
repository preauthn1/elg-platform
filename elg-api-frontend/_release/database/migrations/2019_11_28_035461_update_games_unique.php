<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateGamesUnique extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        Schema::table('games', function (Blueprint $table) {
            $table->unique(['vendor_id', 'game_type']);
            $table->index('game_type');
        });
    }
}