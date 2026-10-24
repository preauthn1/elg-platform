<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Mega888AddPasswordColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mega888_accounts', function (Blueprint $table) {
            $table->string('password')
                ->after('username')
                ->nullable()
                ->comment('密碼');
        });
    }
}