<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Ace333AddPasswordColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ace333_accounts', function (Blueprint $table) {
            $table->string('password')
                ->after('username')
                ->nullable()
                ->comment('密碼');
        });
    }
}